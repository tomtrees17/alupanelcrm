<?php
declare(strict_types=1);

/**
 * Minimal, dependency-free .docx (WordprocessingML) writer — ZipArchive only,
 * like Export.php does for Excel.
 *
 * Why not the MSO-HTML ".doc" (Word.php): Word and WPS lay HTML tables out
 * loosely (percentage widths, nested tables, padding drift) and cannot embed
 * images in it, so the invoice came out misaligned and without its logo. A
 * real .docx has fixed column widths in twips and carries the logo inside.
 *
 * Units: twips (1/20 pt; 1 mm ≈ 56.7) for layout, half-points for font size,
 * EMU (1 mm = 36000) for images.
 */
final class Docx
{
    public const A4_W = 11906;
    public const A4_H = 16838;

    /** @var array<int,array{name:string,data:string}> */
    private array $media = [];

    /** Font written on every run (see run()). */
    public static string $defaultFont = 'Arial';

    public function __construct(
        private int $marginTop = 794,     // 14 mm
        private int $marginSide = 680,    // 12 mm
        private string $font = 'Arial',
        private int $size = 20,           // half-points: 10 pt
    ) {
        self::$defaultFont = $font;
    }

    /** Usable text width in twips. */
    public function width(): int
    {
        return self::A4_W - 2 * $this->marginSide;
    }

    public static function esc(string $s): string
    {
        // Strip characters XML 1.0 forbids (control chars pasted from spreadsheets).
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * A run of text. Options: b, i, size (pt), color (hex), font, caps.
     * Newlines become line breaks.
     */
    public static function run(string $text, array $o = []): string
    {
        // Font on every run: some renderers (macOS Quick Look, older WPS) ignore
        // the document defaults. Elements in CT_RPr schema order.
        $f = self::esc((string) ($o['font'] ?? self::$defaultFont));
        $rpr = "<w:rFonts w:ascii=\"{$f}\" w:hAnsi=\"{$f}\" w:eastAsia=\"{$f}\" w:cs=\"{$f}\"/>";
        if (!empty($o['b'])) {
            $rpr .= '<w:b/>';
        }
        if (!empty($o['i'])) {
            $rpr .= '<w:i/>';
        }
        if (!empty($o['color'])) {
            $rpr .= '<w:color w:val="' . self::esc((string) $o['color']) . '"/>';
        }
        if (!empty($o['spacing'])) {
            $rpr .= '<w:spacing w:val="' . (int) $o['spacing'] . '"/>';
        }
        if (!empty($o['size'])) {
            $hp = (int) round((float) $o['size'] * 2);
            $rpr .= "<w:sz w:val=\"{$hp}\"/><w:szCs w:val=\"{$hp}\"/>";
        }
        $parts = explode("\n", str_replace("\r", '', $text));
        $xml = '';
        foreach ($parts as $k => $part) {
            if ($k > 0) {
                $xml .= '<w:br/>';
            }
            $xml .= '<w:t xml:space="preserve">' . self::esc($part) . '</w:t>';
        }
        return "<w:r><w:rPr>{$rpr}</w:rPr>{$xml}</w:r>";
    }

    /**
     * A paragraph. $content is a string (one run with $o) or pre-built run XML
     * (pass $o['raw'] = true). Options: align (left|center|right), after/before
     * (spacing in pt), plus run options when $content is a string.
     */
    public static function p(string $content = '', array $o = []): string
    {
        // CT_PPr schema order: pBdr, spacing, jc, rPr.
        $ppr = '';
        if (!empty($o['border'])) {   // boxed paragraph (e.g. the VOID stamp)
            $c = self::esc((string) ($o['border_color'] ?? '000000'));
            $ppr .= '<w:pBdr>';
            foreach (['top', 'left', 'bottom', 'right'] as $side) {
                $ppr .= "<w:{$side} w:val=\"single\" w:sz=\"16\" w:space=\"4\" w:color=\"{$c}\"/>";
            }
            $ppr .= '</w:pBdr>';
        }
        $after = (int) round(((float) ($o['after'] ?? 0)) * 20);
        $before = (int) round(((float) ($o['before'] ?? 0)) * 20);
        $ppr .= "<w:spacing w:before=\"{$before}\" w:after=\"{$after}\" w:line=\"240\" w:lineRule=\"auto\"/>";
        if (!empty($o['align'])) {
            $map = ['left' => 'left', 'center' => 'center', 'right' => 'right'];
            $ppr .= '<w:jc w:val="' . ($map[$o['align']] ?? 'left') . '"/>';
        }
        if (!empty($o['size'])) {   // the paragraph mark too, so an empty spacer is small
            $hp = (int) round((float) $o['size'] * 2);
            $ppr .= "<w:rPr><w:sz w:val=\"{$hp}\"/><w:szCs w:val=\"{$hp}\"/></w:rPr>";
        }
        $runs = !empty($o['raw']) ? $content : ($content === '' ? '' : self::run($content, $o));
        return "<w:p><w:pPr>{$ppr}</w:pPr>{$runs}</w:p>";
    }

    /**
     * A table with fixed column widths (twips).
     * $rows: list of rows; each row a list of cells; a cell is either a string
     * (plain paragraph) or ['xml' => paragraphs/tables, 'align', 'b', 'span',
     * 'borders' => bool, 'size', 'valign' => top|center|bottom].
     * Options: borders (bool, default false), pad (twips, default 70), size (pt).
     */
    public static function table(array $widths, array $rows, array $o = []): string
    {
        $total = array_sum($widths);
        $pad = (int) ($o['pad'] ?? 70);
        $tblBorders = '';
        if (!empty($o['borders'])) {
            $tblBorders = '<w:tblBorders>';
            foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
                $tblBorders .= "<w:{$side} w:val=\"single\" w:sz=\"6\" w:space=\"0\" w:color=\"000000\"/>";
            }
            $tblBorders .= '</w:tblBorders>';
        }
        // CT_TblPr schema order: tblW, tblBorders, tblLayout, tblCellMar.
        $xml = '<w:tbl><w:tblPr>'
            . "<w:tblW w:w=\"{$total}\" w:type=\"dxa\"/>"
            . $tblBorders
            . '<w:tblLayout w:type="fixed"/>'
            . "<w:tblCellMar><w:top w:w=\"40\" w:type=\"dxa\"/><w:left w:w=\"{$pad}\" w:type=\"dxa\"/><w:bottom w:w=\"40\" w:type=\"dxa\"/><w:right w:w=\"{$pad}\" w:type=\"dxa\"/></w:tblCellMar>"
            . '</w:tblPr><w:tblGrid>';
        foreach ($widths as $w) {
            $xml .= '<w:gridCol w:w="' . (int) $w . '"/>';
        }
        $xml .= '</w:tblGrid>';
        foreach ($rows as $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            $col = 0;
            foreach ($row as $cell) {
                $c = is_array($cell) ? $cell : ['text' => (string) $cell];
                $span = max(1, (int) ($c['span'] ?? 1));
                $w = array_sum(array_slice($widths, $col, $span));
                $col += $span;
                $tcPr = "<w:tcW w:w=\"{$w}\" w:type=\"dxa\"/>";
                if ($span > 1) {
                    $tcPr .= "<w:gridSpan w:val=\"{$span}\"/>";
                }
                if (isset($c['borders'])) {
                    $tcPr .= '<w:tcBorders>';
                    foreach (['top', 'left', 'bottom', 'right'] as $side) {
                        $tcPr .= $c['borders']
                            ? "<w:{$side} w:val=\"single\" w:sz=\"6\" w:space=\"0\" w:color=\"000000\"/>"
                            : "<w:{$side} w:val=\"nil\"/>";
                    }
                    $tcPr .= '</w:tcBorders>';
                } elseif (!empty($c['top_rule'])) {
                    $tcPr .= '<w:tcBorders><w:top w:val="single" w:sz="8" w:space="0" w:color="000000"/></w:tcBorders>';
                }
                $tcPr .= '<w:vAlign w:val="' . ($c['valign'] ?? 'top') . '"/>';
                if (isset($c['xml'])) {
                    $inner = (string) $c['xml'];
                    // A cell must end with a paragraph (Word rejects a cell ending in a table).
                    if (!preg_match('#</w:p>\s*$#', $inner)) {
                        $inner .= self::p('', ['size' => 2]);
                    }
                } else {
                    $inner = self::p((string) ($c['text'] ?? ''), [
                        'align' => $c['align'] ?? null, 'b' => $c['b'] ?? false, 'i' => $c['i'] ?? false,
                        'size' => $c['size'] ?? ($o['size'] ?? null), 'color' => $c['color'] ?? null,
                    ]);
                }
                $xml .= "<w:tc><w:tcPr>{$tcPr}</w:tcPr>{$inner}</w:tc>";
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    /**
     * Inline image paragraph content (a run). Registers the file as media.
     * Fits inside $maxW × $maxH millimetres, keeping the aspect ratio.
     */
    public function image(string $path, float $maxWmm, float $maxHmm): string
    {
        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return '';
        }
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
        $n = count($this->media) + 1;
        $this->media[] = ['name' => "image{$n}.{$ext}", 'data' => (string) file_get_contents($path)];
        $scale = min($maxWmm / max(1, $info[0]), $maxHmm / max(1, $info[1]));
        $cx = (int) round($info[0] * $scale * 36000);
        $cy = (int) round($info[1] * $scale * 36000);
        $rid = 'rIdImg' . $n;
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . "<wp:extent cx=\"{$cx}\" cy=\"{$cy}\"/><wp:docPr id=\"{$n}\" name=\"Picture {$n}\"/>"
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . "<pic:nvPicPr><pic:cNvPr id=\"{$n}\" name=\"image{$n}.{$ext}\"/><pic:cNvPicPr/></pic:nvPicPr>"
            . "<pic:blipFill><a:blip r:embed=\"{$rid}\"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>"
            . "<pic:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$cx}\" cy=\"{$cy}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></pic:spPr>"
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    /** The complete .docx as a binary string. */
    public function build(string $bodyXml): string
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "<w:document xmlns:w=\"{$w}\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\""
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<w:body>' . $bodyXml
            . '<w:sectPr><w:pgSz w:w="' . self::A4_W . '" w:h="' . self::A4_H . '"/>'
            . "<w:pgMar w:top=\"{$this->marginTop}\" w:right=\"{$this->marginSide}\" w:bottom=\"{$this->marginTop}\" w:left=\"{$this->marginSide}\" w:header=\"400\" w:footer=\"400\" w:gutter=\"0\"/>"
            . '</w:sectPr></w:body></w:document>';

        $f = self::esc($this->font);
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "<w:styles xmlns:w=\"{$w}\"><w:docDefaults>"
            . "<w:rPrDefault><w:rPr><w:rFonts w:ascii=\"{$f}\" w:eastAsia=\"{$f}\" w:hAnsi=\"{$f}\" w:cs=\"{$f}\"/>"
            . "<w:sz w:val=\"{$this->size}\"/><w:szCs w:val=\"{$this->size}\"/><w:lang w:val=\"id-ID\"/></w:rPr></w:rPrDefault>"
            . '<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($this->media as $i => $m) {
            $rels .= '<Relationship Id="rIdImg' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $m['name'] . '"/>';
        }
        $rels .= '</Relationships>';

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';

        $root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot create docx');
        }
        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('_rels/.rels', $root);
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->addFromString('word/_rels/document.xml.rels', $rels);
        foreach ($this->media as $m) {
            $zip->addFromString('word/media/' . $m['name'], $m['data']);
        }
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    public function download(string $basename, string $bodyXml): void
    {
        $bin = $this->build($bodyXml);
        $safe = trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '-', $basename), '-') ?: 'document';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $safe . '.docx"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: max-age=0');
        echo $bin;
        exit;
    }
}
