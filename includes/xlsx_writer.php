<?php
/**
 * Minimal dependency-free XLSX writer.
 *
 * Writes real .xlsx (OOXML) files using only PHP's built-in ZipArchive
 * extension — no Composer, no third-party library, consistent with the
 * rest of this app. Supports exactly what the exports here need: multiple
 * sheets, inline strings, a numeric format, bold/filled header cells
 * (optionally with rotated text), frozen panes, autofilter, column widths
 * and row heights.
 *
 * Sheet definition shape (see write_xlsx()):
 * [
 *   'name'       => 'Sheet Name',
 *   'rows'       => [ [ ['v' => $value, 's' => $styleKey], ... ], ... ],
 *   'colWidths'  => [22, 14, ...],          // 1-indexed by column
 *   'rowHeights' => [1 => 150],             // rowNumber => height (points)
 *   'freeze'     => ['x' => 0, 'y' => 2],   // columns/rows frozen
 *   'autofilter' => 'A1:F10',               // or null
 * ]
 *
 * A cell's value may be an int, float, string, or null — null (or an empty
 * string) skips the cell, leaving it blank, which is normal in a sparse
 * OOXML sheet. A cell's style is one of: null, 'header', 'header_rotated',
 * 'title', 'number'.
 */

function xlsx_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_col_letter(int $col): string {
    $letter = '';
    while ($col > 0) {
        $mod = ($col - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $col = intdiv($col - 1, 26);
    }
    return $letter;
}

function write_xlsx(string $path, array $sheets): void {
    if (file_exists($path)) @unlink($path);

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Could not create XLSX at $path");
    }

    $names = array_column($sheets, 'name');

    $zip->addFromString('[Content_Types].xml', xlsx_content_types(count($sheets)));
    $zip->addFromString('_rels/.rels', xlsx_root_rels());
    $zip->addFromString('docProps/core.xml', xlsx_core_props());
    $zip->addFromString('docProps/app.xml', xlsx_app_props($names));
    $zip->addFromString('xl/workbook.xml', xlsx_workbook_xml($sheets));
    $zip->addFromString('xl/_rels/workbook.xml.rels', xlsx_workbook_rels(count($sheets)));
    $zip->addFromString('xl/styles.xml', xlsx_styles_xml());

    foreach ($sheets as $i => $sheet) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsx_sheet_xml($sheet));
    }

    $zip->close();
}

function xlsx_content_types(int $sheetCount): string {
    $overrides = '';
    for ($i = 1; $i <= $sheetCount; $i++) {
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . $overrides
        . '</Types>';
}

function xlsx_root_rels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function xlsx_core_props(): string {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Stock Management</dc:creator>'
        . '<cp:lastModifiedBy>Stock Management</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function xlsx_app_props(array $sheetNames): string {
    $titles = '';
    foreach ($sheetNames as $name) {
        $titles .= '<vt:lpstr>' . xlsx_escape($name) . '</vt:lpstr>';
    }
    $count = count($sheetNames);
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Stock Management</Application>'
        . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . $count . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
        . '<TitlesOfParts><vt:vector size="' . $count . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
        . '</Properties>';
}

function xlsx_workbook_xml(array $sheets): string {
    $sheetXml = '';
    foreach ($sheets as $i => $sheet) {
        $id = $i + 1;
        $sheetXml .= '<sheet name="' . xlsx_escape($sheet['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetXml . '</sheets>'
        . '</workbook>';
}

function xlsx_workbook_rels(int $sheetCount): string {
    $rels = '';
    for ($i = 1; $i <= $sheetCount; $i++) {
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $stylesId = $sheetCount + 1;
    $rels .= '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $rels
        . '</Relationships>';
}

// Style indices below (referenced by the 's' key on a cell) must match the
// order fonts/fills/cellXfs are written in here.
function xlsx_styles_xml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.##"/></numFmts>'
        . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            . '<font><b/><sz val="13"/><color rgb="FF0E5C56"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0E5C56"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="bottom" textRotation="90"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '</styleSheet>';
}

function xlsx_sheet_xml(array $sheet): string {
    $rows = $sheet['rows'];
    $colWidths = $sheet['colWidths'] ?? [];
    $rowHeights = $sheet['rowHeights'] ?? [];
    $freeze = $sheet['freeze'] ?? null;
    $autofilter = $sheet['autofilter'] ?? null;

    $styleMap = ['header' => 1, 'header_rotated' => 2, 'number' => 3, 'title' => 4];

    $maxCol = 0;
    $rowsXml = '';
    foreach ($rows as $rIdx0 => $row) {
        $r = $rIdx0 + 1;
        $rowAttrs = 'r="' . $r . '"';
        if (isset($rowHeights[$r])) {
            $rowAttrs .= ' ht="' . $rowHeights[$r] . '" customHeight="1"';
        }
        $cellsXml = '';
        foreach ($row as $cIdx0 => $cell) {
            $c = $cIdx0 + 1;
            if ($c > $maxCol) $maxCol = $c;
            $value = is_array($cell) ? ($cell['v'] ?? null) : $cell;
            $styleKey = is_array($cell) ? ($cell['s'] ?? null) : null;
            if ($value === null || $value === '') continue; // sparse: leave the cell blank
            $ref = xlsx_col_letter($c) . $r;
            $sAttr = isset($styleMap[$styleKey]) ? ' s="' . $styleMap[$styleKey] . '"' : '';
            if (is_int($value) || is_float($value)) {
                $cellsXml .= '<c r="' . $ref . '"' . $sAttr . '><v>' . $value . '</v></c>';
            } else {
                $cellsXml .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape((string)$value) . '</t></is></c>';
            }
        }
        $rowsXml .= '<row ' . $rowAttrs . '>' . $cellsXml . '</row>';
    }

    $lastRow = count($rows);
    $dimension = 'A1:' . xlsx_col_letter(max($maxCol, 1)) . max($lastRow, 1);

    $colsXml = '';
    foreach ($colWidths as $i => $w) {
        $colNum = $i + 1;
        $colsXml .= '<col min="' . $colNum . '" max="' . $colNum . '" width="' . $w . '" customWidth="1"/>';
    }

    $sheetViewInner = '';
    if ($freeze) {
        $x = $freeze['x'] ?? 0;
        $y = $freeze['y'] ?? 0;
        $topLeftCell = xlsx_col_letter($x + 1) . ($y + 1);
        $activePane = ($x > 0 && $y > 0) ? 'bottomRight' : ($x > 0 ? 'topRight' : 'bottomLeft');
        $sheetViewInner = '<pane xSplit="' . $x . '" ySplit="' . $y . '" topLeftCell="' . $topLeftCell . '" activePane="' . $activePane . '" state="frozen"/>'
            . '<selection pane="' . $activePane . '" activeCell="' . $topLeftCell . '" sqref="' . $topLeftCell . '"/>';
    }

    $autoFilterXml = $autofilter ? '<autoFilter ref="' . xlsx_escape($autofilter) . '"/>' : '';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView workbookViewId="0">' . $sheetViewInner . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . ($colsXml ? '<cols>' . $colsXml . '</cols>' : '')
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . $autoFilterXml
        . '</worksheet>';
}
