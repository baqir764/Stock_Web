<?php
require __DIR__ . '/includes/functions.php';
require_login();
require __DIR__ . '/includes/xlsx_reader.php';

// Turns one parsed row + a header-name-to-column-index map into the fields
// this importer cares about, or null if the row has no facility name (required).
function hf_row_to_fields(array $row, array $keyMap): ?array {
    $get = function (string $key) use ($row, $keyMap) {
        $idx = $keyMap[$key] ?? null;
        return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
    };
    $name = $get('hfname');
    if ($name === '') return null;
    return [
        'hf_id' => $get('hfid'),
        'name' => $name,
        'province' => $get('province'),
        'district' => $get('district') ?: $get('districtname'),
        'type' => $get('hftype'),
        'incharge' => $get('inchargeperson'),
    ];
}

$error = null;
$report = null;

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
        ? 'Please choose which office to import these facilities into.'
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

                if (!isset($keyMap['hfname'])) {
                    $error = 'Missing required column: HFName.';
                } else {
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                        $fields = hf_row_to_fields($row, $keyMap);
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
                // Accept any sheet whose header row has an HF Name column — works
                // with this app's own "Health Facilities" export (title row +
                // header row) or a plainer sheet that starts the header on row 1.
                $found = null;
                foreach ($sheets as $rows) {
                    $dataStart = (isset($rows[0][0]) && preg_match('/\(\d+\)/', (string)$rows[0][0])) ? 1 : 0;
                    $headerRow = $rows[$dataStart] ?? [];
                    $keyMap = [];
                    foreach ($headerRow as $idx => $hd) $keyMap[header_key((string)$hd)] = $idx;
                    if (isset($keyMap['hfname'])) {
                        $found = ['rows' => $rows, 'dataStart' => $dataStart + 1, 'keyMap' => $keyMap];
                        break;
                    }
                }

                if ($found === null) {
                    $error = 'No sheet with an "HF Name" column was found in this file.';
                } else {
                    for ($r = $found['dataStart']; $r < count($found['rows']); $r++) {
                        $row = $found['rows'][$r];
                        if (empty($row)) { $blankRows++; continue; }
                        $fields = hf_row_to_fields($row, $found['keyMap']);
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
            $error = 'No facility rows found in this file.';
        } else {
            $pdo = db();
            $existingHfs = all_health_facilities($importOffice);
            $existingById = array_column($existingHfs, null, 'HFID');
            $existingByName = [];
            foreach ($existingHfs as $hf) $existingByName[strtolower($hf['HFName'])] = (int)$hf['HFID'];

            $districtByName = [];
            foreach (all_districts() as $d) $districtByName[strtolower($d['DistrictName'])] = (int)$d['DistrictID'];

            $created = 0;
            $updated = 0;
            $unknownDistricts = [];
            foreach ($records as $rec) {
                $hfId = ($rec['hf_id'] !== '' && ctype_digit($rec['hf_id'])) ? (int)$rec['hf_id'] : null;
                if ($hfId !== null && !isset($existingById[$hfId])) $hfId = null;
                if ($hfId === null) {
                    $key = strtolower($rec['name']);
                    if (isset($existingByName[$key])) $hfId = $existingByName[$key];
                }

                $districtId = null;
                if ($rec['district'] !== '') {
                    $dKey = strtolower($rec['district']);
                    if (isset($districtByName[$dKey])) {
                        $districtId = $districtByName[$dKey];
                    } else {
                        $unknownDistricts[] = $rec['district'];
                    }
                }

                $province = $rec['province'] !== '' ? $rec['province'] : null;

                if ($hfId !== null) {
                    $pdo->prepare('UPDATE HealthFacilities SET HFName=?, DistrictID=?, HFType=?, InChargePerson=?, Province=? WHERE HFID=?')
                        ->execute([$rec['name'], $districtId, $rec['type'], $rec['incharge'], $province, $hfId]);
                    $updated++;
                } else {
                    $pdo->prepare('INSERT INTO HealthFacilities (HFName, DistrictID, HFType, InChargePerson, Office, Province) VALUES (?,?,?,?,?,?)')
                        ->execute([$rec['name'], $districtId, $rec['type'], $rec['incharge'], $importOffice, $province]);
                    $created++;
                    $existingByName[strtolower($rec['name'])] = (int)$pdo->lastInsertId();
                }
            }

            $report = [
                'created' => $created,
                'updated' => $updated,
                'blankRows' => $blankRows,
                'unknownDistricts' => array_values(array_unique($unknownDistricts)),
            ];
        }
    }
}

$pageTitle = 'Import Health Facilities';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Import Health Facilities</h1>
    <p>Bulk-create or update health facilities from a CSV or Excel file.</p>
  </div>
  <a class="btn secondary" href="health_facilities">&larr; Back to facilities</a>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<?php if ($report): ?>
  <div class="panel">
    <div class="panel-head"><h2>Import results</h2></div>
    <div style="padding:18px 22px;">
      <p><?= $report['created'] ?> facility(ies) created, <?= $report['updated'] ?> updated.</p>
      <?php if ($report['blankRows']): ?>
        <p class="muted"><?= $report['blankRows'] ?> row(s) skipped — no facility name.</p>
      <?php endif; ?>
      <?php if ($report['unknownDistricts']): ?>
        <p style="color:var(--amber);">District left blank — no exact match for: <?= h(implode(', ', $report['unknownDistricts'])) ?>. Add these districts first, or fix the spelling, then re-import.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><h2>Upload a file</h2></div>
  <form method="post" enctype="multipart/form-data" action="import_hf" style="padding:22px;">
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
    <p><strong>Excel files:</strong> upload the "Health Facilities" file this app exports from the Health Facilities page — unmodified, or with rows added/edited.</p>
    <p><strong>CSV files — expected columns</strong> (header row required): <code>HFID</code> (optional — include it to update an existing facility by ID), <code>HFName</code> (required), <code>Province</code>, <code>District</code>, <code>HFType</code>, <code>InChargePerson</code>.</p>
    <p>Matching: a row with a valid <code>HFID</code> updates that facility. Otherwise, an exact (case-insensitive) match on <code>HFName</code> updates that facility. If neither matches, a new facility is created. <code>District</code> must exactly match an existing district name — an unmatched district is left blank and listed in the results above. Updating replaces every field shown above on that facility — leave a column blank only if you want to clear it.</p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
