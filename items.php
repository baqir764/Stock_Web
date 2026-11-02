<?php
require __DIR__ . '/includes/functions.php';
require_login();

// office_scope() resolves once here and is reused for the whole request:
// on a GET it reads the admin's ?office= filter (or the staff member's own
// office); on a POST it reads the same via a hidden "office" field so the
// delete/empty-all handlers below act on exactly the office the list was
// showing when the button was clicked.
$scope = office_scope();
// Projects cut across offices: the list can be narrowed to one project, or to
// the "No project" group where everything from before projects lives.
$project = project_scope();
$category = category_scope();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: items');
        exit;
    }
    $id = (int)$_POST['delete_item'];
    $itemRow = db()->prepare('SELECT Office FROM Items WHERE ItemID = ?');
    $itemRow->execute([$id]);
    $itemRow = $itemRow->fetch();
    if (!$itemRow || !can_access_office($itemRow['Office'])) {
        flash_set('Item not found.');
        header('Location: items');
        exit;
    }
    $inUse = db()->prepare('SELECT
        (SELECT COUNT(*) FROM StockReceiptDetails WHERE ItemID = ?) +
        (SELECT COUNT(*) FROM StockReleaseDetails WHERE ItemID = ?) AS c');
    $inUse->execute([$id, $id]);
    if ($inUse->fetch()['c'] > 0) {
        flash_set('Cannot delete: this item has receipt/release history. Consider leaving it in the list instead.');
    } else {
        db()->prepare('DELETE FROM Items WHERE ItemID = ?')->execute([$id]);
        flash_set('Item deleted.');
    }
    header('Location: items');
    exit;
}

// One-click "empty all" — admin-only. Permanently wipes every item
// belonging to whichever office an admin has filtered to, including every
// receipt/release line that references any of those items. Requires a
// concrete office — an admin viewing the combined "All offices" list must
// pick one office first, so this can never wipe both offices' data in one
// click. There is no undo; the confirm dialog on the button spells that out
// before this ever runs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empty_all_items'])) {
    if (!is_admin()) {
        flash_set('That action is for admin accounts only.');
        header('Location: items');
        exit;
    }
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: items');
        exit;
    }
    if ($scope === null) {
        flash_set('Select a specific office filter before emptying its item list.');
        header('Location: items');
        exit;
    }
    if (project_scope() !== '__all__' || category_scope() !== '__all__') {
        // The button spells out which OFFICE it empties; silently also
        // honouring a project or category filter would delete less than it
        // promises, and silently ignoring it would delete more than what is
        // on screen.
        flash_set('Clear the project and category filters first — "Empty items" always works on a whole office.');
        header('Location: items');
        exit;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $idStmt = $pdo->prepare('SELECT ItemID FROM Items WHERE Office = ?');
        $idStmt->execute([$scope]);
        $itemIds = array_column($idStmt->fetchAll(), 'ItemID');
        $itemCount = count($itemIds);
        if ($itemCount > 0) {
            $placeholders = implode(',', array_fill(0, $itemCount, '?'));
            $pdo->prepare("DELETE FROM StockReceiptDetails WHERE ItemID IN ($placeholders)")->execute($itemIds);
            $pdo->prepare("DELETE FROM StockReleaseDetails WHERE ItemID IN ($placeholders)")->execute($itemIds);
            $pdo->prepare('DELETE FROM Items WHERE Office = ?')->execute([$scope]);
        }
        $pdo->commit();
        flash_set($itemCount > 0
            ? "All $itemCount item(s) in " . office_label($scope) . " were permanently deleted, along with their receipt/release history."
            : 'There were no items to delete for ' . office_label($scope) . '.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Could not empty the items list: ' . $e->getMessage());
    }
    header('Location: items');
    exit;
}

$pageTitle = 'Items';
require __DIR__ . '/includes/header.php';

// Sorted by the single public Item # (P1-MO-0001 … NP-PO-0090) rather than
// by name or by the hidden internal ItemID. The sequence is zero-padded, so
// a plain string sort is already the correct numeric order.
$codeOrder = 'i.ItemCode';
[$pSql, $pParams] = project_condition('i.ProjectID', $project);
[$cSql, $cParams] = category_condition('i.CategoryID', $category);

if ($scope === '__unassigned__') {
    $items = [];
} elseif ($scope !== null) {
    $items = db()->prepare("
        SELECT i.*,
          COALESCE((SELECT SUM(Quantity) FROM StockReceiptDetails WHERE ItemID=i.ItemID),0)
          - COALESCE((SELECT SUM(Quantity) FROM StockReleaseDetails WHERE ItemID=i.ItemID),0) AS Balance
        FROM Items i WHERE i.Office = ?$pSql$cSql ORDER BY $codeOrder
    ");
    $items->execute(array_merge([$scope], $pParams, $cParams));
    $items = $items->fetchAll();
} elseif ($pSql !== '' || $cSql !== '') {
    $items = db()->prepare("
        SELECT i.*,
          COALESCE((SELECT SUM(Quantity) FROM StockReceiptDetails WHERE ItemID=i.ItemID),0)
          - COALESCE((SELECT SUM(Quantity) FROM StockReleaseDetails WHERE ItemID=i.ItemID),0) AS Balance
        FROM Items i WHERE 1=1$pSql$cSql ORDER BY $codeOrder
    ");
    $items->execute(array_merge($pParams, $cParams));
    $items = $items->fetchAll();
} else {
    $items = db()->query("
        SELECT i.*,
          COALESCE((SELECT SUM(Quantity) FROM StockReceiptDetails WHERE ItemID=i.ItemID),0)
          - COALESCE((SELECT SUM(Quantity) FROM StockReleaseDetails WHERE ItemID=i.ItemID),0) AS Balance
        FROM Items i ORDER BY $codeOrder
    ")->fetchAll();
}

// Status per item: compares its balance against a fixed low-stock threshold
// that applies to every item (no more per-item low-stock level).
const LOW_STOCK_THRESHOLD = 5;
function item_stock_status(array $it): array {
    $balance = (float)$it['Balance'];
    if ($balance <= 0) return ['Out of Stock', 'low'];
    if ($balance <= LOW_STOCK_THRESHOLD) return ['Low Stock', 'low'];
    return ['In Stock', 'ok'];
}
?>
<div class="page-head">
  <div>
    <h1>Items</h1>
    <p><?= count($items) ?> items <?= is_admin() ? h($scope === null ? 'across all offices' : ('in ' . office_label($scope))) : '' ?><?= $project !== '__all__' ? h($project === 'none' ? ' with no project' : (' in "' . project_name($project) . '"')) : '' ?><?= $category !== '__all__' ? h($category === 'none' ? ' with no category' : (' in category "' . category_name($category) . '"')) : '' ?>.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (is_admin()): ?>
      <select id="officeFilter" class="search-box" style="min-width:170px;" onchange="applyItemFilters()">
        <option value="">All offices (combined)</option>
        <?php foreach (office_options() as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $scope === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select id="projectFilter" class="search-box" style="min-width:170px;" onchange="applyItemFilters()" title="Show only one project's items">
      <?= project_options_html($project === '__all__' ? '' : $project, true) ?>
    </select>
    <select id="categoryFilter" class="search-box" style="min-width:170px;" onchange="applyItemFilters()" title="Show only one category's items">
      <?= category_options_html($category === '__all__' ? '' : $category, true) ?>
    </select>
    <a class="btn secondary" href="export_items<?= $scope !== null ? ('?office=' . urlencode($scope)) : '' ?>" title="Export the item master list to Excel">Export to Excel</a>
    <a class="btn secondary" href="import_items">Import Excel</a>
    <a class="btn" href="item_form<?= $project !== '__all__' ? ('?project=' . urlencode((string)$project)) : '' ?><?= $category !== '__all__' ? ($project !== '__all__' ? '&' : '?') . 'category=' . urlencode((string)$category) : '' ?>">+ New item</a>
    <?php if (is_admin() && $items && $scope !== null && $scope !== '__unassigned__'): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete ALL <?= count($items) ?> item(s) in <?= h(office_label($scope)) ?>? This also deletes their entire GRN and Stock Card history and cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="office" value="<?= h($scope) ?>">
        <button type="submit" name="empty_all_items" value="1" class="btn danger">Empty <?= h(office_label($scope)) ?> items</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($scope === '__unassigned__'): ?>
  <div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);">Your account has no office assigned yet — contact an administrator to assign you to the Provincial Office or Main Office.</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h2>All items</h2>
    <input class="search-box" id="itemSearch" placeholder="Search items…" onkeyup="filterTable('itemSearch','itemTable')">
  </div>
  <table id="itemTable">
    <thead><tr><th>Item #</th><th>Item</th><th>Description</th><th>Unit</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th>Project</th><th>Category</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <?php [$statusLabel, $statusClass] = item_stock_status($it); ?>
        <tr>
          <td><?= h($it['ItemCode'] ?? '') ?></td>
          <td><?= h($it['ItemName']) ?><?php if($it['ItemNameLocal']): ?><div class="muted"><?= h($it['ItemNameLocal']) ?></div><?php endif; ?></td>
          <td class="muted"><?= h($it['Description']) ?></td>
          <td><?= h($it['Unit']) ?></td>
          <?php if (is_admin()): ?><td class="muted"><?= h(office_label($it['Office'] ?? null)) ?></td><?php endif; ?>
          <td class="muted"><?= h(project_label($it['ProjectID'] ?? null)) ?></td>
          <td class="muted"><?= h(category_label($it['CategoryID'] ?? null)) ?></td>
          <td class="num"><?= number_format($it['Balance']) ?></td>
          <td><span class="pill <?= $statusClass ?>"><?= h($statusLabel) ?></span></td>
          <td>
            <a class="btn secondary small" href="item_form?id=<?= $it['ItemID'] ?>">Edit</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this item?')">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_item" value="<?= $it['ItemID'] ?>">
              <button type="submit" class="btn danger small">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
// Office + project filter bars navigate together, so choosing one never
// silently drops the other.
function applyItemFilters(){
  const parts = [];
  const office = document.getElementById('officeFilter');
  const project = document.getElementById('projectFilter');
  const category = document.getElementById('categoryFilter');
  if (office && office.value) parts.push('office=' + encodeURIComponent(office.value));
  if (project && project.value) parts.push('project=' + encodeURIComponent(project.value));
  if (category && category.value) parts.push('category=' + encodeURIComponent(category.value));
  location.href = 'items' + (parts.length ? '?' + parts.join('&') : '');
}
function filterTable(inputId, tableId){
  const q = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
