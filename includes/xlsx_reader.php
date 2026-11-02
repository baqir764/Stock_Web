<?php
/**
 * Minimal dependency-free XLSX reader — the counterpart to xlsx_writer.php.
 *
 * Reads real .xlsx (OOXML) files using only PHP's built-in ZipArchive and
 * SimpleXML extensions — no Composer, no third-party library. Handles both
 * inline strings (what xlsx_writer.php produces) and a shared-strings table
 * (what real Excel writes if the file is opened and re-saved there), so a
 * round-tripped export still imports correctly.
 */

function xlsx_col_index(string $letters): int {
    $letters = strtoupper($letters);
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $idx - 1; // 0-based
}

/**
 * Returns ['SheetName' => [ [col0, col1, ...], ... ], ...] — one array of
 * rows per sheet, each row a 0-indexed, null-padded array of cell values
 * (all as strings, except blank cells which are null) so column position
 * always lines up with whatever header row the caller is matching against.
 */
// Thin wrapper around simplexml_load_string for XML that comes from an
// uploaded file (i.e. untrusted). LIBXML_NONET stops libxml from following
// any external reference (DTD/entity/schema) out to the network, and
// internal errors are captured instead of surfacing as warnings — belt and
// suspenders on top of the fact that PHP has disabled external entity
// expansion by default since 5.4.30/5.5.14, so this is defense-in-depth
// rather than a fix for an exploitable gap on any currently-supported PHP.
function xlsx_load_xml(string $xml) {
    $prevUseErrors = libxml_use_internal_errors(true);
    $result = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prevUseErrors);
    return $result;
}

function xlsx_read(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('not a valid .xlsx file (could not open as a zip archive)');
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        $zip->close();
        throw new RuntimeException('not a valid .xlsx file (missing workbook parts)');
    }

    $wb = xlsx_load_xml($workbookXml);
    $rels = xlsx_load_xml($relsXml);
    if ($wb === false || $rels === false) {
        $zip->close();
        throw new RuntimeException('not a valid .xlsx file (unreadable workbook XML)');
    }

    $relTargets = [];
    foreach ($rels->Relationship as $rel) {
        $relTargets[(string)$rel['Id']] = (string)$rel['Target'];
    }

    // Shared strings table (optional — our own exports don't use one, but a
    // file that has been re-saved by real Excel usually will).
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sst = xlsx_load_xml($sharedXml);
        if ($sst !== false) {
            foreach ($sst->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) $text .= (string)$r->t;
                    $shared[] = $text;
                }
            }
        }
    }

    $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $sheets = [];
    foreach ($wb->sheets->sheet as $sheetEl) {
        $name = (string)$sheetEl['name'];
        $rIdAttrs = $sheetEl->attributes($relNs);
        $rId = (string)($rIdAttrs['id'] ?? '');
        if ($rId === '' || !isset($relTargets[$rId])) continue;

        $target = ltrim($relTargets[$rId], '/');
        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;

        $sheetXml = $zip->getFromName($target);
        if ($sheetXml === false) continue;
        $sx = xlsx_load_xml($sheetXml);
        if ($sx === false) continue;

        $rows = [];
        if (isset($sx->sheetData->row)) {
            foreach ($sx->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    if (!preg_match('/^([A-Za-z]+)(\d+)$/', $ref, $m)) continue;
                    $colIdx = xlsx_col_index($m[1]);
                    $type = (string)$c['t'];

                    if ($type === 's') {
                        $v = isset($c->v) ? (int)(string)$c->v : null;
                        $rowData[$colIdx] = ($v !== null && isset($shared[$v])) ? $shared[$v] : null;
                    } elseif ($type === 'inlineStr') {
                        if (isset($c->is->t)) {
                            $rowData[$colIdx] = (string)$c->is->t;
                        } elseif (isset($c->is->r)) {
                            $text = '';
                            foreach ($c->is->r as $r) $text .= (string)$r->t;
                            $rowData[$colIdx] = $text;
                        } else {
                            $rowData[$colIdx] = null;
                        }
                    } else {
                        // 'str' (formula result) or numeric/blank — take <v> as-is.
                        $rowData[$colIdx] = isset($c->v) ? (string)$c->v : null;
                    }
                }
                if ($rowData) {
                    $maxCol = max(array_keys($rowData));
                    $padded = [];
                    for ($i = 0; $i <= $maxCol; $i++) {
                        $padded[$i] = array_key_exists($i, $rowData) ? $rowData[$i] : null;
                    }
                    $rows[] = $padded;
                } else {
                    $rows[] = [];
                }
            }
        }
        $sheets[$name] = $rows;
    }

    $zip->close();
    return $sheets;
}
