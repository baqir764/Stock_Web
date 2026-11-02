<?php
require __DIR__ . '/includes/functions.php';
require_login();

$scope = office_scope();

// One-click "empty all" — admin-only. Permanently deletes every health
// facility in whichever office an admin has filtered to (requires a
// concrete office, same rule as the Items list — an admin viewing "All
// offices" must filter to one first). Any stock card that pointed at a
// deleted facility keeps its item lines but has its facility reference
// cleared (FK constraints forbid deleting a facility that's still
// referenced), so history isn't lost, just unlinked. No undo; the confirm
// dialog spells that out before this ever runs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empty_all_hfs'])) {
    if (!is_admin()) {
        flash_set('That action is for admin accounts only.');
        header('Location: health_facilities');
        exit;
    }
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: health_facilities');
        exit;
    }
    if ($scope === null) {
        flash_set('Select a specific office filter before emptying its facility list.');
        header('Location: health_facilities');
        exit;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $idStmt = $pdo->prepare('SELECT HFID FROM HealthFacilities WHERE Office = ?');
        $idStmt->execute([$scope]);
        $hfIds = array_column($idStmt->fetchAll(), 'HFID');
        $hfCount = count($hfIds);
        if ($hfCount > 0) {
            $placeholders = implode(',', array_fill(0, $hfCount, '?'));
            $pdo->prepare("UPDATE StockReleases SET HFID = NULL WHERE HFID IN ($placeholders)")->execute($hfIds);
            $pdo->prepare('DELETE FROM HealthFacilities WHERE Office = ?')->execute([$scope]);
        }
        $pdo->commit();
        flash_set($hfCount > 0
            ? "All $hfCount facility(ies) in " . office_label($scope) . " were permanently deleted. Any stock cards that referenced them are kept, with their facility cleared."
            : 'There were no facilities to delete for ' . office_label($scope) . '.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Could not empty the facilities list: ' . $e->getMessage());
    }
    header('Location: health_facilities');
    exit;
}

$pageTitle = 'Health Facilities';
require __DIR__ . '/includes/header.php';
$hfs = ($scope === '__unassigned__') ? [] : all_health_facilities($scope);
?>
<div class="page-head">
  <div>
    <h1>Health Facilities</h1>
    <p><?= count($hfs) ?> facilities across <?= count(all_districts()) ?> districts<?= is_admin() ? h($scope === null ? ' (all offices)' : (' — ' . office_label($scope))) : '' ?>.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (is_admin()): ?>
      <select class="search-box" style="min-width:170px;" onchange="location.href='health_facilities'+(this.value?('?office='+this.value):'')">
        <option value="">All offices (combined)</option>
        <?php foreach (office_options() as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $scope === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <a class="btn secondary" href="export_hf<?= $scope !== null ? ('?office=' . urlencode($scope)) : '' ?>" title="Export the health facilities list to Excel">Export to Excel</a>
    <a class="btn secondary" href="import_hf">Import Excel</a>
    <a class="btn" href="hf_form">+ New facility</a>
    <?php if (is_admin() && $hfs && $scope !== null && $scope !== '__unassigned__'): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete ALL <?= count($hfs) ?> facility(ies) in <?= h(office_label($scope)) ?>? Stock cards that reference them will keep their item lines but lose the facility link. This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="office" value="<?= h($scope) ?>">
        <button type="submit" name="empty_all_hfs" value="1" class="btn danger">Empty <?= h(office_label($scope)) ?> facilities</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($scope === '__unassigned__'): ?>
  <div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);">Your account has no office assigned yet — contact an administrator to assign you to the Provincial Office or Main Office.</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h2>All facilities</h2>
    <input class="search-box" id="hfSearch" placeholder="Search facilities, provinces or districts…" onkeyup="filterTable('hfSearch','hfTable')">
  </div>
  <table id="hfTable">
    <thead><tr><th>Facility</th><th>Province</th><th>District</th><th>Type</th><th>In charge person</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th></th></tr></thead>
    <tbody>
      <?php foreach ($hfs as $hf): ?>
        <tr>
          <td><?= h($hf['HFName']) ?></td>
          <td><?= $hf['Province'] ? h($hf['Province']) : '<span class="muted">—</span>' ?></td>
          <td><?= h($hf['DistrictName']) ?></td>
          <td><?= h($hf['HFType']) ?></td>
          <td><?= $hf['InChargePerson'] ? h($hf['InChargePerson']) : '<span class="muted">—</span>' ?></td>
          <?php if (is_admin()): ?><td class="muted"><?= h(office_label($hf['Office'] ?? null)) ?></td><?php endif; ?>
          <td><a class="btn secondary small" href="hf_form?id=<?= $hf['HFID'] ?>">Edit</a></td>
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
