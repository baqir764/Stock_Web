<?php
require __DIR__ . '/includes/functions.php';
require_login();

$scope = office_scope();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_release'])) {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: releases');
        exit;
    }
    $id = (int)$_POST['delete_release'];
    $row = db()->prepare('SELECT Office FROM StockReleases WHERE ReleaseID = ?');
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row || !can_access_office($row['Office'] ?? null)) {
        flash_set('Stock card not found.');
        header('Location: releases');
        exit;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM StockReleaseDetails WHERE ReleaseID = ?')->execute([$id]);
        $stmt = $pdo->prepare('DELETE FROM StockReleases WHERE ReleaseID = ?');
        $stmt->execute([$id]);
        $pdo->commit();
        flash_set($stmt->rowCount() > 0 ? 'Stock card and all its item lines were deleted.' : 'Stock card not found.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Could not delete stock card: ' . $e->getMessage());
    }
    header('Location: releases');
    exit;
}

// One-click "empty all" — permanently wipes every stock card belonging to
// the office currently in scope, including their item lines. Admin-only
// (unlike Items/Health Facilities, staff can't bulk-wipe stock card history
// here) since release cards are the audit trail of what's actually left the
// store — restricting this to admin avoids a staff member accidentally
// erasing that record. Requires a concrete office, same rule as elsewhere —
// an admin viewing "All offices" must filter to one office first so this
// can never wipe both offices' cards in one click. No undo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empty_all_releases'])) {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: releases');
        exit;
    }
    if (!is_admin()) {
        flash_set('Only an admin can empty the stock cards list.');
        header('Location: releases');
        exit;
    }
    if ($scope === null) {
        flash_set('Select a specific office filter before emptying its stock cards list.');
        header('Location: releases');
        exit;
    }
    if (project_scope() !== '__all__') {
        // Same rule as the items list: the button promises a whole office, so
        // a project filter must be cleared first rather than half-honoured.
        flash_set('Clear the project filter first — "Empty stock cards" always works on a whole office.');
        header('Location: releases');
        exit;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $idStmt = $pdo->prepare('SELECT ReleaseID FROM StockReleases WHERE Office = ?');
        $idStmt->execute([$scope]);
        $releaseIds = array_column($idStmt->fetchAll(), 'ReleaseID');
        $releaseCount = count($releaseIds);
        if ($releaseCount > 0) {
            $placeholders = implode(',', array_fill(0, $releaseCount, '?'));
            $pdo->prepare("DELETE FROM StockReleaseDetails WHERE ReleaseID IN ($placeholders)")->execute($releaseIds);
            $pdo->prepare('DELETE FROM StockReleases WHERE Office = ?')->execute([$scope]);
        }
        $pdo->commit();
        flash_set($releaseCount > 0
            ? "All $releaseCount stock card(s) in " . office_label($scope) . " were permanently deleted, along with their item lines."
            : 'There were no stock cards to delete for ' . office_label($scope) . '.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Could not empty the stock cards list: ' . $e->getMessage());
    }
    header('Location: releases');
    exit;
}

$pageTitle = 'Stock Out';
require __DIR__ . '/includes/header.php';

$project = project_scope();
[$pSql, $pParams] = project_condition('sr.ProjectID', $project);

if ($scope === '__unassigned__') {
    $releases = [];
} elseif ($scope !== null) {
    $releases = db()->prepare('
        SELECT sr.*, hf.HFName,
               (SELECT COUNT(*) FROM StockReleaseDetails WHERE ReleaseID=sr.ReleaseID) AS lines
        FROM StockReleases sr
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        WHERE sr.Office = ?' . $pSql . '
    ');
    $releases->execute(array_merge([$scope], $pParams));
    $releases = $releases->fetchAll();
} elseif ($pSql !== '') {
    $releases = db()->prepare('
        SELECT sr.*, hf.HFName,
               (SELECT COUNT(*) FROM StockReleaseDetails WHERE ReleaseID=sr.ReleaseID) AS lines
        FROM StockReleases sr
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        WHERE 1=1' . $pSql . '
    ');
    $releases->execute($pParams);
    $releases = $releases->fetchAll();
} else {
    $releases = db()->query('
        SELECT sr.*, hf.HFName,
               (SELECT COUNT(*) FROM StockReleaseDetails WHERE ReleaseID=sr.ReleaseID) AS lines
        FROM StockReleases sr
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
    ')->fetchAll();
}

// Natural sort (number-aware) on the stock card number itself, so
// "...-2" sorts before "...-10" instead of the plain-text order SQL would
// otherwise give, and so the list stays in card-number order even if cards
// were entered or imported out of sequence.
usort($releases, fn($a, $b) => strnatcmp($a['ReleaseDate'] ?? '', $b['ReleaseDate'] ?? ''));
?>
<div class="page-head">
  <div>
    <h1>Stock Release Cards</h1>
    <p>Items issued from the store to health facilities / departments<?= is_admin() ? h($scope === null ? ' (all offices)' : (' — ' . office_label($scope))) : '' ?>.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (is_admin()): ?>
      <select id="officeFilter" class="search-box" style="min-width:170px;" onchange="applyReleaseFilters()">
        <option value="">All offices (combined)</option>
        <?php foreach (office_options() as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $scope === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select id="projectFilter" class="search-box" style="min-width:170px;" onchange="applyReleaseFilters()" title="Show only one project's stock cards">
      <?= project_options_html($project === '__all__' ? '' : $project, true) ?>
    </select>
    <a class="btn secondary" href="export_excel?type=card<?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?>" title="Stock Cards list with per-card item quantities">Export to Excel</a>
    <a class="btn secondary" href="import?type=card">Import Excel</a>
    <a class="btn" href="release_form<?= $scope !== null && $scope !== '__unassigned__' ? ('?office=' . urlencode($scope)) : '' ?><?= $project !== '__all__' ? (($scope !== null && $scope !== '__unassigned__') ? '&' : '?') . 'project=' . urlencode((string)$project) : '' ?>">+ New stock card</a>
    <?php if ($releases && is_admin() && $scope !== null && $scope !== '__unassigned__'): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete ALL <?= count($releases) ?> stock card(s) in <?= h(office_label($scope)) ?>? This also deletes their item lines and cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="office" value="<?= h($scope) ?>">
        <button type="submit" name="empty_all_releases" value="1" class="btn danger">Empty <?= h(office_label($scope)) ?> cards</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($scope === '__unassigned__'): ?>
  <div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);">Your account has no office assigned yet — contact an administrator to assign you to the Provincial Office or Main Office.</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h2>All Release cards</h2>
    <input class="search-box" id="relSearch" placeholder="Search facility, card # or group…" onkeyup="filterTable('relSearch','relTable')">
  </div>
  <table id="relTable">
    <thead><tr><th>Stock Card #</th><th>Receiver / Facility</th><th>Receiver name</th><th>Group</th><th>Release Date</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th>Project</th><th class="num">Line items</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$releases): ?>
        <tr><td colspan="8" class="muted">No stock cards recorded yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($releases as $r): ?>
        <tr>
          <td><?= h($r['StockCardNo']) ?></td>
          <td><?= h($r['HFName'] ?: '') ?></td>
          <td class="muted"><?= h($r['ReceiverName']) ?: '—' ?></td>
          <td><?= $r['GroupName'] ? h($r['GroupName']) : '<span class="muted">—</span>' ?></td>
          <td><?= $r['ReleaseDate'] ? h($r['ReleaseDate']) : '<span class="muted">—</span>' ?></td>
          <?php if (is_admin()): ?><td class="muted"><?= h(office_label($r['Office'] ?? null)) ?></td><?php endif; ?>
          <td class="muted"><?= h(project_label($r['ProjectID'] ?? null)) ?></td>
          <td class="num"><?= (int)$r['lines'] ?></td>
          <td>
            <a class="btn secondary small" href="release_view?id=<?= $r['ReleaseID'] ?>">View</a>
            <a class="btn secondary small" href="release_form?id=<?= $r['ReleaseID'] ?>">Edit</a>
            <a class="btn secondary small" href="release_print?id=<?= $r['ReleaseID'] ?>" target="_blank">Print</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this stock card and all its item lines? This cannot be undone.')">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_release" value="<?= $r['ReleaseID'] ?>">
              <button type="submit" class="btn danger small">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
function applyReleaseFilters(){
  const parts = [];
  const office = document.getElementById('officeFilter');
  const project = document.getElementById('projectFilter');
  if (office && office.value) parts.push('office=' + encodeURIComponent(office.value));
  if (project && project.value) parts.push('project=' + encodeURIComponent(project.value));
  location.href = 'releases' + (parts.length ? '?' + parts.join('&') : '');
}
function filterTable(inputId, tableId){
  const q = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
