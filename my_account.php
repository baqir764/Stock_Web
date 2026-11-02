<?php
require __DIR__ . '/includes/functions.php';
require_login();

$me = current_user();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'profile') {
            $fullName = trim($_POST['FullName'] ?? '');
            db()->prepare('UPDATE Users SET FullName = ? WHERE UserID = ?')->execute([$fullName, $me['UserID']]);
            flash_set('Your name has been updated.');
            header('Location: my_account');
            exit;
        } elseif ($action === 'password') {
            $current = (string)($_POST['CurrentPassword'] ?? '');
            $new = (string)($_POST['NewPassword'] ?? '');
            $confirm = (string)($_POST['ConfirmPassword'] ?? '');

            $stmt = db()->prepare('SELECT * FROM Users WHERE UserID = ?');
            $stmt->execute([$me['UserID']]);
            $row = $stmt->fetch();

            // A brand-new account that was just force-set to MustChangePassword
            // still has to prove it knows the temporary password before picking
            // a new one — this isn't skippable just because the flag is set.
            if (!password_verify($current, $row['PasswordHash'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $error = "New password and confirmation don't match.";
            } elseif (password_verify($new, $row['PasswordHash'])) {
                $error = 'New password must be different from your current password.';
            } else {
                db()->prepare('UPDATE Users SET PasswordHash = ?, MustChangePassword = 0 WHERE UserID = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $me['UserID']]);
                flash_set('Password updated.');
                header('Location: my_account');
                exit;
            }
        }
    }
}

$pageTitle = 'My account';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>My account</h1>
    <p><?= h($me['Username']) ?> &middot; <?= h(ucfirst($me['Role'])) ?><?= $me['Role'] !== 'admin' ? ' · ' . h(office_label($me['Office'] ?? null)) : '' ?></p>
  </div>
</div>

<?php if ($error): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<?php if ((int)($me['MustChangePassword'] ?? 0)): ?>
  <div class="flash" style="background:var(--amber-tint);border-color:#E9CDA0;color:var(--amber);">
    You're using a temporary password. Please set a new one below before continuing.
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><h2>Profile</h2></div>
  <form method="post" style="padding:22px;max-width:420px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profile">
    <div class="field">
      <label>Username</label>
      <input type="text" value="<?= h($me['Username']) ?>" disabled>
    </div>
    <div class="field">
      <label>Full name</label>
      <input type="text" name="FullName" value="<?= h($me['FullName'] ?? '') ?>">
    </div>
    <div class="actions">
      <button class="btn" type="submit">Save name</button>
    </div>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><h2>Change password</h2></div>
  <form method="post" style="padding:22px;max-width:420px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <div class="field">
      <label>Current password</label>
      <input type="password" name="CurrentPassword" required autocomplete="current-password">
    </div>
    <div class="field">
      <label>New password</label>
      <input type="password" name="NewPassword" required autocomplete="new-password" placeholder="At least 8 characters">
    </div>
    <div class="field">
      <label>Confirm new password</label>
      <input type="password" name="ConfirmPassword" required autocomplete="new-password">
    </div>
    <div class="actions">
      <button class="btn" type="submit">Update password</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
