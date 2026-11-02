<?php
require __DIR__ . '/includes/functions.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$category = ['CategoryName' => '', 'CategoryNameLocal' => ''];

if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM ItemCategories WHERE CategoryID = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash_set('Category not found.');
        header('Location: categories');
        exit;
    }
    $category = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } else {
        $name = trim((string)($_POST['CategoryName'] ?? ''));
        $local = trim((string)($_POST['CategoryNameLocal'] ?? ''));
        if ($name === '') {
            $error = 'Category name is required.';
        } else {
            if ($id > 0) {
                db()->prepare('UPDATE ItemCategories SET CategoryName = ?, CategoryNameLocal = ? WHERE CategoryID = ?')
                    ->execute([$name, $local, $id]);
                flash_set('Category updated.');
            } else {
                db()->prepare('INSERT INTO ItemCategories (CategoryName, CategoryNameLocal, CreatedAt) VALUES (?,?,datetime(\'now\'))')
                    ->execute([$name, $local]);
                flash_set('Category added.');
            }
            header('Location: categories');
            exit;
        }
    }
}

$pageTitle = $id ? 'Edit category' : 'New category';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit category' : 'New category' ?></h1>
    <p>A section of the item master, e.g. consumables (مواد مصرفی).</p>
  </div>
  <a class="btn secondary" href="categories">&larr; Back to categories</a>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form class="stack" method="post" style="padding:22px;">
    <?= csrf_field() ?>
    <div class="field">
      <label>Category name (English)</label>
      <input type="text" name="CategoryName" value="<?= h($category['CategoryName']) ?>" required placeholder="e.g. Consumables">
    </div>
    <div class="field">
      <label>Category name (local / Dari)</label>
      <input type="text" name="CategoryNameLocal" value="<?= h($category['CategoryNameLocal'] ?? '') ?>" placeholder="e.g. مواد مصرفی">
    </div>
    <div class="actions">
      <button class="btn" type="submit">Save category</button>
      <a class="btn secondary" href="categories">Cancel</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
