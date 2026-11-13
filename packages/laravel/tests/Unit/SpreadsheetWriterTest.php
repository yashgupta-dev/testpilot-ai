<?php

declare(strict_types=1);

use TestPilot\Laravel\Support\SpreadsheetWriter;

function sampleSheet(array $rows): array
{
    return ['columns' => [['Case', 30], ['Input', 20], ['Confidence', 10]], 'rows' => $rows];
}

it('writes a valid multi-sheet xlsx with a header row, filter and frozen pane', function () {
    $bytes = SpreadsheetWriter::xlsx([
        'Test cases' => sampleSheet([['rejects <script> & "quotes"', '=SUM(A1)', 0.95], ['ok', null, 1]]),
        'Summary/Q1' => sampleSheet([['x', 'y', 2]]),
    ]);
    $file = tempnam(sys_get_temp_dir(), 'tp');
    file_put_contents($file, $bytes);
    $zip = new ZipArchive();
    $zip->open($file);

    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');

    foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $part) {
        expect(simplexml_load_string((string) $zip->getFromName($part)))->not->toBeFalse();
    }
    expect($workbook)->toContain('name="Test cases"', 'name="Summary Q1"')
        ->and($sheet)->toContain('rejects &lt;script&gt; &amp; &quot;quotes&quot;', '<autoFilter ref="A1:C3"/>', 'state="frozen"', '<v>0.95</v>')
        ->and($sheet)->toContain('<t xml:space="preserve">=SUM(A1)</t>') // stored as text, never as a formula
        ->and($sheet)->not->toContain('<f>');
    $zip->close();
    unlink($file);
});

it('neutralises formulas in CSV and keeps numbers', function () {
    $csv = SpreadsheetWriter::csv(sampleSheet([['=HYPERLINK("http://x")', '-5', 3], ['@cmd', 'plain', null]]));

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain("\"'=HYPERLINK(\"\"http://x\"\")\"", ',-5,3', "'@cmd");
});

it('names columns beyond Z', function () {
    expect(SpreadsheetWriter::column(1))->toBe('A')
        ->and(SpreadsheetWriter::column(26))->toBe('Z')
        ->and(SpreadsheetWriter::column(27))->toBe('AA')
        ->and(SpreadsheetWriter::column(703))->toBe('AAA');
});
