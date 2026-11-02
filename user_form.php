<?php
require __DIR__ . '/includes/functions.php';
require_admin();

$me = current_user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$user = ['Username' => '', 'FullName' => '', 'Role' => 'staff', 'Active' => 1, 'Office' => null];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM Users WHERE UserID = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash_set('That account no longer exists.');
        header('Location: users');
        exit;
    }
    $user = $found;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } else {
        $username = trim($_POST['Username'] ?? '');
        $fullName = trim($_POST['FullName'] ?? '');
        $role = ($_POST['Role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
        // The Active checkbox only appears on the edit form (a brand-new
        // account has no reason to start deactivated), so on create there's
        // nothing in $_POST to read — default it to active.
        $active = $id ? (isset($_POST['Active']) ? 1 : 0) : 1;
        $password = (string)($_POST['Password'] ?? '');

        // Office: staff accounts need one assigned (otherwise they'd see no
        // data at all — a fail-closed but confusing state to leave someone
        // in); admin accounts aren't restricted by office at all, so it's
        // optional for them and purely informational if set.
        $office = $_POST['Office'] ?? '';
        $office = array_key_exists($office, office_options()) ? $office : null;

        if ($username === '') {
            $error = 'Username is required.';
        } elseif (!$id && $password === '') {
            $error = 'A password is required for a new account.';
        } elseif ($password !== '' && strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($role === 'staff' && $office === null) {
            $error = 'Please assign an office (Provincial or Main) for this staff account.';
        } elseif ($id && $id === $me['UserID'] && !$active) {
            $error = "You can't deactivate your own account.";
        } elseif ($id && $id === $me['UserID'] && $role !== 'admin') {
            $error = "You can't remove your own admin role.";
        } else {
            // Case-insensitive uniqueness check (the column is COLLATE NOCASE,
            // but doing it explicitly here lets us give a clear error message).
            $dupStmt = db()->prepare('SELECT UserID FROM Users WHERE Username = ? AND UserID != ?');
            $dupStmt->execute([$username, $id ?? 0]);
            if ($dupStmt->fetch()) {
                $error = 'That username is already taken.';
            } else {
                // Safety net matching the one on users.php: never let the very
                // last active admin be demoted or deactivated through here either.
                if ($id) {
                    $stmt = db()->prepare('SELECT Role, Active FROM Users WHERE UserID = ?');
                    $stmt->execute([$id]);
                    $before = $stmt->fetch();
                    $losingAdmin = $before['Role'] === 'admin' && ($role !== 'admin' || !$active);
                    if ($losingAdmin) {
                        $activeAdmins = (int)db()->query("SELECT COUNT(*) FROM Users WHERE Role='admin' AND Active=1")->fetchColumn();
                        if ($activeAdmins <= 1) {
                            $error = 'Cannot remove the last active admin account.';
                        }
                    }
                }

                if (!$error) {
                    if ($id) {
                        if ($password !== '') {
                            db()->prepare('UPDATE Users SET Username=?, FullName=?, Role=?, Active=?, Office=?, PasswordHash=?, MustChangePassword=1 WHERE UserID=?')
                                ->execute([$username, $fullName, $role, $active, $office, password_hash($password, PASSWORD_DEFAULT), $id]);
                        } else {
                            db()->prepare('UPDATE Users SET Username=?, FullName=?, Role=?, Active=?, Office=? WHERE UserID=?')
                                ->execute([$username, $fullName, $role, $active, $office, $id]);
                        }
                        flash_set('Account "' . $username . '" updated.');
                    } else {
                        db()->prepare('INSERT INTO Users (Username, FullName, Role, Active, Office, PasswordHash, MustChangePassword) VALUES (?,?,?,?,?,?,1)')
                            ->execute([$username, $fullName, $role, $active, $office, password_hash($password, PASSWORD_DEFAULT)]);
                        flash_set('Account "' . $username . '" created. They must set their own password on first sign-in.');
                    }
                    header('Location: users');
                    exit;
                }
            }
        }
    }
}

$pageTitle = $id ? 'Edit user' : 'New user';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><h1><?= $id ? 'Edit user' : 'New user' ?></h1></div>
  <a class="btn secondary" href="users">&larr; Back to users</a>
</div>

<?php if ($error): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form class="stack" method="post" style="padding:22px;">
    <?= csrf_field() ?>
    <div class="field">
      <label>Username</label>
      <input type="text" name="Username" value="<?= h($user['Username']) ?>" required autocomplete="off">
    </div>
    <div class="field">
      <label>Full name</label>
      <input type="text" name="FullName" value="<?= h($user['FullName'] ?? '') ?>" placeholder="Shown in the sidebar and on audit history">
    </div>
    <div class="row2">
      <div class="field">
        <label>Role</label>
        <select name="Role" <?= ($id && $id === $me['UserID']) ? 'disabled' : '' ?>>
          <option value="staff" <?= $user['Role'] === 'staff' ? 'selected' : '' ?>>Staff — create/edit GRNs &amp; Stock Cards</option>
          <option value="admin" <?= $user['Role'] === 'admin' ? 'selected' : '' ?>>Admin — full access, incl. user management</option>
        </select>
        <?php if ($id && $id === $me['UserID']): ?>
          <input type="hidden" name="Role" value="<?= h($user['Role']) ?>">
          <p class="muted" style="font-size:12px;margin-top:4px;">You can't change your own role.</p>
        <?php endif; ?>
      </div>
      <div class="field">
        <label>Password<?= $id ? ' (leave blank to keep current)' : '' ?></label>
        <input type="password" name="Password" autocomplete="new-password" placeholder="<?= $id ? 'Leave blank to keep current password' : 'At least 8 characters' ?>">
      </div>
    </div>
    <div class="field">
      <label>Office</label>
      <select name="Office">
        <option value="">— Unassigned —</option>
        <?php foreach (office_options() as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($user['Office'] ?? null) === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="muted" style="font-size:12px;margin-top:4px;">Staff accounts only see and manage their assigned office's data. Admin accounts see both offices regardless of what's set here.</p>
    </div>
    <?php if ($id): ?>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:500;">
          <input type="checkbox" name="Active" value="1" style="width:auto;" <?= (int)$user['Active'] ? 'checked' : '' ?> <?= $id === $me['UserID'] ? 'disabled' : '' ?>>
          Active (can sign in)
        </label>
        <?php if ($id === $me['UserID']): ?>
          <input type="hidden" name="Active" value="1">
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="actions">
      <button class="btn" type="submit">Save account</button>
      <a class="btn secondary" href="users">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
