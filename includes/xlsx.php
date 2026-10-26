<?php
/**
 * SmartClass - minimal Excel (.xlsx) writer (loaded by includes/functions.php); needs PHP's zip extension.
 *   xlsxDownload($filename, $sheets)   streams a workbook
 *   $sheets = [ 'Sheet name' => ['title' => 'optional first line', 'header' => ['Col A', …], 'rows' => [[…], …], 'widths' => [24, 12, …], 'notes' => ['text under the table', …]] ]
 * Numbers stay numbers; every text is written as a string, never as a formula (a cell such as "=1+1" is just text),
 * so exported names or remarks can never run anything when the file is opened.
 */
function xlsxColumn($i) {   // 0 → A, 26 → AA
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function xlsxEsc($v) {
    $v = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $v);
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxCell($ref, $v, $style = 0) {
    if ($v === null || $v === '') return $style ? '<c r="' . $ref . '" s="' . $style . '"/>' : '';
    if (is_int($v) || is_float($v)) return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . (is_float($v) ? rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') : $v) . '</v></c>';
    return '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . xlsxEsc($v) . '</t></is></c>';
}

function xlsxSheetXml(array $sh) {
    $rows = []; $r = 1; $freeze = 0;
    if (!empty($sh['title'])) { $rows[] = '<row r="' . $r . '">' . xlsxCell('A' . $r, $sh['title'], 3) . '</row>'; $r += 2; }
    $head_row = 0;
    if (!empty($sh['header'])) {
        $cells = '';
        foreach ($sh['header'] as $i => $h) $cells .= xlsxCell(xlsxColumn($i) . $r, $h, 1);
        $rows[] = '<row r="' . $r . '">' . $cells . '</row>'; $head_row = $r; $r++;
    }
    foreach ($sh['rows'] ?? [] as $row) {
        $cells = '';
        foreach (array_values($row) as $i => $v) $cells .= xlsxCell(xlsxColumn($i) . $r, $v, is_array($v) ? 0 : 0);
        $rows[] = '<row r="' . $r . '">' . $cells . '</row>'; $r++;
    }
    foreach ($sh['notes'] ?? [] as $n) { $r++; $rows[] = '<row r="' . $r . '">' . xlsxCell('A' . $r, $n, 4) . '</row>'; }
    $cols = '';
    foreach ($sh['widths'] ?? [] as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
    $pane = $head_row ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $head_row . '" topLeftCell="A' . ($head_row + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $pane
        . ($cols ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>' . implode('', $rows) . '</sheetData></worksheet>';
}

function xlsxDownload($filename, array $sheets) {
    if (!class_exists('ZipArchive')) { http_response_code(500); echo 'Excel export needs the PHP zip extension.'; exit; }
    $tmp = tempnam(sys_get_temp_dir(), 'scx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $names = array_keys($sheets);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names))) . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . implode('', array_map(fn($i, $n) => '<sheet name="' . xlsxEsc(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $n), 0, 31)) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', array_keys($names), $names)) . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', array_map(fn($i) => '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>', array_keys($names)))
        . '<Relationship Id="rId' . (count($names) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    // styles: 0 normal, 1 header (bold, blue-grey fill, border), 3 title (bold 14), 4 note (grey italic)
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF667085"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8ECF8"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FF98A2B3"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
    foreach (array_values($sheets) as $i => $sh) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsxSheetXml($sh));
    $zip->close();
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($filename, PATHINFO_FILENAME)) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . filesize($tmp));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
