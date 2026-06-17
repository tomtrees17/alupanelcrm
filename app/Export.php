<?php
declare(strict_types=1);

/**
 * Dependency-free spreadsheet export.
 * Produces a real .xlsx via ZipArchive (OOXML), or falls back to UTF-8 CSV
 * if the zip extension is unavailable. Streams the file and exits.
 *
 * $rows: array of arrays. Pass numbers as int/float (become numeric cells),
 *        everything else is written as text.
 */
function send_spreadsheet(string $baseName, string $sheetTitle, array $headers, array $rows): void
{
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($tmp !== false && $zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            xlsx_build($zip, $sheetTitle, $headers, $rows);
            $zip->close();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: max-age=0');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
    }
    send_csv($baseName, $headers, $rows);
}

function send_csv(string $baseName, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel detects encoding
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

function xlsx_build(ZipArchive $zip, string $sheetTitle, array $headers, array $rows): void
{
    $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $sheetTitle);
    $title = mb_substr($title, 0, 31) ?: 'Sheet1';

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');

    $all = array_merge([$headers], $rows);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($all as $ri => $row) {
        $r = $ri + 1;
        $sheet .= '<row r="' . $r . '">';
        $ci = 0;
        foreach ($row as $val) {
            $sheet .= xlsx_cell($ci++, $r, $val);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
}

function xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

function xlsx_cell(int $col, int $row, $val): string
{
    $ref = xlsx_col($col) . $row;
    if (is_int($val) || is_float($val)) {
        return '<c r="' . $ref . '"><v>' . $val . '</v></c>';
    }
    $text = htmlspecialchars((string) $val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
}
