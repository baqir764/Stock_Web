<?php
require __DIR__ . '/includes/functions.php';
require_login();

$receiptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$receipt = null;
$lines = [];

if ($receiptId > 0) {
    $receipt = db()->prepare('SELECT * FROM StockReceipts WHERE ReceiptID = ?');
    $receipt->execute([$receiptId]);
    $receipt = $receipt->fetch();

    if (!$receipt || !can_access_office($receipt['Office'] ?? null)) {
        flash_set("GRN not found.");
        header('Location: receipts');
        exit;
    }

    $lines = db()->prepare('
        SELECT rd.*, i.ItemName, i.ItemNameLocal, i.Description, i.Unit
        FROM StockReceiptDetails rd
        JOIN Items i ON i.ItemID = rd.ItemID
        WHERE rd.ReceiptID = ?
        ORDER BY rd.ItemID
    ');
    $lines->execute([$receiptId]);
    $lines = $lines->fetchAll();
}

// Which office this GRN belongs to (existing GRNs keep their office — it's
// not editable after creation, so an item list saved against one office
// never mixes with the other). For a new GRN, admin picks the office via
// the dropdown below (reloading the page with ?office=... to refresh the
// item list); staff always create into their own office.
if ($receipt) {
    $office = $receipt['Office'] ?? null;
} elseif (is_admin()) {
    $office = $_GET['office'] ?? '';
    $office = array_key_exists($office, office_options()) ? $office : null;
} else {
    $office = current_user()['Office'] ?? null;
}

// Which project this GRN is filed under. Like the office, it is fixed once
// the GRN exists — moving stock between projects later would silently rewrite
// history. A new GRN's project comes from the selector below (the page reloads
// with ?project=… so the item list only offers that project's items); on a
// validation redisplay the posted choice is kept.
$normProject = static function ($raw) {
    $raw = trim((string)$raw);
    if ($raw !== '' && $raw !== 'none' && ctype_digit($raw)) return (int)$raw;
    return 'none';
};
if ($receipt) {
    $project = ($receipt['ProjectID'] ?? null) === null ? 'none' : (int)$receipt['ProjectID'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ProjectID'])) {
    $project = $normProject($_POST['ProjectID']);
} else {
    $project = $normProject($_GET['project'] ?? 'none');
}

$items = $office !== null ? all_items($office, $project) : [];
// Every item of this office with its project, so a posted line from another
// project can be refused by name instead of quietly disappearing.
$officeItemProjects = array_column($office !== null ? all_items($office) : [], 'ProjectID', 'ItemID');

// Suggested default GRN number for a brand-new GRN — the field stays a
// plain editable text input, this just saves typing. On a POST redisplay
// (validation error), keep whatever the user actually typed rather than
// regenerating a fresh suggestion out from under them.
if ($receipt) {
    $defaultGrn = $receipt['GRNNumber'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['GRNNumber'])) {
    $defaultGrn = $_POST['GRNNumber'];
} elseif ($office !== null) {
    $defaultGrn = next_document_number('GRN', 'StockReceipts', 'GRNNumber', $office);
} else {
    $defaultGrn = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } elseif ($office === null) {
        $error = is_admin()
            ? 'Please choose which office this GRN belongs to.'
            : 'Your account has no office assigned yet — contact an administrator.';
    } else {
    $grn = trim($_POST['GRNNumber']);
    $date = trim($_POST['ReceiptDate']);
    $supplier = trim($_POST['Supplier'] ?? '');
    $group = trim($_POST['GroupName'] ?? '');
    if ($group === '') $group = null;
    $itemIds = $_POST['item_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];

    if ($grn === '') {
        $error = 'GRN number is required.';
    } else {
        $pdo = db();
        $me = current_user();
        $pdo->beginTransaction();
        try {
            if ($receiptId > 0) {
                $stmt = $pdo->prepare('UPDATE StockReceipts SET GRNNumber = ?, ReceiptDate = ?, Supplier = ?, GroupName = ?, UpdatedByUserID = ?, UpdatedAt = datetime(\'now\') WHERE ReceiptID = ?');
                $stmt->execute([$grn, $date, $supplier, $group, $me['UserID'], $receiptId]);
                $pdo->prepare('DELETE FROM StockReceiptDetails WHERE ReceiptID = ?')->execute([$receiptId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO StockReceipts (GRNNumber, ReceiptDate, Supplier, GroupName, Office, ProjectID, CreatedByUserID, CreatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))');
                $stmt->execute([$grn, $date, $supplier, $group, $office, $project === 'none' ? null : $project, $me['UserID']]);
                $receiptId = $pdo->lastInsertId();
            }

            $validIds = array_map('intval', array_column($items, 'ItemID'));
            $itemNames = array_column($office !== null ? all_items($office) : [], 'ItemName', 'ItemID');
            $lineStmt = $pdo->prepare('INSERT INTO StockReceiptDetails (ReceiptID, ItemID, Quantity) VALUES (?,?,?)');
            $count = 0;
            $skipped = [];
            $wrongProject = [];
            $droppedNoQty = 0;
            foreach ($itemIds as $i => $itemId) {
                $itemId = (int)$itemId;
                $qty = (float)($qtys[$i] ?? 0);
                if ($itemId <= 0) continue;
                if ($qty <= 0) {
                    $droppedNoQty++;
                    continue;
                }
                if (!in_array($itemId, $validIds, true)) {
                    // An item that exists in this office but lives in another
                    // project (or in "No project") must never sneak onto this
                    // GRN — that is what keeps each project's stock separate.
                    if (array_key_exists($itemId, $officeItemProjects)) {
                        $wrongProject[] = $itemNames[$itemId] ?? "Item $itemId";
                    } else {
                        $skipped[] = $itemId;
                    }
                    continue;
                }
                $lineStmt->execute([$receiptId, $itemId, $qty]);
                $count++;
            }

            if ($wrongProject) {
                throw new Exception('These items belong to a different project (or to "No project") and cannot be received on this GRN: ' . implode(', ', array_unique($wrongProject)) . '.');
            }
            if ($count === 0) throw new Exception('Add at least one item line with a valid Item # and quantity.');

            $pdo->commit();
            $msg = "GRN $grn " . ($receipt ? "updated" : "recorded") . " with $count item line(s).";
            if ($skipped) $msg .= ' Skipped ' . count(array_unique($skipped)) . ' unknown item line(s).';
            if ($droppedNoQty) $msg .= " $droppedNoQty line(s) removed because quantity was blank or 0.";
            flash_set($msg);
            header('Location: receipts');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
    }
}

// Map items for easy lookup in JS
$itemMap = [];
foreach ($items as $it) {
    $itemMap[$it['ItemID']] = [
        'name' => $it['ItemName'],
        'local' => $it['ItemNameLocal'],
        'desc' => $it['Description'],
        'unit' => $it['Unit']
    ];
}

$pageTitle = ($receiptId > 0) ? 'Edit GRN' : 'New GRN';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $receiptId > 0 ? 'Edit' : 'New' ?> GRN (Stock In)</h1>
    <p>Record goods received into the store.</p>
  </div>
  <?php if ($receiptId > 0): ?>
    <a class="btn secondary" href="receipt_view?id=<?= $receiptId ?>">&larr; Back to GRN details</a>
  <?php endif; ?>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form method="post" style="padding:22px;">
    <?= csrf_field() ?>
    <div class="field">
      <label>Office</label>
      <?php if ($receipt): ?>
        <input type="text" value="<?= h(office_label($office)) ?>" disabled>
      <?php elseif (is_admin()): ?>
        <select onchange="location.href='receipt_form?office='+this.value+'<?= $project !== 'none' ? ('&project=' . urlencode((string)$project)) : '&project=none' ?>'">
          <option value="">— Select office —</option>
          <?php foreach (office_options() as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $office === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="text" value="<?= h(office_label(current_user()['Office'] ?? null)) ?>" disabled>
      <?php endif; ?>
    </div>
    <div class="field">
      <label>Project</label>
      <?php if ($receipt): ?>
        <input type="text" value="<?= h(project_label($project === 'none' ? null : $project)) ?>" disabled title="Filed when this GRN was created — editing never moves it between projects">
        <div class="muted" style="font-size:12px;margin-top:4px;">Filed when this GRN was created — kept unchanged when the GRN is edited.</div>
      <?php else: ?>
        <select name="ProjectID" onchange="location.href='receipt_form?project='+this.value<?= $office !== null ? "+'&office=" . urlencode($office) . "'" : '' ?>">
          <?= project_options_html($project === 'none' ? 'none' : (string)$project) ?>
        </select>
        <div class="muted" style="font-size:12px;margin-top:4px;">Only this project's items can be received on this GRN — each project's stock stays completely separate. "No project" is where older stock lives.</div>
      <?php endif; ?>
    </div>

    <?php if (!$receipt && $office === null): ?>
      <div class="flash" style="background:var(--amber-tint);border-color:#EAD2A8;color:var(--amber);">Choose an office above to load its item list before adding line items.</div>
    <?php endif; ?>

    <div class="row4">
      <div class="field">
        <label>GRN number</label>
        <input type="text" name="GRNNumber" required placeholder="e.g. GRN-PO-2026-AUG-001" value="<?= h($defaultGrn) ?>">
      </div>
      <div class="field">
        <label>Receipt date</label>
        <input type="date" name="ReceiptDate" value="<?= h($receipt['ReceiptDate'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Supplier</label>
        <input type="text" name="Supplier" placeholder="e.g. WHO, UNICEF, Ministry" value="<?= h($receipt['Supplier'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Group</label>
        <input type="text" name="GroupName" placeholder="e.g. Q1" value="<?= h($receipt['GroupName'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label>Items received</label>
      <div class="line-table-wrap">
      <table class="line-table" id="lineTable">
        <thead>
          <tr>
            <th>Item</th>
            <th>Item Name (Dari)</th>
            <th>Description</th>
            <th>Unit</th>
            <th class="qty">Quantity</th>
            <th class="remove"></th>
            <th class="edit-col"></th>
          </tr>
        </thead>
        <tbody id="lineBody">
          <?php
          $displayLines = ($receiptId > 0) ? $lines : [[]];
          foreach ($displayLines as $line):
            $isExisting = !empty($line['ItemID']);
            $selectedId = $line['ItemID'] ?? '';
          ?>
          <tr>
            <td class="id-col">
              <select name="item_id[]" class="id-input" onchange="updateRowInfo(this)">
                <option value="">— Select item —</option>
                <?php foreach ($items as $it): ?>
                  <option value="<?= (int)$it['ItemID'] ?>" <?= ((string)$selectedId === (string)$it['ItemID']) ? 'selected' : '' ?>><?= h(($it['ItemCode'] ?? '') !== '' ? $it['ItemCode'] . ' — ' : '') ?><?= h($it['ItemName']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><span class="muted dari-label"><?= h($line['ItemNameLocal'] ?? '') ?></span></td>
            <td><span class="muted desc-label"><?= h($line['Description'] ?? '') ?></span></td>
            <td><span class="muted unit-label"><?= h($line['Unit'] ?? '') ?></span></td>
            <td class="qty"><input type="number" name="quantity[]" min="0" step="any" value="<?= h($line['Quantity'] ?? '') ?>"></td>
            <td class="remove"><button type="button" class="remove-row" onclick="removeRow(this)">&times;</button></td>
            <td class="edit-col"><a class="item-edit-link" href="item_form?id=<?= (int)($line['ItemID'] ?? 0) ?>" target="_blank" style="<?= $isExisting ? '' : 'display:none;' ?>">Edit more</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <button type="button" class="btn secondary small add-line" onclick="addRow()">+ Add item line</button>
    </div>

    <div class="actions">
      <button class="btn" type="submit">Save GRN</button>
      <a class="btn secondary" href="receipts">Cancel</a>
    </div>
  </form>
</div>

<script>
const itemMap = <?= json_encode($itemMap) ?>;
const template = document.querySelector('#lineBody tr').cloneNode(true);

function addRow(){
  const row = template.cloneNode(true);
  row.querySelectorAll('input').forEach(i => i.value = '');
  const idInput = row.querySelector('.id-input');
  idInput.value = '';
  idInput.title = '';
  row.querySelector('.dari-label').textContent = '';
  row.querySelector('.desc-label').textContent = '';
  row.querySelector('.unit-label').textContent = '';
  const editLink = row.querySelector('.item-edit-link');
  editLink.href = 'item_form?id=';
  editLink.style.display = 'none';
  document.getElementById('lineBody').appendChild(row);
}

function removeRow(btn){
  const body = document.getElementById('lineBody');
  if (body.rows.length > 1) btn.closest('tr').remove();
}

function updateRowInfo(select) {
  const row = select.closest('tr');
  const info = itemMap[select.value];
  const editLink = row.querySelector('.item-edit-link');
  row.querySelector('.dari-label').textContent = info ? info.local : '';
  row.querySelector('.desc-label').textContent = info ? info.desc : '';
  row.querySelector('.unit-label').textContent = info ? info.unit : '';
  // Full item name as a native tooltip — a backstop for the rare item name
  // still too long to show in full in the closed combo box.
  select.title = info ? info.name : '';
  if (info) {
    editLink.href = 'item_form?id=' + select.value;
    editLink.style.display = '';
  } else {
    editLink.style.display = 'none';
  }
}

// Existing lines (editing a GRN that already has items) start with a value
// already selected but never fire 'change', so their tooltip needs setting
// up front too — just the tooltip, not the rest of updateRowInfo(), so the
// server-rendered Dari/description/unit/edit-link for each line (which may
// reflect an item no longer in this office's active list) aren't clobbered.
document.querySelectorAll('.id-input').forEach(select => {
  const info = itemMap[select.value];
  if (info) select.title = info.name;
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
