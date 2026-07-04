<?php
declare(strict_types=1);

/**
 * Dependency-free "export to Word" — streams MSO-HTML as a .doc attachment.
 * MS Word / WPS open it fully editable (layout kept via plain tables).
 */
final class Word
{
    public static function download(string $basename, string $bodyHtml): void
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '-', $basename), '-') ?: 'document';
        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">'
            . '<head><meta charset="UTF-8">'
            . '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->'
            . '<style>'
            . '@page{size:210mm 297mm;margin:14mm 12mm;}'
            . 'body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#000;}'
            . 'table{border-collapse:collapse;}td,th{vertical-align:top;}'
            . '</style></head><body>' . $bodyHtml . '</body></html>';

        header('Content-Type: application/msword; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safe . '.doc"');
        header('Cache-Control: max-age=0');
        echo "\xEF\xBB\xBF" . $html;   // BOM so Word detects UTF-8
        exit;
    }
}
