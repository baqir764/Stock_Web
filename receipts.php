<?php
require __DIR__ . '/includes/functions.php';
require_login();

$scope = office_scope();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_receipt'])) {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: receipts');
        exit;
    }
    $id = (int)$_POST['delete_receipt'];
    $row = db()->prepare('SELECT Office FROM StockReceipts WHERE ReceiptID = ?');
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row || !can_access_office($row['Office'] ?? null)) {
        flash_set('GRN not found.');
        header('Location: receipts');
        exit;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM StockReceiptDetails WHERE ReceiptID = ?')->execute([$id]);
        $stmt = $pdo->prepare('DELETE FROM StockReceipts WHERE ReceiptID = ?');
        $stmt->execute([$id]);
        $pdo->commit();
        flash_set($stmt->rowCount() > 0 ? 'GRN and all its item lines were deleted.' : 'GRN not found.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Could not delete GRN: ' . $e->getMessage());
    }
    header('Location: receipts');
    exit;
}

$pageTitle = 'Stock In';
require __DIR__ . '/includes/header.php';

$project = project_scope();
[$pSql, $pParams] = project_condition('r.ProjectID', $project);

if ($scope === '__unassigned__') {
    $receipts = [];
} elseif ($scope !== null) {
    $receipts = db()->prepare('
        SELECT r.*, (SELECT COUNT(*) FROM StockReceiptDetails WHERE ReceiptID=r.ReceiptID) AS lines
        FROM StockReceipts r WHERE r.Office = ?' . $pSql . '
    ');
    $receipts->execute(array_merge([$scope], $pParams));
    $receipts = $receipts->fetchAll();
} elseif ($pSql !== '') {
    $receipts = db()->prepare('
        SELECT r.*, (SELECT COUNT(*) FROM StockReceiptDetails WHERE ReceiptID=r.ReceiptID) AS lines
        FROM StockReceipts r WHERE 1=1' . $pSql . '
    ');
    $receipts->execute($pParams);
    $receipts = $receipts->fetchAll();
} else {
    $receipts = db()->query('
        SELECT r.*, (SELECT COUNT(*) FROM StockReceiptDetails WHERE ReceiptID=r.ReceiptID) AS lines
        FROM StockReceipts r
    ')->fetchAll();
}

// Natural sort (number-aware) on the GRN number itself, so "GRN-2" sorts
// before "GRN-10" instead of the plain-text order SQL would otherwise give,
// and so the list stays in GRN-number order even if GRNs were entered or
// imported out of sequence.
usort($receipts, fn($a, $b) => strnatcmp($a['ReceiptDate'] ?? '', $b['ReceiptDate'] ?? ''));
?>
<div class="page-head">
  <div>
    <h1>Stock In (GRN)</h1>
    <p>Goods Received Notes — items entering the store<?= is_admin() ? h($scope === null ? ' (all offices)' : (' — ' . office_label($scope))) : '' ?>.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (is_admin()): ?>
      <select id="officeFilter" class="search-box" style="min-width:170px;" onchange="applyReceiptFilters()">
        <option value="">All offices (combined)</option>
        <?php foreach (office_options() as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $scope === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select id="projectFilter" class="search-box" style="min-width:170px;" onchange="applyReceiptFilters()" title="Show only one project's GRNs">
      <?= project_options_html($project === '__all__' ? '' : $project, true) ?>
    </select>
    <a class="btn secondary" href="export_excel?type=grn<?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?>" title="GRN list with per-GRN item quantities">Export to Excel</a>
    <a class="btn secondary" href="import?type=grn">Import Excel</a>
    <a class="btn" href="receipt_form<?= $scope !== null && $scope !== '__unassigned__' ? ('?office=' . urlencode($scope)) : '' ?><?= $project !== '__all__' ? (($scope !== null && $scope !== '__unassigned__') ? '&' : '?') . 'project=' . urlencode((string)$project) : '' ?>">+ New GRN</a>
  </div>
</div>

<?php if ($scope === '__unassigned__'): ?>
  <div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);">Your account has no office assigned yet — contact an administrator to assign you to the Provincial Office or Main Office.</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h2>All GRNs</h2>
    <input class="search-box" id="grnSearch" placeholder="Search GRN #, supplier or group…" onkeyup="filterTable('grnSearch','grnTable')">
  </div>
  <table id="grnTable">
    <thead><tr><th>GRN #</th><th>Date</th><th>Supplier</th><th>Group</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th>Project</th><th class="num">Line items</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$receipts): ?>
        <tr><td colspan="8" class="muted">No GRNs recorded yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($receipts as $r): ?>
        <tr>
          <td><?= h($r['GRNNumber']) ?></td>
          <td><?= h($r['ReceiptDate']) ?></td>
          <td><?= h($r['Supplier'] ?? '') ?></td>
          <td><?= $r['GroupName'] ? h($r['GroupName']) : '<span class="muted">—</span>' ?></td>
          <?php if (is_admin()): ?><td class="muted"><?= h(office_label($r['Office'] ?? null)) ?></td><?php endif; ?>
          <td class="muted"><?= h(project_label($r['ProjectID'] ?? null)) ?></td>
          <td class="num"><?= (int)$r['lines'] ?></td>
          <td>
            <a class="btn secondary small" href="receipt_view?id=<?= $r['ReceiptID'] ?>">View</a>
            <a class="btn secondary small" href="receipt_form?id=<?= $r['ReceiptID'] ?>">Edit</a>
            <a class="btn secondary small" href="receipt_print?id=<?= $r['ReceiptID'] ?>" target="_blank">Print</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this GRN and all its item lines? This cannot be undone.')">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_receipt" value="<?= $r['ReceiptID'] ?>">
              <button type="submit" class="btn danger small">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
function applyReceiptFilters(){
  const parts = [];
  const office = document.getElementById('officeFilter');
  const project = document.getElementById('projectFilter');
  if (office && office.value) parts.push('office=' + encodeURIComponent(office.value));
  if (project && project.value) parts.push('project=' + encodeURIComponent(project.value));
  location.href = 'receipts' + (parts.length ? '?' + parts.join('&') : '');
}
function filterTable(inputId, tableId){
  const q = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
