<?php
require __DIR__ . '/includes/functions.php';
require_login();
require __DIR__ . '/includes/xlsx_reader.php';

// Turns one parsed row + a header-name-to-column-index map into the fields
// this importer cares about, or null if the row has no item name (required).
function items_row_to_fields(array $row, array $keyMap): ?array {
    $get = function (string $key) use ($row, $keyMap) {
        $idx = $keyMap[$key] ?? null;
        return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
    };
    $name = $get('itemname');
    if ($name === '') return null;
    return [
        'item_id' => $get('itemid'),
        'name' => $name,
        'local' => $get('itemnamedari') ?: $get('itemnamelocal'),
        'desc' => $get('description'),
        'unit' => $get('unit'),
        'opening_in' => $get('openingqtyin'),
        'opening_out' => $get('openingqtyout'),
    ];
}

$error = null;
$report = null;

// Which office this import targets — staff always import into their own
// office; admin must explicitly choose one (there's no per-row office
// column in the file, so a single target office keeps this unambiguous and
// matches existing items only within that office, never across offices).
if (is_admin()) {
    $importOffice = $_POST['office'] ?? '';
    $importOffice = array_key_exists($importOffice, office_options()) ? $importOffice : null;
} else {
    $importOffice = current_user()['Office'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && !csrf_verify()) {
    $error = 'Your session expired — please choose the file and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && $importOffice === null) {
    $error = is_admin()
        ? 'Please choose which office to import these items into.'
        : 'Your account has no office assigned yet — contact an administrator.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    $records = [];
    $blankRows = 0;

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
        $error = 'No file was uploaded, or the upload failed. Please choose a .csv or .xlsx file and try again.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            // ==================== Parse a CSV file ====================
            $handle = fopen($file['tmp_name'], 'r');
            $headerRow = $handle ? fgetcsv($handle) : false;

            if (!$headerRow) {
                $error = 'The file is empty or not a readable CSV.';
            } else {
                $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]);
                $keyMap = [];
                foreach ($headerRow as $idx => $name) $keyMap[header_key((string)$name)] = $idx;

                if (!isset($keyMap['itemname'])) {
                    $error = 'Missing required column: ItemName.';
                } else {
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                        $fields = items_row_to_fields($row, $keyMap);
                        if ($fields === null) { $blankRows++; continue; }
                        $records[] = $fields;
                    }
                    fclose($handle);
                }
            }
        } elseif ($ext === 'xlsx') {
            // ==================== Parse an .xlsx file ====================
            try {
                $sheets = xlsx_read($file['tmp_name']);
            } catch (Throwable $e) {
                $error = 'Could not read this Excel file — ' . $e->getMessage() . '.';
                $sheets = null;
            }

            if ($sheets !== null) {
                // Accept any sheet whose header row has an Item Name column — works
                // with this app's own "Items" export (title row + header row) or a
                // plainer sheet that starts the header on row 1.
                $found = null;
                foreach ($sheets as $rows) {
                    $dataStart = (isset($rows[0][0]) && preg_match('/\(\d+\)/', (string)$rows[0][0])) ? 1 : 0;
                    $headerRow = $rows[$dataStart] ?? [];
                    $keyMap = [];
                    foreach ($headerRow as $idx => $hd) $keyMap[header_key((string)$hd)] = $idx;
                    if (isset($keyMap['itemname'])) {
                        $found = ['rows' => $rows, 'dataStart' => $dataStart + 1, 'keyMap' => $keyMap];
                        break;
                    }
                }

                if ($found === null) {
                    $error = 'No sheet with an "Item Name" column was found in this file.';
                } else {
                    for ($r = $found['dataStart']; $r < count($found['rows']); $r++) {
                        $row = $found['rows'][$r];
                        if (empty($row)) { $blankRows++; continue; }
                        $fields = items_row_to_fields($row, $found['keyMap']);
                        if ($fields === null) { $blankRows++; continue; }
                        $records[] = $fields;
                    }
                }
            }
        } else {
            $error = 'Unsupported file type — please upload a .csv or .xlsx file.';
        }
    }

    // ==================== Save $records — identical regardless of source format ====================
    if (!$error) {
        if (!$records) {
            $error = 'No item rows found in this file.';
        } else {
            $pdo = db();
            // Scoped to $importOffice so a row can only match/update an item
            // that's already in the target office — never one belonging to
            // the other office, even if the IDs or names happen to collide.
            $existingById = array_column(all_items($importOffice), null, 'ItemID');
            $existingByName = [];
            foreach ($existingById as $it) $existingByName[strtolower($it['ItemName'])] = (int)$it['ItemID'];

            $created = 0;
            $updated = 0;
            foreach ($records as $rec) {
                $itemId = ($rec['item_id'] !== '' && ctype_digit($rec['item_id'])) ? (int)$rec['item_id'] : null;
                if ($itemId !== null && !isset($existingById[$itemId])) $itemId = null;
                if ($itemId === null) {
                    $key = strtolower($rec['name']);
                    if (isset($existingByName[$key])) $itemId = $existingByName[$key];
                }

                $openingIn = $rec['opening_in'] !== '' ? (float)$rec['opening_in'] : 0;
                $openingOut = $rec['opening_out'] !== '' ? (float)$rec['opening_out'] : 0;

                if ($itemId !== null) {
                    $pdo->prepare('UPDATE Items SET ItemName=?, ItemNameLocal=?, Description=?, Unit=?, OpeningQtyIn=?, OpeningQtyOut=? WHERE ItemID=?')
                        ->execute([$rec['name'], $rec['local'], $rec['desc'], $rec['unit'], $openingIn, $openingOut, $itemId]);
                    $updated++;
                } else {
                    // Each new row gets the next code in this office's own
                    // sequence (e.g. "MO-05") — computed fresh per row so it
                    // correctly accounts for rows already inserted earlier in
                    // this same import.
                    $code = next_item_code($importOffice);
                    $pdo->prepare('INSERT INTO Items (ItemCode, ItemName, ItemNameLocal, Description, Unit, OpeningQtyIn, OpeningQtyOut, Office) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$code, $rec['name'], $rec['local'], $rec['desc'], $rec['unit'], $openingIn, $openingOut, $importOffice]);
                    $created++;
                    $existingByName[strtolower($rec['name'])] = (int)$pdo->lastInsertId();
                }
            }

            $report = ['created' => $created, 'updated' => $updated, 'blankRows' => $blankRows];
        }
    }
}

$pageTitle = 'Import Items';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Import Items</h1>
    <p>Bulk-create or update the item master list from a CSV or Excel file.</p>
  </div>
  <a class="btn secondary" href="items">&larr; Back to items</a>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<?php if ($report): ?>
  <div class="panel">
    <div class="panel-head"><h2>Import results</h2></div>
    <div style="padding:18px 22px;">
      <p><?= $report['created'] ?> item(s) created, <?= $report['updated'] ?> updated.</p>
      <?php if ($report['blankRows']): ?>
        <p class="muted"><?= $report['blankRows'] ?> row(s) skipped — no item name.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><h2>Upload a file</h2></div>
  <form method="post" enctype="multipart/form-data" action="import_items" style="padding:22px;">
    <?= csrf_field() ?>
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
      <label>CSV or Excel (.xlsx) file</label>
      <input type="file" name="import_file" accept=".csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Import</button>
    </div>
  </form>
  <div style="padding:0 22px 22px;font-size:13px;color:var(--ink-soft);">
    <p><strong>Excel files:</strong> upload the "Items" file this app exports from the Items page — unmodified, or with rows added/edited.</p>
    <p><strong>CSV files — expected columns</strong> (header row required): <code>ItemID</code> (optional — include it to update an existing item by ID), <code>ItemName</code> (required), <code>ItemNameLocal</code> / <code>ItemNameDari</code>, <code>Description</code>, <code>Unit</code>, <code>OpeningQtyIn</code>, <code>OpeningQtyOut</code>.</p>
    <p>Matching: a row with a valid <code>ItemID</code> updates that item. Otherwise, an exact (case-insensitive) match on <code>ItemName</code> updates that item. If neither matches, a new item is created. Updating replaces every field shown above on that item — leave a column blank only if you want to clear it.</p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
