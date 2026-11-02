<?php
require __DIR__ . '/includes/functions.php';

session_start_once();

// Already logged in with a still-active account? Skip straight past the
// login form instead of making them log in twice.
if (!empty($_SESSION['user']['UserID'])) {
    $stmt = db()->prepare('SELECT Active FROM Users WHERE UserID = ?');
    $stmt->execute([$_SESSION['user']['UserID']]);
    if ((int)$stmt->fetchColumn() === 1) {
        header('Location: index');
        exit;
    }
}

$error = null;
$next = $_GET['next'] ?? $_POST['next'] ?? '';
// Same allow-only-relative-path rule as redirect_to_login() — never forward
// through this app to an external URL.
if ($next !== '' && (strpos($next, '://') !== false || substr($next, 0, 2) === '//')) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $user = $username !== '' ? find_user_by_username($username) : null;

        if ($user && login_is_locked($user)) {
            $mins = (int)ceil(login_seconds_remaining($user) / 60);
            $error = "Too many failed attempts. Please try again in $mins minute(s).";
        } elseif (!$user || !password_verify($password, $user['PasswordHash'])) {
            if ($user) login_register_failure($user);
            $error = 'Incorrect username or password.';
        } elseif (!(int)$user['Active']) {
            $error = 'This account has been deactivated. Contact your administrator.';
        } else {
            login_register_success((int)$user['UserID']);
            login_user($user);
            header('Location: ' . ($next !== '' ? $next : 'index'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in · AAIWO Stock Management</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--teal-dark);}
  .login-card{background:var(--panel);border-radius:var(--radius);padding:34px 34px 28px;width:100%;max-width:360px;box-shadow:0 12px 40px rgba(0,0,0,.25);}
  .login-brand{display:flex;align-items:center;gap:14px;margin-bottom:22px;}
  .login-logo{width:56px;height:56px;object-fit:contain;flex-shrink:0;}
  .login-title{font-family:'Source Serif 4',serif;font-weight:700;font-size:19px;color:var(--teal-dark);line-height:1.15;}
  .login-org-full{font-size:11px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.03em;margin-top:2px;line-height:1.35;}
  .login-sub{font-size:12px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-top:6px;}
</style>
</head>
<body>
  <div class="login-card">
    <div class="login-brand">
      <img src="assets/logo-color.png" alt="AAIWO" class="login-logo">
      <div>
        <div class="login-title">AAIWO</div>
        <div class="login-org-full">Afghan Alfouz Innovational Welfare Organization</div>
        <div class="login-sub">Sign in · Stock Management</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <div class="actions" style="margin-top:6px;">
        <button class="btn" type="submit" style="width:100%;justify-content:center;">Sign in</button>
      </div>
    </form>
  </div>
</body>
</html>
