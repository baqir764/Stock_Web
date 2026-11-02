<?php
require __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: projects');
        exit;
    }
    $id = (int)$_POST['delete_project'];
    // A project can only disappear when nothing is filed under it any more —
    // items, GRNs and stock cards keep their ProjectID, so deleting a project
    // that still holds records would leave them pointing at nothing.
    $inUse = db()->prepare('SELECT
        (SELECT COUNT(*) FROM Items WHERE ProjectID = ?) +
        (SELECT COUNT(*) FROM StockReceipts WHERE ProjectID = ?) +
        (SELECT COUNT(*) FROM StockReleases WHERE ProjectID = ?) AS c');
    $inUse->execute([$id, $id, $id]);
    if ((int)$inUse->fetch()['c'] > 0) {
        flash_set('Cannot delete: this project still has items, GRNs or stock cards filed under it. Move or delete those first.');
    } else {
        db()->prepare('DELETE FROM Projects WHERE ProjectID = ?')->execute([$id]);
        flash_set('Project deleted.');
    }
    header('Location: projects');
    exit;
}

$pageTitle = 'Projects';
require __DIR__ . '/includes/header.php';

$projects = db()->query('SELECT * FROM Projects ORDER BY ProjectName')->fetchAll();
$counts = [];
$stmt = db()->query('SELECT ProjectID, COUNT(*) c FROM Items WHERE ProjectID IS NOT NULL GROUP BY ProjectID');
foreach ($stmt->fetchAll() as $r) $counts[(int)$r['ProjectID']]['items'] = (int)$r['c'];
$stmt = db()->query('SELECT ProjectID, COUNT(*) c FROM StockReceipts WHERE ProjectID IS NOT NULL GROUP BY ProjectID');
foreach ($stmt->fetchAll() as $r) $counts[(int)$r['ProjectID']]['grn'] = (int)$r['c'];
$stmt = db()->query('SELECT ProjectID, COUNT(*) c FROM StockReleases WHERE ProjectID IS NOT NULL GROUP BY ProjectID');
foreach ($stmt->fetchAll() as $r) $counts[(int)$r['ProjectID']]['cards'] = (int)$r['c'];
?>
<div class="page-head">
  <div>
    <h1>Projects</h1>
    <p>Programmes of work. Each project keeps its own items, its own stock and its own reports — completely separate from the others. Projects span both offices.</p>
  </div>
  <div class="no-print">
    <a class="btn" href="project_form">+ New project</a>
  </div>
</div>

<div class="panel">
  <table>
    <thead><tr><th>Project</th><th>Description</th><th class="num">Items</th><th class="num">GRNs</th><th class="num">Stock cards</th><th></th></tr></thead>
    <tbody>
      <?php if (!$projects): ?>
        <tr><td colspan="6" class="muted">No projects yet. Everything currently lives under "No project". Add a project to start keeping its stock separate.</td></tr>
      <?php endif; ?>
      <?php foreach ($projects as $p): $id = (int)$p['ProjectID']; ?>
        <tr>
          <td><a href="items?project=<?= $id ?>"><?= h($p['ProjectName']) ?></a></td>
          <td class="muted"><?= $p['Description'] ? h($p['Description']) : '—' ?></td>
          <td class="num"><?= (int)($counts[$id]['items'] ?? 0) ?></td>
          <td class="num"><?= (int)($counts[$id]['grn'] ?? 0) ?></td>
          <td class="num"><?= (int)($counts[$id]['cards'] ?? 0) ?></td>
          <td style="white-space:nowrap;">
            <a class="btn secondary small" href="project_form?id=<?= $id ?>">Edit</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this project? Only possible while nothing is filed under it.');">
              <?= csrf_field() ?>
              <button class="btn danger small" type="submit" name="delete_project" value="<?= $id ?>">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head"><h2>No project</h2><span class="muted">Everything created before projects existed, and anything you choose not to file under a project.</span></div>
  <table>
    <thead><tr><th>Records with no project</th><th class="num">Count</th><th></th></tr></thead>
    <tbody>
      <tr><td>Items</td><td class="num"><?= (int)db()->query('SELECT COUNT(*) FROM Items WHERE ProjectID IS NULL')->fetchColumn() ?></td><td><a class="btn secondary small" href="items?project=none">View</a></td></tr>
      <tr><td>GRNs</td><td class="num"><?= (int)db()->query('SELECT COUNT(*) FROM StockReceipts WHERE ProjectID IS NULL')->fetchColumn() ?></td><td><a class="btn secondary small" href="receipts?project=none">View</a></td></tr>
      <tr><td>Stock cards</td><td class="num"><?= (int)db()->query('SELECT COUNT(*) FROM StockReleases WHERE ProjectID IS NULL')->fetchColumn() ?></td><td><a class="btn secondary small" href="releases?project=none">View</a></td></tr>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
