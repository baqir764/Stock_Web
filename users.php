<?php
require __DIR__ . '/includes/functions.php';
require_admin();

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify()) {
        flash_set('Your session expired — please try that again.');
        header('Location: users');
        exit;
    }

    $targetId = (int)($_POST['user_id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM Users WHERE UserID = ?');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();

    if (!$target) {
        flash_set('That account no longer exists.');
    } elseif ($_POST['action'] === 'toggle_active') {
        if ($target['UserID'] === $me['UserID']) {
            flash_set("You can't deactivate your own account while logged in as it.");
        } elseif ((int)$target['Active'] === 1) {
            // Never allow the last active admin to be deactivated — that
            // would lock everyone out of user management.
            $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM Users WHERE Role='admin' AND Active=1")->fetchColumn();
            if ($target['Role'] === 'admin' && $activeAdmins <= 1) {
                flash_set('Cannot deactivate the last active admin account.');
            } else {
                $pdo->prepare('UPDATE Users SET Active = 0 WHERE UserID = ?')->execute([$targetId]);
                flash_set('"' . $target['Username'] . '" has been deactivated.');
            }
        } else {
            $pdo->prepare('UPDATE Users SET Active = 1 WHERE UserID = ?')->execute([$targetId]);
            flash_set('"' . $target['Username'] . '" has been reactivated.');
        }
    }
    header('Location: users');
    exit;
}

$users = all_users();
$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Users</h1>
    <p>Accounts that can sign in to Stock Management.</p>
  </div>
  <a class="btn" href="user_form">+ New user</a>
</div>

<div class="panel">
  <div class="panel-head"><h2>All accounts</h2></div>
  <table>
    <thead>
      <tr><th>Username</th><th>Full name</th><th>Role</th><th>Office</th><th>Status</th><th>Created</th><th>Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= h($u['Username']) ?><?= $u['UserID'] === $me['UserID'] ? ' <span class="muted">(you)</span>' : '' ?></td>
          <td><?= h($u['FullName'] ?: '') ?></td>
          <td><?= h(ucfirst($u['Role'])) ?></td>
          <td class="muted"><?= $u['Role'] === 'admin' ? 'Both (admin)' : h(office_label($u['Office'] ?? null)) ?></td>
          <td>
            <?php if ((int)$u['Active']): ?>
              <span class="pill ok">Active</span>
            <?php else: ?>
              <span class="pill low">Deactivated</span>
            <?php endif; ?>
            <?php if ((int)$u['MustChangePassword']): ?>
              <span class="muted" style="font-size:11.5px;">— must set password</span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= h(substr($u['CreatedAt'], 0, 10)) ?></td>
          <td>
            <a class="btn secondary small" href="user_form?id=<?= $u['UserID'] ?>">Edit / reset password</a>
            <?php if ($u['UserID'] !== $me['UserID']): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('<?= (int)$u['Active'] ? 'Deactivate' : 'Reactivate' ?> this account?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= $u['UserID'] ?>">
                <button class="btn <?= (int)$u['Active'] ? 'danger' : 'secondary' ?> small" type="submit"><?= (int)$u['Active'] ? 'Deactivate' : 'Reactivate' ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
