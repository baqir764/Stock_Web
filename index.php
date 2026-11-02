<?php
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';

$scope = office_scope();

$balances = ($scope === '__unassigned__') ? [] : stock_balance($scope);
$totalItems = count($balances);
$lowStock = array_filter($balances, fn($b) => $b['CurrentBalance'] <= 10);

if ($scope === '__unassigned__') {
    $recentReleases = [];
} elseif ($scope !== null) {
    $recentReleases = db()->prepare('
        SELECT sr.StockCardNo, sr.ReleaseDate, hf.HFName,
               (SELECT COUNT(*) FROM StockReleaseDetails WHERE ReleaseID = sr.ReleaseID) AS lines
        FROM StockReleases sr
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        WHERE sr.Office = ?
        ORDER BY sr.ReleaseID DESC LIMIT 8
    ');
    $recentReleases->execute([$scope]);
    $recentReleases = $recentReleases->fetchAll();
} else {
    $recentReleases = db()->query('
        SELECT sr.StockCardNo, sr.ReleaseDate, hf.HFName,
               (SELECT COUNT(*) FROM StockReleaseDetails WHERE ReleaseID = sr.ReleaseID) AS lines
        FROM StockReleases sr
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        ORDER BY sr.ReleaseID DESC LIMIT 8
    ')->fetchAll();
}
?>
<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <p>Current stock position at a glance<?= is_admin() ? h($scope === null ? ' — all offices' : (' — ' . office_label($scope))) : '' ?>.</p>
  </div>
  <?php if (is_admin()): ?>
    <select class="search-box" style="min-width:170px;" onchange="location.href='index'+(this.value?('?office='+this.value):'')">
      <option value="">All offices (combined)</option>
      <?php foreach (office_options() as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= $scope === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
</div>

<?php if ($scope === '__unassigned__'): ?>
  <div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);">Your account has no office assigned yet — contact an administrator to assign you to the Provincial Office or Main Office.</div>
<?php endif; ?>

<div class="cards">
  <div class="card">
    <div class="label">Items tracked</div>
    <div class="value"><?= $totalItems ?></div>
  </div>
  <div class="card <?= count($lowStock) ? 'warn' : '' ?>">
    <div class="label">Low stock items (&le;10)</div>
    <div class="value"><?= count($lowStock) ?></div>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Stock balance</h2>
    <input class="search-box" id="balSearch" placeholder="Search items…" onkeyup="filterTable('balSearch','balTable')">
  </div>
  <table id="balTable">
    <thead>
      <tr><th>Item ID</th><th>Item</th><th>Unit</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th class="num">Received</th><th class="num">Released</th><th class="num">Balance</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($balances as $b): ?>
        <tr>
          <td class="muted"><?= h($b['ItemID']) ?></td>
          <td><?= h($b['ItemName']) ?><?php if($b['ItemNameLocal']): ?><div class="muted"><?= h($b['ItemNameLocal']) ?></div><?php endif; ?></td>
          <td><?= h($b['Unit']) ?></td>
          <?php if (is_admin()): ?><td class="muted"><?= h(office_label($b['Office'] ?? null)) ?></td><?php endif; ?>
          <td class="num"><?= number_format($b['TotalReceived']) ?></td>
          <td class="num"><?= number_format($b['TotalReleased']) ?></td>
          <td class="num"><strong><?= number_format($b['CurrentBalance']) ?></strong></td>
          <td>
            <?php if ($b['CurrentBalance'] <= 10): ?>
              <span class="pill low">Low</span>
            <?php else: ?>
              <span class="pill ok">OK</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head"><h2>Recent stock cards issued</h2></div>
  <table>
    <thead><tr><th>Stock Card #</th><th>Health facility</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th>Lines</th></tr></thead>
    <tbody>
      <?php foreach ($recentReleases as $r): ?>
        <tr>
          <td><?= h($r['StockCardNo']) ?></td>
          <td><?= h($r['HFName'] ?: '') ?></td>
          <?php if (is_admin()): ?><td class="muted"><?= h(office_label($r['Office'] ?? null)) ?></td><?php endif; ?>
          <td><?= (int)$r['lines'] ?> items</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function filterTable(inputId, tableId){
  const q = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
