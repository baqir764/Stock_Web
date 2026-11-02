<?php
require __DIR__ . '/includes/functions.php';
require_login();

$releaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$release = null;
$lines = [];

if ($releaseId > 0) {
    $release = db()->prepare('SELECT * FROM StockReleases WHERE ReleaseID = ?');
    $release->execute([$releaseId]);
    $release = $release->fetch();

    if (!$release || !can_access_office($release['Office'] ?? null)) {
        flash_set('Stock card not found.');
        header('Location: releases');
        exit;
    }

    $lines = db()->prepare('
        SELECT rd.*, i.ItemName, i.ItemNameLocal, i.Description, i.Unit
        FROM StockReleaseDetails rd
        JOIN Items i ON i.ItemID = rd.ItemID
        WHERE rd.ReleaseID = ?
        ORDER BY rd.ItemID
    ');
    $lines->execute([$releaseId]);
    $lines = $lines->fetchAll();
}

// Which office this stock card belongs to — see the identical comment in
// receipt_form.php; same rule applies here (fixed after creation, admin
// picks it up front for a new card via the dropdown below).
if ($release) {
    $office = $release['Office'] ?? null;
} elseif (is_admin()) {
    $office = $_GET['office'] ?? '';
    $office = array_key_exists($office, office_options()) ? $office : null;
} else {
    $office = current_user()['Office'] ?? null;
}

// Which project this stock card is filed under. Like the office and the
// storekeeper, it is fixed once the card exists — stock never moves between
// projects afterwards. A new card's project comes from the selector below
// (the page reloads with ?project=… so the item list, and therefore every
// balance check, only sees that project's items); on a validation redisplay
// the posted choice is kept.
$normProject = static function ($raw) {
    $raw = trim((string)$raw);
    if ($raw !== '' && $raw !== 'none' && ctype_digit($raw)) return (int)$raw;
    return 'none';
};
if ($release) {
    $project = ($release['ProjectID'] ?? null) === null ? 'none' : (int)$release['ProjectID'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ProjectID'])) {
    $project = $normProject($_POST['ProjectID']);
} else {
    $project = $normProject($_GET['project'] ?? 'none');
}

$items = $office !== null ? stock_balance($office, $project) : [];
// Every item of this office with its project and name, so a posted line from
// another project can be refused by name instead of quietly disappearing.
$officeItems = $office !== null ? stock_balance($office) : [];
$officeItemProjects = array_column($officeItems, 'ProjectID', 'ItemID');
$officeItemNames = array_column($officeItems, 'ItemName', 'ItemID');

// stock_balance() orders by ItemID (useful elsewhere), but the combo box
// below should list items alphabetically by name — a separate sorted copy
// keeps that ordering local to the dropdown without touching $items itself
// (which still drives $validIds / $itemMap / balance lookups below).
$itemsForSelect = $items;
usort($itemsForSelect, fn($a, $b) => strcasecmp($a['ItemName'] ?? '', $b['ItemName'] ?? ''));

// Suggested default Stock Card number for a brand-new card — see the
// identical comment in receipt_form.php; same reasoning applies here.
if ($release) {
    $defaultCardNo = $release['StockCardNo'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['StockCardNo'])) {
    $defaultCardNo = $_POST['StockCardNo'];
} elseif ($office !== null) {
    $defaultCardNo = next_document_number('STC', 'StockReleases', 'StockCardNo', $office);
} else {
    $defaultCardNo = '';
}

// Storekeeper name — the person recorded as releasing the stock. For a NEW
// card it's a plain editable field, pre-filled with the logged-in user's own
// name (they're the one issuing it), and they can type anyone else's name
// instead. For an EXISTING card the name recorded at creation time is shown
// read-only and is deliberately never written back by the UPDATE below — so
// editing/re-saving a card can never rewrite the storekeeper it already has,
// and the historical cards keep exactly the name they were created with.
$me = current_user();
if ($release) {
    $defaultStorekeeper = (string)($release['StorekeeperName'] ?? '');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['StorekeeperName'])) {
    // Validation redisplay — keep whatever was typed, not a fresh default.
    $defaultStorekeeper = trim((string)$_POST['StorekeeperName']);
} else {
    $defaultStorekeeper = trim((string)($me['FullName'] ?: $me['Username']));
}

// Printed subject line — the line printed at the top of the card. It's an
// editable field on BOTH new and existing cards, so the subject of an older
// card can be corrected later instead of being frozen at creation time.
//   • New card  — pre-filled with the last subject that office already used
//     (so a month's cards stay consistent), or the classic line if that
//     office has never used a custom one.
//   • Existing card — pre-filled with its own stored subject. Old cards from
//     before the Subject column existed have nothing stored, so they show the
//     classic line (release_subject() does that fallback), and saving writes
//     a real Subject for them from then on.
// Note this is the ONE field that differs from StorekeeperName above: the
// storekeeper stays forward-only, the subject is editable.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['Subject'])) {
    // Validation redisplay — keep whatever was typed, not a fresh default.
    // Checked before $release so a failed save on an EXISTING card shows the
    // text just typed rather than snapping back to the stored subject.
    $defaultSubject = trim((string)$_POST['Subject']);
} elseif ($release) {
    $defaultSubject = release_subject($release['Subject'] ?? null);
} elseif ($office !== null) {
    $defaultSubject = last_release_subject($office) ?? release_subject_classic();
} else {
    $defaultSubject = release_subject_classic();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } elseif ($office === null) {
        $error = is_admin()
            ? 'Please choose which office this stock card belongs to.'
            : 'Your account has no office assigned yet — contact an administrator.';
    } else {
    $cardNo = trim($_POST['StockCardNo']);
    $hfId = $_POST['HFID'] !== '' ? (int)$_POST['HFID'] : null;
    $receiverName = trim($_POST['ReceiverName']);
    $date = trim($_POST['ReleaseDate']);
    $group = trim($_POST['GroupName'] ?? '');
    if ($group === '') $group = null;
    $itemIds = $_POST['item_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];

    if ($cardNo === '') {
        $error = 'Stock card number is required.';
    } elseif ($hfId === null) {
        $error = 'Receiving facility is required.';
    } elseif (!in_array($hfId, array_map('intval', array_column(all_health_facilities($office), 'HFID')), true)) {
        // Health Facilities are split per office too — a submitted HFID must
        // belong to this stock card's own office, never the other one.
        $error = 'Please choose a facility from your own office.';
    } else {
        // Safety net: if Receiver name was left blank, default it to the selected
        // facility's in-charge person (mirrors the JS auto-fill on the form itself,
        // so the value is still correct even if that never ran — old cached page, JS off, etc).
        if ($receiverName === '') {
            $stmt = db()->prepare('SELECT InChargePerson FROM HealthFacilities WHERE HFID = ?');
            $stmt->execute([$hfId]);
            $receiverName = trim((string)($stmt->fetchColumn() ?: ''));
        }
        $pdo = db();
        $me = current_user();

        // Printed subject — the editable form field, for new AND existing
        // cards. Blank (or whitespace only) always falls back to the classic
        // line, so a card never ends up printing an empty subject. Old cards
        // with no stored Subject get a real one the first time they're saved.
        if (array_key_exists('Subject', $_POST)) {
            $subject = trim((string)$_POST['Subject']);
            if ($subject === '') {
                $subject = release_subject_classic();
            }
        } else {
            // The field wasn't submitted at all — e.g. a stale cached copy of
            // this page from before the subject box became editable. Keep
            // whatever the card already has instead of resetting it.
            $subject = $release
                ? release_subject($release['Subject'] ?? null)
                : release_subject_classic();
        }

        $pdo->beginTransaction();
        try {
            if ($releaseId > 0) {
                // Note: StorekeeperName is deliberately NOT part of this
                // UPDATE — the name recorded when the card was created stays
                // with it forever. Subject IS included: it's an editable field
                // on this form for existing and old cards too.
                $stmt = $pdo->prepare('UPDATE StockReleases SET StockCardNo = ?, ReceiverName = ?, HFID = ?, ReleaseDate = ?, GroupName = ?, Subject = ?, UpdatedByUserID = ?, UpdatedAt = datetime(\'now\') WHERE ReleaseID = ?');
                $stmt->execute([$cardNo, $receiverName, $hfId, $date, $group, $subject, $me['UserID'], $releaseId]);
                $pdo->prepare('DELETE FROM StockReleaseDetails WHERE ReleaseID = ?')->execute([$releaseId]);
            } else {
                // New card — the storekeeper comes from the editable form field;
                // if it was somehow left blank, fall back to the issuing user's name.
                $storekeeperName = trim((string)($_POST['StorekeeperName'] ?? ''));
                if ($storekeeperName === '') {
                    $storekeeperName = trim((string)($me['FullName'] ?: $me['Username']));
                }
                $stmt = $pdo->prepare('INSERT INTO StockReleases (StockCardNo, ReceiverName, HFID, StorekeeperName, Subject, ReleaseDate, GroupName, Office, ProjectID, CreatedByUserID, CreatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))');
                $stmt->execute([$cardNo, $receiverName, $hfId, $storekeeperName, $subject, $date, $group, $office, $project === 'none' ? null : $project, $me['UserID']]);
                $releaseId = $pdo->lastInsertId();
            }

            $validIds = array_map('intval', array_column($items, 'ItemID'));
            $itemNames = array_column($items, 'ItemName', 'ItemID');
            $lineStmt = $pdo->prepare('INSERT INTO StockReleaseDetails (ReleaseID, ItemID, Quantity) VALUES (?,?,?)');
            $count = 0;
            $skipped = [];
            $wrongProject = [];
            $droppedNoQty = 0;
            $overLimit = [];
            $availByItem = []; // fresh, live balance per item — queried AFTER this release's old
                                // lines were deleted above, so it already correctly excludes them.
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
                    // project (or in "No project") must never be issued on this
                    // card — that refusal is what keeps each project's stock
                    // completely separate, even against a hand-crafted POST.
                    if (array_key_exists($itemId, $officeItemProjects)) {
                        $wrongProject[] = $officeItemNames[$itemId] ?? "Item $itemId";
                    } else {
                        $skipped[] = $itemId;
                    }
                    continue;
                }
                if (!array_key_exists($itemId, $availByItem)) {
                    $availByItem[$itemId] = item_balance($itemId);
                }
                if ($qty > $availByItem[$itemId]) {
                    $name = $itemNames[$itemId] ?? "Item $itemId";
                    $overLimit[] = sprintf('%s (needs %s, only %s available)', $name, rtrim(rtrim(number_format($qty, 2), '0'), '.'), rtrim(rtrim(number_format($availByItem[$itemId], 2), '0'), '.'));
                    continue;
                }
                $lineStmt->execute([$releaseId, $itemId, $qty]);
                $availByItem[$itemId] -= $qty;
                $count++;
            }

            if ($wrongProject) {
                throw new Exception('These items belong to a different project (or to "No project") and cannot be issued on this stock card: ' . implode(', ', array_unique($wrongProject)) . '.');
            }
            if ($count === 0) throw new Exception('Add at least one item line with a valid Item ID and quantity, within the available balance.');

            $pdo->commit();
            $msg = "Stock card $cardNo " . ($release ? 'updated' : 'recorded') . " with $count item line(s).";
            if ($skipped) $msg .= ' Skipped unknown Item ID(s): ' . implode(', ', array_unique($skipped)) . '.';
            if ($droppedNoQty) $msg .= " $droppedNoQty line(s) removed because quantity was blank or 0.";
            if ($overLimit) $msg .= ' Rejected — exceeds available balance: ' . implode('; ', $overLimit) . '.';
            flash_set($msg);
            header('Location: releases');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
    }
}

$hfs = $office !== null ? all_health_facilities($office) : [];

// Map items for easy lookup in JS
$itemMap = [];
foreach ($items as $it) {
    $itemMap[$it['ItemID']] = [
        'name' => $it['ItemName'],
        'local' => $it['ItemNameLocal'],
        'desc' => $it['Description'],
        'unit' => $it['Unit'],
        'balance' => $it['CurrentBalance']
    ];
}
$balanceMap = array_column($items, 'CurrentBalance', 'ItemID');

// Map facilities to their in-charge person for easy lookup in JS
$hfMap = [];
foreach ($hfs as $hf) {
    $hfMap[$hf['HFID']] = $hf['InChargePerson'] ?? '';
}

$pageTitle = ($releaseId > 0) ? 'Edit stock card' : 'New stock card';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $releaseId > 0 ? 'Edit' : 'New' ?> Stock Release Card</h1>
    <p>Issue items to a health facility or department. Quantity cannot exceed the available balance for each item.</p>
  </div>
  <?php if ($releaseId > 0): ?>
    <a class="btn secondary" href="release_view?id=<?= $releaseId ?>">&larr; Back to stock card details</a>
  <?php endif; ?>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form method="post" style="padding:22px;">
    <?= csrf_field() ?>
    <div class="field">
      <label>Office</label>
      <?php if ($release): ?>
        <input type="text" value="<?= h(office_label($office)) ?>" disabled>
      <?php elseif (is_admin()): ?>
        <select onchange="location.href='release_form?office='+this.value+'<?= $project !== 'none' ? ('&project=' . urlencode((string)$project)) : '&project=none' ?>'">
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
      <?php if ($release): ?>
        <input type="text" value="<?= h(project_label($project === 'none' ? null : $project)) ?>" disabled title="Filed when this card was created — editing never moves it between projects">
        <div class="muted" style="font-size:12px;margin-top:4px;">Filed when this card was created — kept unchanged when the card is edited.</div>
      <?php else: ?>
        <select name="ProjectID" onchange="location.href='release_form?project='+this.value<?= $office !== null ? "+'&office=" . urlencode($office) . "'" : '' ?>">
          <?= project_options_html($project === 'none' ? 'none' : (string)$project) ?>
        </select>
        <div class="muted" style="font-size:12px;margin-top:4px;">Only this project's items can be issued on this card, and only its own stock is touched — each project stays completely separate. "No project" is where older stock lives.</div>
      <?php endif; ?>
    </div>

    <?php if (!$release && $office === null): ?>
      <div class="flash" style="background:var(--amber-tint);border-color:#EAD2A8;color:var(--amber);">Choose an office above to load its item and facility lists before adding line items.</div>
    <?php endif; ?>

    <div class="row3">
      <div class="field">
        <label>Stock card number</label>
        <input type="text" name="StockCardNo" required placeholder="e.g. STC-PO-2026-AUG-001" value="<?= h($defaultCardNo) ?>">
      </div>
      <div class="field">
        <label>Release date</label>
        <input type="date" name="ReleaseDate" value="<?= h($release['ReleaseDate'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Group</label>
        <input type="text" name="GroupName" placeholder="e.g. Q1" value="<?= h($release['GroupName'] ?? '') ?>">
      </div>
    </div>
    <div class="row3">
      <div class="field">
        <label>Receiving facility</label>
        <select name="HFID" required onchange="fillReceiverName(this)">
          <option value="">— Select facility —</option>
          <?php foreach ($hfs as $hf): ?>
            <option value="<?= $hf['HFID'] ?>" <?= (isset($release['HFID']) && $release['HFID'] == $hf['HFID']) ? 'selected' : '' ?>><?= h($hf['HFName']) ?> — <?= h($hf['DistrictName']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Receiver name</label>
        <input type="text" id="receiverNameInput" name="ReceiverName" placeholder="Person collecting the items — auto-filled from the facility's in-charge person" value="<?= h($release['ReceiverName'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Storekeeper name</label>
        <?php if ($release): ?>
          <input type="text" value="<?= h($release['StorekeeperName'] ?: '') ?>" disabled title="Recorded when this card was created — editing a card never changes its storekeeper">
          <div class="muted" style="font-size:12px;margin-top:4px;">Recorded when this card was created — kept unchanged when the card is edited.</div>
        <?php else: ?>
          <input type="text" name="StorekeeperName" required placeholder="Person releasing the stock" value="<?= h($defaultStorekeeper) ?>">
        <?php endif; ?>
      </div>
    </div>

    <div class="field">
      <label>Printed subject</label>
      <!-- Editable on new AND existing cards, including old ones created
           before per-card subjects existed (those show the classic line,
           from release_subject()). Only StorekeeperName above stays
           read-only once a card exists. -->
      <input type="text" name="Subject" placeholder="e.g. <?= h(release_subject_classic()) ?>" value="<?= h($defaultSubject) ?>">
      <?php if ($release): ?>
        <div class="muted" style="font-size:12px;margin-top:4px;">Printed at the top of this card and shown on its details page. You can change it here — leave it blank to use the classic line.</div>
      <?php else: ?>
        <div class="muted" style="font-size:12px;margin-top:4px;">Printed at the top of the card. Pre-filled with the last subject this office used (or the classic line) — change it for this card only.</div>
      <?php endif; ?>
    </div>

    <div class="field">
      <label>Items issued</label>
      <div class="line-table-wrap">
      <table class="line-table" id="lineTable">
        <thead>
          <tr>
            <th>Item</th>
            <th>Item Name (Dari)</th>
            <th>Description</th>
            <th>Unit</th>
            <th class="num">Balance</th>
            <th class="qty">Quantity</th>
            <th class="remove"></th>
            <th class="edit-col"></th>
          </tr>
        </thead>
        <tbody id="lineBody">
          <?php
          $displayLines = ($releaseId > 0) ? $lines : [[]];
          foreach ($displayLines as $line):
            $isExisting = !empty($line['ItemID']);
            $selectedId = $line['ItemID'] ?? '';
            $lineBalance = $isExisting ? ($balanceMap[$line['ItemID']] ?? 0) : null;
            // Ceiling for THIS line = current balance (which already excludes this line's own
            // quantity, since it's still counted as released) + this line's own current quantity.
            // That's the true max you could set this line to without over-issuing.
            $lineCeiling = $isExisting ? ($lineBalance + (float)($line['Quantity'] ?? 0)) : '';
          ?>
          <tr>
            <td class="id-col">
              <select name="item_id[]" class="id-input" onchange="updateRowInfo(this)">
                <option value="">— Select item —</option>
                <?php foreach ($itemsForSelect as $it): ?>
                  <option value="<?= (int)$it['ItemID'] ?>" <?= ((string)$selectedId === (string)$it['ItemID']) ? 'selected' : '' ?>><?= h($it['ItemName']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><span class="muted dari-label"><?= h($line['ItemNameLocal'] ?? '') ?></span></td>
            <td><span class="muted desc-label"><?= h($line['Description'] ?? '') ?></span></td>
            <td><span class="muted unit-label"><?= h($line['Unit'] ?? '') ?></span></td>
            <td class="num"><span class="muted balance-label"><?= $isExisting ? number_format($lineBalance) : '' ?></span></td>
            <td class="qty"><input type="number" name="quantity[]" min="0" step="any" value="<?= h($line['Quantity'] ?? '') ?>" data-ceiling="<?= h((string)$lineCeiling) ?>" oninput="checkQtyLimit(this)" title="Cannot exceed the available balance for this item"></td>
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
      <button class="btn" type="submit">Save stock card</button>
      <a class="btn secondary" href="releases">Cancel</a>
    </div>
  </form>
</div>

<script>
const itemMap = <?= json_encode($itemMap) ?>;
const hfMap = <?= json_encode($hfMap) ?>;
const template = document.querySelector('#lineBody tr').cloneNode(true);

function fillReceiverName(select) {
  const person = hfMap[select.value];
  if (person) document.getElementById('receiverNameInput').value = person;
}

// On page load (e.g. editing an existing card that already has a facility
// selected), the <select> never fires a 'change' event on its own, so the
// fill above never ran for it. If a facility is already chosen and the
// receiver name box is still empty, fill it in now the same way.
(function () {
  const select = document.querySelector('select[name="HFID"]');
  const input = document.getElementById('receiverNameInput');
  if (select && input && select.value && !input.value) {
    fillReceiverName(select);
  }
})();

function addRow(){
  const row = template.cloneNode(true);
  row.querySelectorAll('input').forEach(i => i.value = '');
  const idInput = row.querySelector('.id-input');
  idInput.value = '';
  idInput.title = '';
  row.querySelector('.dari-label').textContent = '';
  row.querySelector('.desc-label').textContent = '';
  row.querySelector('.unit-label').textContent = '';
  row.querySelector('.balance-label').textContent = '';
  const qtyInput = row.querySelector('input[name="quantity[]"]');
  qtyInput.dataset.ceiling = '';
  qtyInput.setCustomValidity('');
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
  const qtyInput = row.querySelector('input[name="quantity[]"]');
  row.querySelector('.dari-label').textContent = info ? info.local : '';
  row.querySelector('.desc-label').textContent = info ? info.desc : '';
  row.querySelector('.unit-label').textContent = info ? info.unit : '';
  row.querySelector('.balance-label').textContent = info ? Number(info.balance).toLocaleString() : '';
  // For a newly-picked item on a new line, there's no pre-existing quantity to add back —
  // the ceiling is simply the item's current available balance.
  qtyInput.dataset.ceiling = info ? info.balance : '';
  checkQtyLimit(qtyInput);
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

// Existing lines (editing a stock card that already has items) start with a
// value already selected but never fire 'change', so their tooltip needs
// setting up front too — just the tooltip, not the rest of updateRowInfo(),
// since that would also recompute this line's quantity ceiling from the
// item's current balance alone and lose the "+ this line's own quantity"
// adjustment the server already baked into data-ceiling for existing lines.
document.querySelectorAll('.id-input').forEach(select => {
  const info = itemMap[select.value];
  if (info) select.title = info.name;
});

function checkQtyLimit(input) {
  const ceilingRaw = input.dataset.ceiling;
  if (ceilingRaw === undefined || ceilingRaw === '') {
    input.setCustomValidity('');
    return;
  }
  const ceiling = parseFloat(ceilingRaw);
  const val = parseFloat(input.value);
  if (!isNaN(val) && val > ceiling) {
    input.setCustomValidity('Only ' + ceiling.toLocaleString() + ' available for this item — reduce the quantity.');
  } else {
    input.setCustomValidity('');
  }
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
