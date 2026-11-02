<?php
require __DIR__ . '/includes/functions.php';
require_login();
require __DIR__ . '/includes/xlsx_reader.php';

function csv_cell(array $row, array $headerMap, string $name): string {
    $idx = $headerMap[$name] ?? null;
    return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
}

// Finds the ONE item-number column in a CSV header. This app exports it as
// "Item #"; the other spellings are accepted so hand-made or older sheets
// still import. Values are always the public number, never the hidden
// internal ItemID — see stockapp_item_codes() in config/db.php.
function item_no_column(array $headerMap): ?int {
    foreach (['item #', 'item#', 'item no', 'itemno', 'item number', 'itemnumber',
              'item code', 'itemcode', 'itemid', 'item'] as $alias) {
        if (isset($headerMap[$alias])) return (int)$headerMap[$alias];
    }
    return null;
}

function csv_cell_at(array $row, ?int $idx): string {
    return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
}

// One file, two independent import abilities — GRNs and Stock Cards each keep
// their own required columns, grouping rules and save logic. Either ability
// accepts a plain CSV or this app's own exported .xlsx (GRN Details /
// Stock Cards Details) as the source file; whichever format is uploaded is
// parsed down into the same $groups shape, then saved the same way.
$type = (($_GET['type'] ?? $_POST['import_type'] ?? 'grn') === 'card') ? 'card' : 'grn';

$error = null;
$report = null;
$groups = null;
$blankRows = 0;

// Which office these GRNs/Stock Cards (and, for the Card import, the
// facilities/items they reference) belong to — staff always import into
// their own office; admin must explicitly choose one.
if (is_admin()) {
    $importOffice = $_POST['office'] ?? '';
    $importOffice = array_key_exists($importOffice, office_options()) ? $importOffice : null;
} else {
    $importOffice = current_user()['Office'] ?? null;
}

// Storekeeper name applied to NEWLY CREATED stock cards during this import —
// an editable field pre-filled with the importing user's own name. Cards that
// already exist go through the UPDATE branch below, which never touches
// StorekeeperName, so existing cards always keep the name they were created
// with no matter what is typed here.
$me = current_user();
$importStorekeeper = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['storekeeper_name']))
    ? trim((string)$_POST['storekeeper_name'])
    : trim((string)($me['FullName'] ?: $me['Username']));

// Printed subject line applied to NEWLY CREATED stock cards during this
// import — pre-filled with the last subject this office already used, or
// the classic line if it has never used a custom one. Cards that already
// exist go through the UPDATE branch below, which never touches Subject,
// so an existing card always keeps the subject it already has (whether that
// came from its creation or from a later edit on the stock card form) no
// matter what is typed here. Changing a card's subject is a deliberate act
// done on that card's own edit form, never a side effect of a bulk import.
$importSubject = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['printed_subject']))
    ? trim((string)$_POST['printed_subject'])
    : (last_release_subject($importOffice) ?? release_subject_classic());

// Project filed on NEWLY CREATED documents during this import — the same
// forward-only rule as the storekeeper name and the printed subject: the
// UPDATE branches below never touch ProjectID, so documents that already
// exist keep whichever project they were created with (or "No project").
$importProjectRaw = trim((string)($_POST['project_id'] ?? ''));
$importProject = null;
if ($importProjectRaw !== '' && $importProjectRaw !== 'none' && ctype_digit($importProjectRaw)) {
    $projectCheck = db()->prepare('SELECT ProjectID FROM Projects WHERE ProjectID = ?');
    $projectCheck->execute([(int)$importProjectRaw]);
    $importProject = (int)$projectCheck->fetchColumn() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && !csrf_verify()) {
    $error = 'Your session expired — please choose the file and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && $importOffice === null) {
    $error = is_admin()
        ? 'Please choose which office to import these into.'
        : 'Your account has no office assigned yet — contact an administrator.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
        $error = 'No file was uploaded, or the upload failed. Please choose a .csv or .xlsx file and try again.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            // ==================== Parse a CSV file into $groups ====================
            $handle = fopen($file['tmp_name'], 'r');
            $headerRow = $handle ? fgetcsv($handle) : false;

            if (!$headerRow) {
                $error = 'The file is empty or not a readable CSV.';
            } else {
                $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]);
                $headerMap = [];
                foreach ($headerRow as $idx => $name) {
                    $headerMap[strtolower(trim((string)$name))] = $idx;
                }
                // One lookup for BOTH import types (GRN and stock card).
                $itemNoCol = item_no_column($headerMap);

                if ($type === 'grn') {
                    $required = ['grnnumber', 'quantity'];
                    $missing = array_filter($required, fn($c) => !isset($headerMap[$c]));
                    if ($itemNoCol === null) $missing[] = 'Item #';

                    if ($missing) {
                        $error = 'Missing required column(s): ' . implode(', ', $missing) . '. Expected at least GRNNumber, Item #, Quantity.';
                    } else {
                        $groups = [];
                        while (($row = fgetcsv($handle)) !== false) {
                            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                            $grn = csv_cell($row, $headerMap, 'grnnumber');
                            if ($grn === '') {
                                $blankRows++;
                                continue;
                            }
                            if (!isset($groups[$grn])) {
                                $groups[$grn] = [
                                    'date' => csv_cell($row, $headerMap, 'receiptdate'),
                                    'supplier' => csv_cell($row, $headerMap, 'supplier'),
                                    'group' => csv_cell($row, $headerMap, 'group'),
                                    'lines' => [],
                                ];
                            }
                            $groups[$grn]['lines'][] = [
                                'item_no' => csv_cell_at($row, $itemNoCol),
                                'qty' => (float)csv_cell($row, $headerMap, 'quantity'),
                            ];
                        }
                        fclose($handle);
                    }
                } else {
                    $required = ['stockcardno', 'quantity'];
                    $missing = array_filter($required, fn($c) => !isset($headerMap[$c]));
                    if ($itemNoCol === null) $missing[] = 'Item #';

                    if ($missing) {
                        $error = 'Missing required column(s): ' . implode(', ', $missing) . '. Expected at least StockCardNo, Item #, Quantity.';
                    } else {
                        $hfs = all_health_facilities($importOffice);
                        $hfById = array_column($hfs, null, 'HFID');
                        $hfIdByName = array_column($hfs, 'HFID', 'HFName');

                        $groups = [];
                        while (($row = fgetcsv($handle)) !== false) {
                            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                            $cardNo = csv_cell($row, $headerMap, 'stockcardno');
                            if ($cardNo === '') {
                                $blankRows++;
                                continue;
                            }
                            if (!isset($groups[$cardNo])) {
                                $hfIdRaw = csv_cell($row, $headerMap, 'hfid');
                                $hfNameRaw = csv_cell($row, $headerMap, 'hfname');
                                $hfId = null;
                                if ($hfIdRaw !== '' && ctype_digit($hfIdRaw) && isset($hfById[(int)$hfIdRaw])) {
                                    $hfId = (int)$hfIdRaw;
                                } elseif ($hfNameRaw !== '' && isset($hfIdByName[$hfNameRaw])) {
                                    $hfId = (int)$hfIdByName[$hfNameRaw];
                                }
                                $groups[$cardNo] = [
                                    'date' => csv_cell($row, $headerMap, 'releasedate'),
                                    'hf_id' => $hfId,
                                    'hf_id_raw' => $hfIdRaw,
                                    'hf_name_raw' => $hfNameRaw,
                                    'receiver' => csv_cell($row, $headerMap, 'receivername'),
                                    'group' => csv_cell($row, $headerMap, 'group'),
                                    'lines' => [],
                                ];
                            }
                            $groups[$cardNo]['lines'][] = [
                                'item_no' => csv_cell_at($row, $itemNoCol),
                                'qty' => (float)csv_cell($row, $headerMap, 'quantity'),
                            ];
                        }
                        fclose($handle);
                    }
                }
            }
        } elseif ($ext === 'xlsx') {
            // ==================== Parse this app's own exported .xlsx into $groups ====================
            try {
                $sheets = xlsx_read($file['tmp_name']);
            } catch (Throwable $e) {
                $error = 'Could not read this Excel file — ' . $e->getMessage() . '.';
                $sheets = null;
            }

            if ($sheets !== null) {
                if (!isset($sheets['Items'])) {
                    $error = 'This doesn\'t look like a file exported from this app — no "Items" sheet found.';
                } else {
                    $listSheetName = null;
                    foreach ($sheets as $name => $rows) {
                        if ($name !== 'Items') {
                            $listSheetName = $name;
                            break;
                        }
                    }

                    // Guard against uploading a GRN export to the Card import (or vice
                    // versa): check the list sheet's header rather than trusting the tab
                    // name, since a title row may or may not be present and either could
                    // have been renamed.
                    if ($listSheetName !== null) {
                        $checkRows = $sheets[$listSheetName];
                        $checkDataStart = (isset($checkRows[0][0]) && preg_match('/\(\d+\)/', (string)$checkRows[0][0])) ? 2 : 1;
                        $checkHeaderCell = strtolower(trim((string)($checkRows[$checkDataStart - 1][0] ?? '')));
                        $looksLikeGrn = strpos($checkHeaderCell, 'grn') !== false;
                        $looksLikeCard = strpos($checkHeaderCell, 'card') !== false;

                        if ($type === 'grn' && $looksLikeCard && !$looksLikeGrn) {
                            $error = 'This looks like a Stock Cards export file, not a GRN one — upload it from the "Import Stock Cards" tab instead.';
                        } elseif ($type === 'card' && $looksLikeGrn && !$looksLikeCard) {
                            $error = 'This looks like a GRN export file, not a Stock Cards one — upload it from the "Import GRNs" tab instead.';
                        }
                    }
                }
            }

            if ($sheets !== null && !$error) {
                if (!isset($sheets['Items'])) {
                    // Already reported above.
                } else {
                    $itemsRows = $sheets['Items'];
                    $itemsHeader = $itemsRows[0] ?? [];
                    $nMeta = null;
                    foreach ($itemsHeader as $i => $h) {
                        if (stripos((string)$h, 'Total Qty') !== false) $nMeta = $i + 1;
                    }
                    if ($nMeta === null) $nMeta = 6; // fallback: matches every export this app produces

                    $docNumbers = [];
                    for ($i = $nMeta; $i < count($itemsHeader); $i++) {
                        $docNo = trim((string)($itemsHeader[$i] ?? ''));
                        if ($docNo !== '') $docNumbers[$i] = $docNo;
                    }

                    // Item # + doc column -> quantity, grouped back into per-document lines.
                    $linesByDoc = [];
                    for ($r = 1; $r < count($itemsRows); $r++) {
                        $row = $itemsRows[$r];
                        $itemNo = trim((string)($row[0] ?? ''));
                        if ($itemNo === '') continue;
                        foreach ($docNumbers as $colIdx => $docNo) {
                            $qty = $row[$colIdx] ?? null;
                            if ($qty === null || $qty === '') continue;
                            $qty = (float)$qty;
                            if ($qty <= 0) continue;
                            $linesByDoc[$docNo][] = ['item_no' => $itemNo, 'qty' => $qty];
                        }
                    }

                    // Document-level fields (date, supplier / facility, receiver), from the
                    // list sheet. Skip the title row ("GRNs (2)" / "Stock Cards (117)") if
                    // present, and the header row right under it.
                    $metaByDoc = [];
                    if ($listSheetName !== null) {
                        $listRows = $sheets[$listSheetName];
                        $dataStart = (isset($listRows[0][0]) && preg_match('/\(\d+\)/', (string)$listRows[0][0])) ? 2 : 1;
                        for ($r = $dataStart; $r < count($listRows); $r++) {
                            $row = $listRows[$r];
                            if (empty($row)) continue;
                            $docNo = trim((string)($row[0] ?? ''));
                            if ($docNo === '') continue;
                            // Column 0 is the doc number; column 1 is Group in both list
                            // sheets this app exports, so the rest shift over by one slot.
                            if ($type === 'grn') {
                                $metaByDoc[$docNo] = ['group' => (string)($row[1] ?? ''), 'date' => (string)($row[2] ?? ''), 'supplier' => (string)($row[3] ?? '')];
                            } else {
                                $metaByDoc[$docNo] = ['group' => (string)($row[1] ?? ''), 'date' => (string)($row[2] ?? ''), 'hf_name' => (string)($row[3] ?? ''), 'receiver' => (string)($row[5] ?? '')];
                            }
                        }
                    }

                    $allDocNos = array_unique(array_merge(array_keys($metaByDoc), array_keys($linesByDoc)));
                    if (!$allDocNos) {
                        $error = $type === 'grn' ? 'No GRN data found in this file.' : 'No Stock Card data found in this file.';
                    } else {
                        $groups = [];
                        if ($type === 'grn') {
                            foreach ($allDocNos as $docNo) {
                                $groups[$docNo] = [
                                    'date' => $metaByDoc[$docNo]['date'] ?? '',
                                    'supplier' => $metaByDoc[$docNo]['supplier'] ?? '',
                                    'group' => $metaByDoc[$docNo]['group'] ?? '',
                                    'lines' => $linesByDoc[$docNo] ?? [],
                                ];
                            }
                        } else {
                            $hfs = all_health_facilities($importOffice);
                            $hfIdByName = array_column($hfs, 'HFID', 'HFName');
                            foreach ($allDocNos as $docNo) {
                                $hfName = $metaByDoc[$docNo]['hf_name'] ?? '';
                                $hfId = ($hfName !== '' && isset($hfIdByName[$hfName])) ? (int)$hfIdByName[$hfName] : null;
                                $groups[$docNo] = [
                                    'date' => $metaByDoc[$docNo]['date'] ?? '',
                                    'hf_id' => $hfId,
                                    'hf_id_raw' => '',
                                    'hf_name_raw' => $hfName,
                                    'receiver' => $metaByDoc[$docNo]['receiver'] ?? '',
                                    'group' => $metaByDoc[$docNo]['group'] ?? '',
                                    'lines' => $linesByDoc[$docNo] ?? [],
                                ];
                            }
                        }
                    }
                }
            }
        } else {
            $error = 'Unsupported file type — please upload a .csv or .xlsx file.';
        }
    }

    // ==================== Save $groups — identical regardless of source format ====================
    if ($groups !== null && !$error) {
        if ($type === 'grn') {
            // Files name items by their ONE public number, never by the
            // hidden internal ItemID (same rule in the Card branch below).
            $itemsByCode = [];
            foreach (all_items($importOffice) as $it) {
                $key = normalize_item_code($it['ItemCode'] ?? null);
                if ($key !== '') $itemsByCode[$key] = (int)$it['ItemID'];
            }
            $pdo = db();
            $me = current_user();

            $created = 0;
            $updated = 0;
            $totalLines = 0;
            $droppedNoQty = 0;
            $skippedItemNos = [];
            $failed = []; // GRNNumber => reason

            foreach ($groups as $grn => $g) {
                $pdo->beginTransaction();
                try {
                    // Matching is scoped to $importOffice — a GRN number that
                    // exists in the other office is treated as new here, never
                    // updated (two offices are free to reuse the same number).
                    $stmt = $pdo->prepare('SELECT ReceiptID FROM StockReceipts WHERE GRNNumber = ? AND Office = ?');
                    $stmt->execute([$grn, $importOffice]);
                    $receiptId = $stmt->fetchColumn();
                    $isUpdate = (bool)$receiptId;
                    $group = trim($g['group'] ?? '');
                    if ($group === '') $group = null;

                    if ($isUpdate) {
                        $pdo->prepare('UPDATE StockReceipts SET ReceiptDate = ?, Supplier = ?, GroupName = ?, UpdatedByUserID = ?, UpdatedAt = datetime(\'now\') WHERE ReceiptID = ?')
                            ->execute([$g['date'], $g['supplier'], $group, $me['UserID'], $receiptId]);
                        $pdo->prepare('DELETE FROM StockReceiptDetails WHERE ReceiptID = ?')->execute([$receiptId]);
                    } else {
                        $pdo->prepare('INSERT INTO StockReceipts (GRNNumber, ReceiptDate, Supplier, GroupName, Office, ProjectID, CreatedByUserID, CreatedAt) VALUES (?,?,?,?,?,?,?,datetime(\'now\'))')
                            ->execute([$grn, $g['date'], $g['supplier'], $group, $importOffice, $importProject, $me['UserID']]);
                        $receiptId = $pdo->lastInsertId();
                    }

                    $lineStmt = $pdo->prepare('INSERT INTO StockReceiptDetails (ReceiptID, ItemID, Quantity) VALUES (?,?,?)');
                    $count = 0;
                    foreach ($g['lines'] as $line) {
                        $itemNo = normalize_item_code($line['item_no'] ?? null);
                        if ($itemNo === '') continue;
                        if ($line['qty'] <= 0) {
                            $droppedNoQty++;
                            continue;
                        }
                        if (!isset($itemsByCode[$itemNo])) {
                            $skippedItemNos[] = $line['item_no'];
                            continue;
                        }
                        $lineStmt->execute([$receiptId, $itemsByCode[$itemNo], $line['qty']]);
                        $count++;
                    }

                    if ($count === 0) throw new Exception('No valid item line (valid Item # + quantity > 0) found for this GRN.');

                    $pdo->commit();
                    $totalLines += $count;
                    if ($isUpdate) $updated++; else $created++;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $failed[$grn] = $e->getMessage();
                }
            }

            $report = [
                'created' => $created,
                'updated' => $updated,
                'totalLines' => $totalLines,
                'droppedNoQty' => $droppedNoQty,
                'skippedItemNos' => array_values(array_unique($skippedItemNos)),
                'blankRows' => $blankRows,
                'failed' => $failed,
            ];
        } else {
            $items = stock_balance($importOffice);
            // One public number -> internal row, scoped to this office.
            $itemsByCode = [];
            foreach ($items as $it) {
                $key = normalize_item_code($it['ItemCode'] ?? null);
                if ($key !== '') $itemsByCode[$key] = (int)$it['ItemID'];
            }
            $itemNames = array_column($items, 'ItemName', 'ItemID');
            $hfs = all_health_facilities($importOffice);
            $hfById = array_column($hfs, null, 'HFID');
            $pdo = db();
            $me = current_user();

            $created = 0;
            $updated = 0;
            $totalLines = 0;
            $droppedNoQty = 0;
            $skippedItemNos = [];
            $failed = []; // StockCardNo => reason

            foreach ($groups as $cardNo => $g) {
                if ($g['hf_id'] === null) {
                    $bad = $g['hf_id_raw'] !== '' ? $g['hf_id_raw'] : ($g['hf_name_raw'] !== '' ? $g['hf_name_raw'] : '(blank)');
                    $failed[$cardNo] = "Receiving facility \"$bad\" not found. Provide a valid HFID or an exact HFName match.";
                    continue;
                }
                $hfId = $g['hf_id'];

                $pdo->beginTransaction();
                try {
                    // Matching scoped to $importOffice — same reasoning as the
                    // GRN branch above.
                    $stmt = $pdo->prepare('SELECT ReleaseID FROM StockReleases WHERE StockCardNo = ? AND Office = ?');
                    $stmt->execute([$cardNo, $importOffice]);
                    $releaseId = $stmt->fetchColumn();
                    $isUpdate = (bool)$releaseId;
                    $group = trim($g['group'] ?? '');
                    if ($group === '') $group = null;

                    $receiverName = $g['receiver'];
                    if ($receiverName === '') {
                        $receiverName = trim((string)($hfById[$hfId]['InChargePerson'] ?? ''));
                    }

                    if ($isUpdate) {
                        // Note: StorekeeperName and Subject are deliberately NOT
                        // part of this UPDATE — an existing card keeps its
                        // storekeeper name and its current printed subject. The
                        // subject is editable on the card's own form
                        // (release_form.php), just never from an import.
                        $pdo->prepare('UPDATE StockReleases SET ReceiverName = ?, HFID = ?, ReleaseDate = ?, GroupName = ?, UpdatedByUserID = ?, UpdatedAt = datetime(\'now\') WHERE ReleaseID = ?')
                            ->execute([$receiverName, $hfId, $g['date'], $group, $me['UserID'], $releaseId]);
                        $pdo->prepare('DELETE FROM StockReleaseDetails WHERE ReleaseID = ?')->execute([$releaseId]);
                    } else {
                        $storekeeperName = $importStorekeeper !== ''
                            ? $importStorekeeper
                            : trim((string)($me['FullName'] ?: $me['Username']));
                        // Printed subject from the import field (pre-filled with
                        // this office's last used subject or the classic line);
                        // a blank field falls back to the classic line.
                        $subject = $importSubject !== '' ? $importSubject : release_subject_classic();
                        $pdo->prepare('INSERT INTO StockReleases (StockCardNo, ReceiverName, HFID, StorekeeperName, Subject, ReleaseDate, GroupName, Office, ProjectID, CreatedByUserID, CreatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))')
                            ->execute([$cardNo, $receiverName, $hfId, $storekeeperName, $subject, $g['date'], $group, $importOffice, $importProject, $me['UserID']]);
                        $releaseId = $pdo->lastInsertId();
                    }

                    $lineStmt = $pdo->prepare('INSERT INTO StockReleaseDetails (ReleaseID, ItemID, Quantity) VALUES (?,?,?)');
                    $count = 0;
                    $availByItem = [];
                    $overLimit = [];
                    foreach ($g['lines'] as $line) {
                        $itemNo = normalize_item_code($line['item_no'] ?? null);
                        $qty = $line['qty'];
                        if ($itemNo === '') continue;
                        if ($qty <= 0) {
                            $droppedNoQty++;
                            continue;
                        }
                        if (!isset($itemsByCode[$itemNo])) {
                            $skippedItemNos[] = $line['item_no'];
                            continue;
                        }
                        $itemId = $itemsByCode[$itemNo];
                        if (!array_key_exists($itemId, $availByItem)) {
                            $availByItem[$itemId] = item_balance($itemId);
                        }
                        if ($qty > $availByItem[$itemId]) {
                            $name = $itemNames[$itemId] ?? $itemNo;
                            $overLimit[] = sprintf('%s (needs %s, only %s available)', $name, rtrim(rtrim(number_format($qty, 2), '0'), '.'), rtrim(rtrim(number_format($availByItem[$itemId], 2), '0'), '.'));
                            continue;
                        }
                        $lineStmt->execute([$releaseId, $itemId, $qty]);
                        $availByItem[$itemId] -= $qty;
                        $count++;
                    }

                    if ($count === 0) {
                        $reason = $overLimit ? ('Exceeds available balance: ' . implode('; ', $overLimit)) : 'No valid item line (valid Item # + quantity > 0, within available balance) found for this card.';
                        throw new Exception($reason);
                    }

                    $pdo->commit();
                    $totalLines += $count;
                    if ($isUpdate) $updated++; else $created++;
                    if ($overLimit) $failed[$cardNo] = 'Saved with some lines rejected — exceeds available balance: ' . implode('; ', $overLimit);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $failed[$cardNo] = $e->getMessage();
                }
            }

            $report = [
                'created' => $created,
                'updated' => $updated,
                'totalLines' => $totalLines,
                'droppedNoQty' => $droppedNoQty,
                'skippedItemNos' => array_values(array_unique($skippedItemNos)),
                'blankRows' => $blankRows,
                'failed' => $failed,
            ];
        }
    }
}

$pageTitle = $type === 'card' ? 'Import Stock Cards' : 'Import GRNs';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $type === 'card' ? 'Import Release Cards' : 'Import GRNs (Stock In)' ?></h1>
    <p>Bulk-create or update <?= $type === 'card' ? 'stock cards' : 'GRNs' ?> from a CSV or Excel file.</p>
  </div>
  <a class="btn secondary" href="<?= $type === 'card' ? 'releases' : 'receipts' ?>">&larr; Back to <?= $type === 'card' ? 'stock cards' : 'GRNs' ?></a>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<?php if ($report): ?>
  <div class="panel">
    <div class="panel-head"><h2>Import results</h2></div>
    <div style="padding:18px 22px;">
      <p><?= $report['created'] ?> <?= $type === 'card' ? 'stock card(s)' : 'GRN(s)' ?> created, <?= $report['updated'] ?> updated, <?= $report['totalLines'] ?> item line(s) written in total.</p>
      <?php if ($report['droppedNoQty']): ?>
        <p class="muted"><?= $report['droppedNoQty'] ?> row(s) skipped — blank or zero quantity.</p>
      <?php endif; ?>
      <?php if ($report['blankRows']): ?>
        <p class="muted"><?= $report['blankRows'] ?> row(s) skipped — no <?= $type === 'card' ? 'stock card number' : 'GRN number' ?>.</p>
      <?php endif; ?>
      <?php if ($report['skippedItemNos']): ?>
        <p style="color:var(--amber);">Skipped unknown Item #(s): <?= h(implode(', ', $report['skippedItemNos'])) ?> — these don't exist in your Items list.</p>
      <?php endif; ?>
      <?php if ($report['failed']): ?>
        <p style="color:var(--red);font-weight:600;">The following <?= $type === 'card' ? 'card(s) need attention' : 'GRN(s) were not saved' ?>:</p>
        <ul style="margin-top:0;">
          <?php foreach ($report['failed'] as $key => $reason): ?>
            <li><strong><?= h($key) ?></strong> — <?= h($reason) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><h2>Upload a file</h2></div>
  <form method="post" enctype="multipart/form-data" action="import?type=<?= h($type) ?>" style="padding:22px;">
    <?= csrf_field() ?>
    <input type="hidden" name="import_type" value="<?= h($type) ?>">
    <?php if (is_admin()): ?>
      <div class="field">
        <label>Import into office</label>
        <?= office_select_field('office', $importOffice) ?>
      </div>
    <?php else: ?>
      <div class="field">
        <label>Import into office</label>
        <input type="text" value="<?= h(office_label(current_user()['Office'] ?? null)) ?>" disabled>
      </div>
    <?php endif; ?>
    <div class="field">
      <label>Project (new documents only)</label>
      <?= project_select_field('project_id', $importProjectRaw !== '' ? $importProjectRaw : 'none') ?>
      <div class="muted" style="font-size:12px;margin-top:4px;">Applied only to GRNs / stock cards this import creates. Documents that already exist keep the project they were created with — an import never moves stock between projects.</div>
    </div>
    <?php if ($type === 'card'): ?>
      <div class="field">
        <label>Storekeeper name (new cards only)</label>
        <input type="text" name="storekeeper_name" value="<?= h($importStorekeeper) ?>" placeholder="Person releasing the stock">
        <div class="muted" style="font-size:12px;margin-top:4px;">Applied only to cards this import creates. Cards that already exist keep the storekeeper name they were created with.</div>
      </div>
      <div class="field">
        <label>Printed subject (new cards only)</label>
        <input type="text" name="printed_subject" value="<?= h($importSubject) ?>" placeholder="Line printed at the top of the card">
        <div class="muted" style="font-size:12px;margin-top:4px;">Printed at the top of each card. Pre-filled with the last subject this office used (or the classic line). Applied only to cards this import creates — cards that already exist keep the subject they already have. To change an existing card's subject, open that card and edit it there.</div>
      </div>
    <?php endif; ?>
    <div class="field">
      <label>CSV or Excel (.xlsx) file</label>
      <input type="file" name="import_file" accept=".csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Import</button>
    </div>
  </form>
  <div style="padding:0 22px 22px;font-size:13px;color:var(--ink-soft);">
    <p><strong>Excel files:</strong> upload the <?= $type === 'card' ? '"Stock Cards Details"' : '"GRN Details"' ?> file this app exports from <?= $type === 'card' ? 'the Stock Cards page' : 'the GRN page' ?> — unmodified, or with quantities/dates/rows edited. Its "Items" sheet (the per-<?= $type === 'card' ? 'card' : 'GRN' ?> quantity matrix) supplies the item lines, and its list sheet supplies each <?= $type === 'card' ? "card's facility, date and receiver" : "GRN's date and supplier" ?>.</p>
    <?php if ($type === 'card'): ?>
      <p><strong>CSV files — expected columns</strong> (header row required): <code>StockCardNo</code>, <code>ReleaseDate</code>, <code>HFID</code> (or <code>HFName</code> as an exact-match fallback), <code>ReceiverName</code>, <code>Group</code> (optional — any free text, e.g. "Q1"), <code>Item #</code>, <code>Quantity</code>. <code>ItemName</code> and <code>Unit</code> columns are ignored if present — matching is by Item # only.</p>
      <p>One row per item line. Repeat the card number, date, facility, receiver and group on every row belonging to that card. Leave <code>ReceiverName</code> blank to auto-fill it from the facility's in-charge person. Leave <code>Group</code> blank for no group.</p>
      <p>Quantities that would exceed an item's current available balance are rejected line-by-line — the rest of the card still saves. If a stock card number already exists, its facility/date/receiver/group are updated and <strong>all of its item lines are replaced</strong> with the ones in the file.</p>
    <?php else: ?>
      <p><strong>CSV files — expected columns</strong> (header row required): <code>GRNNumber</code>, <code>ReceiptDate</code>, <code>Supplier</code>, <code>Group</code> (optional — any free text, e.g. "Q1"), <code>Item #</code>, <code>Quantity</code>. <code>ItemName</code> and <code>Unit</code> columns are ignored if present — matching is by Item # only.</p>
      <p>One row per item line. Repeat the GRN number, date, supplier and group on every row belonging to that GRN. Leave <code>Group</code> blank for no group.</p>
      <p>If a GRN number already exists, its date/supplier/group are updated and <strong>all of its item lines are replaced</strong> with the ones in the file — the same as editing it by hand. A new GRN number creates a new GRN.</p>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
