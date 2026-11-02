<?php
require __DIR__ . '/includes/functions.php';
require_login();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$item = ['ItemName'=>'','ItemNameLocal'=>'','Description'=>'','Unit'=>'','OpeningQtyIn'=>0,'OpeningQtyOut'=>0,'Office'=>null,'ProjectID'=>null,'CategoryID'=>null];

// A new item opened from a filtered Items list starts out in whatever project
// that list was showing, so filing it takes no extra thought.
$defaultProject = '';
$rawProject = trim((string)($_GET['project'] ?? ''));
if ($rawProject === 'none' || ctype_digit($rawProject)) {
    $defaultProject = $rawProject;
}
$defaultCategory = '';
$rawCategory = trim((string)($_GET['category'] ?? ''));
if ($rawCategory === 'none' || ctype_digit($rawCategory)) {
    $defaultCategory = $rawCategory;
}

if ($id) {
    $stmt = db()->prepare('SELECT * FROM Items WHERE ItemID = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found || !can_access_office($found['Office'] ?? null)) {
        flash_set('Item not found.');
        header('Location: items');
        exit;
    }
    $item = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } elseif ($id && !can_access_office($item['Office'] ?? null)) {
        $error = 'You do not have access to this item.';
    } else {
        $name = trim($_POST['ItemName']);
        $local = trim($_POST['ItemNameLocal']);
        $desc = trim($_POST['Description']);
        $unit = trim($_POST['Unit']);

        // Office: staff always save into their own assigned office (never
        // editable by them); admin picks it explicitly via the dropdown and
        // it's validated against the real set of office keys either way, so
        // this can never be set to something arbitrary via a tampered POST.
        if (is_admin()) {
            $office = $_POST['Office'] ?? '';
            $office = array_key_exists($office, office_options()) ? $office : null;
        } else {
            $office = current_user()['Office'] ?? null;
        }

        // Project: org-wide, optional. "none" (or anything unusable) stores
        // NULL, i.e. the item stays in the "No project" group; a number is
        // checked against the real Projects table so it can't be forged.
        $rawPost = trim((string)($_POST['ProjectID'] ?? ''));
        $projectId = null;
        if ($rawPost !== '' && $rawPost !== 'none' && ctype_digit($rawPost)) {
            $chk = db()->prepare('SELECT ProjectID FROM Projects WHERE ProjectID = ?');
            $chk->execute([(int)$rawPost]);
            $projectId = (int)$chk->fetchColumn() ?: null;
        }
        $rawCat = trim((string)($_POST['CategoryID'] ?? ''));
        $categoryId = null;
        if ($rawCat !== '' && $rawCat !== 'none' && ctype_digit($rawCat)) {
            $chkCat = db()->prepare('SELECT CategoryID FROM ItemCategories WHERE CategoryID = ?');
            $chkCat->execute([(int)$rawCat]);
            $categoryId = (int)$chkCat->fetchColumn() ?: null;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Validation redisplay keeps the project and category chosen.
            $item['ProjectID'] = $projectId;
            $item['CategoryID'] = $categoryId;
        }

        if ($name === '') {
            $error = 'Item name is required.';
        } elseif ($office === null) {
            $error = is_admin()
                ? 'Please choose which office this item belongs to.'
                : 'Your account has no office assigned yet — contact an administrator.';
        } else {
            if ($id) {
                // Keep the item's existing ID (e.g. "MO-03") unless it doesn't
                // have one yet, or an admin just moved it to the other
                // office — in that case its code is regenerated in the new
                // office's own sequence, so the prefix always matches where
                // the item actually lives.
                $code = $item['ItemCode'] ?? null;
                if ($code === null || $code === '' || $office !== ($item['Office'] ?? null)) {
                    $code = next_item_code($office);
                }
                $stmt = db()->prepare('UPDATE Items SET ItemCode=?, ItemName=?, ItemNameLocal=?, Description=?, Unit=?, Office=?, ProjectID=?, CategoryID=? WHERE ItemID=?');
                $stmt->execute([$code, $name, $local, $desc, $unit, $office, $projectId, $categoryId, $id]);
                flash_set('Item updated.');
            } else {
                $code = next_item_code($office);
                $stmt = db()->prepare('INSERT INTO Items (ItemCode, ItemName, ItemNameLocal, Description, Unit, OpeningQtyIn, OpeningQtyOut, Office, ProjectID, CategoryID) VALUES (?,?,?,?,?,0,0,?,?,?)');
                $stmt->execute([$code, $name, $local, $desc, $unit, $office, $projectId, $categoryId]);
                flash_set("Item added as $code.");
            }
            header('Location: items');
            exit;
        }
    }
}

$pageTitle = $id ? 'Edit item' : 'New item';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit item' : 'New item' ?></h1>
    <p>Item master data — used across receipts and releases.</p>
  </div>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form class="stack" method="post" style="padding:22px;">
    <?= csrf_field() ?>
    <?php if ($id): ?>
    <div class="field">
      <label>Item ID</label>
      <input type="text" value="<?= h($item['ItemCode'] ?? '') ?>" disabled>
    </div>
    <?php endif; ?>
    <div class="field">
      <label>Item name (English)</label>
      <input type="text" name="ItemName" value="<?= h($item['ItemName']) ?>" required>
    </div>
    <div class="field">
      <label>Item name (local / Dari)</label>
      <input type="text" name="ItemNameLocal" value="<?= h($item['ItemNameLocal']) ?>">
    </div>
    <div class="field">
      <label>Description</label>
      <textarea name="Description" rows="3"><?= h($item['Description']) ?></textarea>
    </div>
    <div class="field">
      <label>Unit</label>
      <input type="text" name="Unit" value="<?= h($item['Unit']) ?>" placeholder="PCS, RAM, Box, Roll…">
    </div>
    <div class="field">
      <label>Office</label>
      <?php if (is_admin()): ?>
        <?= office_select_field('Office', $item['Office'] ?? null) ?>
      <?php else: ?>
        <input type="text" value="<?= h(office_label(current_user()['Office'] ?? null)) ?>" disabled>
      <?php endif; ?>
    </div>
    <div class="field">
      <label>Project</label>
      <?php
        $selectedProject = $id
            ? (($item['ProjectID'] ?? null) === null ? 'none' : (string)(int)$item['ProjectID'])
            : ($defaultProject !== '' ? $defaultProject : 'none');
      ?>
      <?= project_select_field('ProjectID', $selectedProject) ?>
      <div class="muted" style="font-size:12px;margin-top:4px;">Optional. A project's stock is kept completely separate: its items can only be received or issued on that project's own GRNs and stock cards.</div>
    </div>
    <div class="field">
      <label>Category (بخش)</label>
      <?php
        $selectedCategory = $id
            ? (($item['CategoryID'] ?? null) === null ? 'none' : (string)(int)$item['CategoryID'])
            : ($defaultCategory !== '' ? $defaultCategory : 'none');
      ?>
      <?= category_select_field('CategoryID', $selectedCategory) ?>
      <div class="muted" style="font-size:12px;margin-top:4px;">Optional — the section this item belongs to, e.g. consumables (مواد مصرفی). Used for filtering and per-category reports.</div>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Save item</button>
      <a class="btn secondary" href="items">Cancel</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
