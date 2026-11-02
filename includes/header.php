<?php
require_once __DIR__ . '/functions.php';
require_login();
$me = current_user();
$flash = flash_get();
$current = basename($_SERVER['SCRIPT_NAME']);
$importType = $_GET['type'] ?? ($_POST['import_type'] ?? '');
$isImportGrn = ($current === 'import.php' && $importType !== 'card');
$isImportCard = ($current === 'import.php' && $importType === 'card');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>AAIWO Stock Management</title>
<?php
// Cache-bust the stylesheet with its own last-modified time, so a browser
// that already cached an older assets/style.css (e.g. from before the print
// rules below existed) always picks up the new one on the very next load —
// no "hard refresh" needed, today or after any future style.css edit.
$cssPath = __DIR__ . '/../assets/style.css';
$cssVer = is_file($cssPath) ? filemtime($cssPath) : 1;
?>
<link rel="stylesheet" href="<?= isset($assetPrefix) ? $assetPrefix : '' ?>assets/style.css?v=<?= $cssVer ?>">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand" title="Afghan Alfouz Innovational Welfare Organization">
      <img src="<?= isset($assetPrefix) ? $assetPrefix : '' ?>assets/logo-white.png" alt="AAIWO" class="brand-mark-logo">
      <div>
        <div class="brand-title">AAIWO</div>
        <div class="brand-sub">Stock Management</div>
      </div>
    </div>
    <nav>
      <a href="index" class="<?= $current==='index.php'?'active':'' ?>">Dashboard</a>
      <a href="items" class="<?= in_array($current, ['items.php','item_form.php','import_items.php','export_items.php'], true) ? 'active' : '' ?>">Items</a>
      <a href="receipts" class="<?= (in_array($current, ['receipts.php','receipt_form.php','receipt_view.php','export_excel.php'], true) || $isImportGrn) ? 'active' : '' ?>">Stock In (GRN)</a>
      <a href="releases" class="<?= (in_array($current, ['releases.php','release_form.php','release_view.php','export_excel.php'], true) || $isImportCard) ? 'active' : '' ?>">Stock Release Cards</a>
      <a href="health_facilities" class="<?= in_array($current, ['health_facilities.php','hf_form.php','import_hf.php','export_hf.php'], true) ? 'active' : '' ?>">Health Facilities</a>
      <?php if (($me['Role'] ?? '') === 'admin'): ?>
        <a href="projects" class="<?= in_array($current, ['projects.php','project_form.php'], true) ? 'active' : '' ?>">Projects</a>
        <a href="categories" class="<?= in_array($current, ['categories.php','category_form.php'], true) ? 'active' : '' ?>">Categories</a>
        <a href="reports" class="<?= $current==='reports.php'?'active':'' ?>">Reports</a>
        <a href="users" class="<?= $current==='users.php'||$current==='user_form.php'?'active':'' ?>">Users</a>
        <a href="backup" class="<?= $current==='backup.php'?'active':'' ?>">Backup</a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-account">
      <div class="account-card">
        <span class="account-avatar" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </span>
        <div class="account-meta">
          <div class="account-name"><?= h($me['FullName'] ?? '') ?></div>
          <div class="account-role">
            <?= h(ucfirst($me['Role'] ?? '')) ?>
            <?= (($me['Role'] ?? '') !== 'admin') ? ' · ' . h(office_label($me['Office'] ?? null)) : '' ?>
          </div>
        </div>
      </div>
      <div class="account-actions">
        <a href="my_account" class="account-action" title="My account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"></path></svg>
          <span>Account</span>
        </a>
        <a href="logout" class="account-action" title="Sign out">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
          <span>Sign out</span>
        </a>
      </div>
    </div>
  </aside>
  <main class="content">
    <?php if ($flash): ?>
      <div class="flash no-print"><?= h($flash) ?></div>
    <?php endif; ?>
