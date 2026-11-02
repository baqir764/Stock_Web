<?php
require __DIR__ . '/includes/functions.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project = ['ProjectName' => '', 'Description' => ''];

if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM Projects WHERE ProjectID = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash_set('Project not found.');
        header('Location: projects');
        exit;
    }
    $project = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } else {
        $name = trim((string)($_POST['ProjectName'] ?? ''));
        $desc = trim((string)($_POST['Description'] ?? ''));
        if ($name === '') {
            $error = 'Project name is required.';
        } else {
            $me = current_user();
            if ($id > 0) {
                db()->prepare('UPDATE Projects SET ProjectName = ?, Description = ?, UpdatedByUserID = ?, UpdatedAt = datetime(\'now\') WHERE ProjectID = ?')
                    ->execute([$name, $desc, $me['UserID'], $id]);
                flash_set('Project updated.');
            } else {
                db()->prepare('INSERT INTO Projects (ProjectName, Description, CreatedByUserID, CreatedAt) VALUES (?,?,?,datetime(\'now\'))')
                    ->execute([$name, $desc, $me['UserID']]);
                flash_set('Project added.');
            }
            header('Location: projects');
            exit;
        }
    }
}

$pageTitle = $id ? 'Edit project' : 'New project';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit project' : 'New project' ?></h1>
    <p>A project keeps its own items, its own stock and its own reports, separate from every other project. It spans both offices.</p>
  </div>
  <a class="btn secondary" href="projects">&larr; Back to projects</a>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form class="stack" method="post" style="padding:22px;">
    <?= csrf_field() ?>
    <div class="field">
      <label>Project name</label>
      <input type="text" name="ProjectName" value="<?= h($project['ProjectName']) ?>" required placeholder="e.g. WASH response 2026">
    </div>
    <div class="field">
      <label>Description</label>
      <textarea name="Description" rows="3" placeholder="Optional — what this project covers"><?= h($project['Description']) ?></textarea>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Save project</button>
      <a class="btn secondary" href="projects">Cancel</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
