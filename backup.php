<?php
require __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: backup');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $path = backup_create();
            flash_set('Backup created: ' . basename($path) . '.');
        } catch (Throwable $e) {
            flash_set('Could not create a backup: ' . $e->getMessage());
        }
        header('Location: backup');
        exit;
    }

    if ($action === 'delete') {
        $path = backup_resolve((string)($_POST['file'] ?? ''));
        if ($path === null) {
            flash_set('That backup file could not be found.');
        } else {
            @unlink($path);
            flash_set('Backup deleted.');
        }
        header('Location: backup');
        exit;
    }

    if ($action === 'restore_existing') {
        $path = backup_resolve((string)($_POST['file'] ?? ''));
        if ($path === null) {
            flash_set('That backup file could not be found.');
            header('Location: backup');
            exit;
        }
        try {
            $name = basename($path);
            backup_restore($path);
            logout_user();
            flash_set('Database restored from "' . $name . '". A safety copy of the previous database was saved as a "before-restore" backup. Please sign in again.');
            header('Location: login');
            exit;
        } catch (Throwable $e) {
            flash_set('Restore failed: ' . $e->getMessage());
            header('Location: backup');
            exit;
        }
    }

    if ($action === 'restore_upload') {
        $file = $_FILES['restore_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] === 0) {
            flash_set('No file was uploaded, or the upload failed. Please choose a .sqlite backup file and try again.');
            header('Location: backup');
            exit;
        }
        try {
            $name = basename($file['name']);
            backup_restore($file['tmp_name']);
            logout_user();
            flash_set('Database restored from "' . $name . '". A safety copy of the previous database was saved as a "before-restore" backup. Please sign in again.');
            header('Location: login');
            exit;
        } catch (Throwable $e) {
            flash_set('Restore failed: ' . $e->getMessage());
            header('Location: backup');
            exit;
        } finally {
            if (is_file($file['tmp_name'])) @unlink($file['tmp_name']);
        }
    }

    header('Location: backup');
    exit;
}

$backups = backup_list();
$liveDbPath = db_file_path();
$liveSize = is_file($liveDbPath) ? filesize($liveDbPath) : 0;
$liveMtime = is_file($liveDbPath) ? filemtime($liveDbPath) : null;

$pageTitle = 'Backup & Restore';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Backup & Restore</h1>
    <p>Everything in this app lives in one database file. Back it up regularly and keep a copy somewhere off this server.</p>
  </div>
</div>

<div class="cards">
  <div class="card">
    <div class="label">Current database size</div>
    <div class="value"><?= h(backup_human_size($liveSize)) ?></div>
  </div>
  <div class="card">
    <div class="label">Last modified</div>
    <div class="value" style="font-size:16px;"><?= $liveMtime ? h(date('Y-m-d H:i', $liveMtime)) : '—' ?></div>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Backups stored on this server</h2>
    <form method="post" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <button class="btn" type="submit">Create backup now</button>
    </form>
  </div>
  <table>
    <thead><tr><th>File</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$backups): ?>
        <tr><td colspan="4" class="muted">No backups yet — click "Create backup now" above.</td></tr>
      <?php endif; ?>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><?= h($b['name']) ?></td>
          <td class="muted"><?= h(backup_human_size($b['size'])) ?></td>
          <td class="muted"><?= h(date('Y-m-d H:i', $b['mtime'])) ?></td>
          <td>
            <a class="btn secondary small" href="backup_download?file=<?= urlencode($b['name']) ?>">Download</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Replace the LIVE database with this backup?\n\nA safety copy of the current database will be made first, but this still immediately overwrites all data added since this backup was taken.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="restore_existing">
              <input type="hidden" name="file" value="<?= h($b['name']) ?>">
              <button class="btn secondary small" type="submit">Restore</button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this backup file permanently?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="file" value="<?= h($b['name']) ?>">
              <button class="btn danger small" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head"><h2>Restore from an uploaded file</h2></div>
  <form method="post" enctype="multipart/form-data" style="padding:22px;" onsubmit="return confirm('Replace the LIVE database with this uploaded file?\n\nA safety copy of the current database will be made first, but this still immediately overwrites all current data.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="restore_upload">
    <div class="field">
      <label>Backup file (.sqlite)</label>
      <input type="file" name="restore_file" accept=".sqlite,.sqlite3,.db" required>
    </div>
    <div class="actions">
      <button class="btn danger" type="submit">Upload and restore</button>
    </div>
  </form>
  <div style="padding:0 22px 22px;font-size:13px;color:var(--ink-soft);">
    <p>Use this to restore a copy downloaded earlier from this page, or from another installation of this app. The file is checked (SQLite format, integrity, and a matching Users table) before anything is overwritten, and the current database is always backed up first as a "before-restore" snapshot — so a bad restore can itself be undone from the list above.</p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
