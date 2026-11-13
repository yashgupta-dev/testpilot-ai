<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Support;

use RuntimeException;
use ZipArchive;

/**
 * Writes sheets as .xlsx (Excel, Google Sheets, LibreOffice, Numbers) or .csv without extra dependencies.
 *
 * A sheet is ['columns' => [[title, width], …], 'rows' => [[cell, …], …]]. Cells are strings, numbers, booleans or null.
 * Text is written as text (never as a formula); CSV cells that a spreadsheet would run as a formula are prefixed with '.
 */
final class SpreadsheetWriter
{
    private const MAX_CELL = 32_767; // Excel's limit per cell

    /** @param array<string, array{columns: list<array{0: string, 1: int}>, rows: list<list<mixed>>}> $sheets */
    public static function xlsx(array $sheets): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is needed for .xlsx export; use format=csv instead.');
        }
        if ($sheets === []) {
            throw new RuntimeException('Nothing to export.');
        }

        $file = tempnam(sys_get_temp_dir(), 'testpilot-xlsx-');
        $zip = new ZipArchive();
        if ($file === false || $zip->open($file, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the .xlsx file.');
        }

        $names = self::sheetNames(array_keys($sheets));
        $count = count($names);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(static fn (int $i): string => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $count)))
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(static fn (int $i, string $name): string => '<sheet name="' . self::xml($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>', range(1, $count), $names))
            . '</sheets>'
            // Filter ranges must be declared as hidden defined names for Excel to keep them.
            . '<definedNames>' . implode('', array_map(static function (int $i, string $name, array $sheet): string {
                $last = self::column(max(1, count($sheet['columns']))) . (count($sheet['rows']) + 1);

                return '<definedName name="_xlnm._FilterDatabase" localSheetId="' . ($i - 1) . '" hidden="1">\'' . self::xml(str_replace("'", "''", $name)) . '\'!$A$1:$' . preg_replace('/(\d+)$/', '\$$1', $last) . '</definedName>';
            }, range(1, $count), $names, array_values($sheets))) . '</definedNames>'
            . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(static fn (int $i): string => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, $count)))
            . '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        // Styles: 0 default, 1 header (bold, grey fill, border), 2 body (top-aligned, wrapped).
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
            . '</styleSheet>');

        $i = 1;
        foreach ($sheets as $sheet) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", self::worksheet($sheet));
            $i++;
        }

        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /** @param array{columns: list<array{0: string, 1: int}>, rows: list<list<mixed>>} $sheet */
    public static function csv(array $sheet): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accents correctly
        fputcsv($out, array_map(static fn (array $c): string => $c[0], $sheet['columns']), ',', '"', '');
        foreach ($sheet['rows'] as $row) {
            fputcsv($out, array_map(static function (mixed $v): string {
                $v = is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '');

                // A cell starting with = + - @ would be run as a formula when the file is opened.
                return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v) ? "'" . $v : $v;
            }, $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @param array{columns: list<array{0: string, 1: int}>, rows: list<list<mixed>>} $sheet */
    private static function worksheet(array $sheet): string
    {
        $columns = $sheet['columns'];
        $width = max(1, count($columns));
        $lastRow = count($sheet['rows']) + 1;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>' . implode('', array_map(static fn (int $i, array $c): string => '<col min="' . $i . '" max="' . $i . '" width="' . max(6, (int) $c[1]) . '" customWidth="1"/>', range(1, $width), $columns ?: [['', 10]])) . '</cols>'
            . '<sheetData>';

        $xml .= self::row(1, array_map(static fn (array $c): string => $c[0], $columns), 1);
        foreach ($sheet['rows'] as $n => $row) {
            $xml .= self::row($n + 2, $row, 2);
        }

        return $xml . '</sheetData><autoFilter ref="A1:' . self::column($width) . $lastRow . '"/></worksheet>';
    }

    /** @param list<mixed> $cells */
    private static function row(int $number, array $cells, int $style): string
    {
        $xml = '<row r="' . $number . '">';
        foreach (array_values($cells) as $i => $value) {
            $ref = self::column($i + 1) . $number;
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $xml .= '<c r="' . $ref . '" s="' . $style . '" t="b"><v>' . ($value ? 1 : 0) . '</v></c>';
            } elseif (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . (is_finite((float) $value) ? $value : 0) . '</v></c>';
            } else {
                $text = mb_substr(self::clean((string) (is_array($value) ? json_encode($value) : $value)), 0, self::MAX_CELL);
                $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml($text) . '</t></is></c>';
            }
        }

        return $xml . '</row>';
    }

    /** 1 → A, 27 → AA */
    public static function column(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /**
     * Excel sheet names: ≤ 31 characters, no []:*?/\ and unique.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sheetNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $base = mb_substr(trim((string) preg_replace('#[\[\]:*?/\\\\]#', ' ', $name)) ?: 'Sheet', 0, 31);
            $candidate = $base;
            for ($n = 2; in_array(mb_strtolower($candidate), array_map('mb_strtolower', $out), true); $n++) {
                $candidate = mb_substr($base, 0, 31 - strlen(" ({$n})")) . " ({$n})";
            }
            $out[] = $candidate;
        }

        return $out;
    }

    private static function clean(string $text): string
    {
        // Remove characters XML 1.0 does not allow (control bytes from test output, invalid UTF-8).
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
