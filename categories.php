<?php
require __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: categories');
        exit;
    }
    $id = (int)$_POST['delete_category'];
    $inUse = db()->prepare('SELECT COUNT(*) FROM Items WHERE CategoryID = ?');
    $inUse->execute([$id]);
    if ((int)$inUse->fetchColumn() > 0) {
        flash_set('Cannot delete: items are still filed under this category. Move them first.');
    } else {
        db()->prepare('DELETE FROM ItemCategories WHERE CategoryID = ?')->execute([$id]);
        flash_set('Category deleted.');
    }
    header('Location: categories');
    exit;
}

$pageTitle = 'Categories';
require __DIR__ . '/includes/header.php';

$categories = all_categories();
$counts = [];
foreach (db()->query('SELECT CategoryID, COUNT(*) c FROM Items WHERE CategoryID IS NOT NULL GROUP BY CategoryID')->fetchAll() as $r) {
    $counts[(int)$r['CategoryID']] = (int)$r['c'];
}
?>
<div class="page-head">
  <div>
    <h1>Categories (بخش بندی)</h1>
    <p>Sections of the item master — for example consumables (مواد مصرفی) or non-medical equipment (وسایل غیر طبی). Every item can be filed under one category, and reports can be shown per category.</p>
  </div>
  <div class="no-print">
    <a class="btn" href="category_form">+ New category</a>
  </div>
</div>

<div class="panel">
  <table>
    <thead><tr><th>Category</th><th>Local name</th><th class="num">Items</th><th></th></tr></thead>
    <tbody>
      <?php if (!$categories): ?>
        <tr><td colspan="4" class="muted">No categories yet — every item is under "No category".</td></tr>
      <?php endif; ?>
      <?php foreach ($categories as $c): $id = (int)$c['CategoryID']; ?>
        <tr>
          <td><a href="items?category=<?= $id ?>"><?= h($c['CategoryName']) ?></a></td>
          <td><?= $c['CategoryNameLocal'] ? h($c['CategoryNameLocal']) : '<span class="muted">—</span>' ?></td>
          <td class="num"><?= (int)($counts[$id] ?? 0) ?></td>
          <td style="white-space:nowrap;">
            <a class="btn secondary small" href="category_form?id=<?= $id ?>">Edit</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this category? Only possible while no item is filed under it.');">
              <?= csrf_field() ?>
              <button class="btn danger small" type="submit" name="delete_category" value="<?= $id ?>">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head"><h2>No category</h2><span class="muted">Items created before categories existed, and anything you choose not to file.</span></div>
  <table>
    <tbody>
      <tr>
        <td>Items with no category</td>
        <td class="num"><?= (int)db()->query('SELECT COUNT(*) FROM Items WHERE CategoryID IS NULL')->fetchColumn() ?></td>
        <td><a class="btn secondary small" href="items?category=none">View</a></td>
      </tr>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
