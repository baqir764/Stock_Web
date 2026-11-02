<?php
require_once __DIR__ . '/../config/db.php';

// Standard hardening headers, sent on every request. Doesn't change behavior
// for any page — just tells the browser not to sniff content types, not to
// let this app be framed by another site (clickjacking), to keep the
// Referer header minimal on cross-origin links, and to only load
// scripts/styles/images from this same origin. Safe to leave on for local
// XAMPP/http testing too: HSTS is the only piece that's conditional, and it
// only ever gets sent once the site is actually reached over HTTPS.
function send_security_headers(): void {
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    // Only tell the browser to require HTTPS once we've actually been reached
    // over HTTPS — sending this over plain http (e.g. local testing) would be
    // a no-op at best and a footgun at worst if the site is ever briefly
    // served over http again.
    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
send_security_headers();

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Normalizes a spreadsheet/CSV header cell down to a bare lowercase key
// (letters+digits only) so "Item ID", "ItemID" and "item_id" all match the
// same lookup key — used by the Items/Health Facilities importers.
function header_key(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim($s)));
}

function all_items(?string $office = null, $project = '__all__', $category = '__all__'): array {
    $where = [];
    $params = [];
    if ($office !== null) {
        $where[] = 'Office = ?';
        $params[] = $office;
    }
    [$pSql, $pParams] = project_condition('ProjectID', $project);
    if ($pSql !== '') {
        $where[] = substr($pSql, 5); // drop the leading " AND "
        $params = array_merge($params, $pParams);
    }
    [$cSql, $cParams] = category_condition('CategoryID', $category);
    if ($cSql !== '') {
        $where[] = substr($cSql, 5);
        $params = array_merge($params, $cParams);
    }
    $sql = 'SELECT * FROM Items' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ItemName';
    if ($params) {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function all_health_facilities(?string $office = null): array {
    $sql = '
        SELECT hf.*, d.DistrictName
        FROM HealthFacilities hf
        LEFT JOIN Districts d ON d.DistrictID = hf.DistrictID
    ';
    if ($office !== null) {
        $stmt = db()->prepare($sql . ' WHERE hf.Office = ? ORDER BY hf.HFType, d.DistrictName, hf.HFName');
        $stmt->execute([$office]);
        return $stmt->fetchAll();
    }
    return db()->query($sql . ' ORDER BY hf.HFType, d.DistrictName, hf.HFName')->fetchAll();
}

function all_districts(): array {
    return db()->query('SELECT * FROM Districts ORDER BY DistrictName')->fetchAll();
}

// Every facility type currently in use (e.g. "SHC", "BHC", "CHC") plus a
// starter set of the common Afghan health-facility types, so the New/Edit
// Facility form can suggest them via a <datalist> while still letting
// anyone type a brand-new type freely — the moment a new type is saved on
// one facility, it shows up as a suggestion for every facility after that.
function all_hf_types(): array {
    $defaults = ['BHC', 'CHC', 'SHC', 'DH', 'H3', 'PO', 'MO'];
    $existing = db()->query("SELECT DISTINCT HFType FROM HealthFacilities WHERE HFType IS NOT NULL AND TRIM(HFType) <> '' ORDER BY HFType")->fetchAll(PDO::FETCH_COLUMN);
    $types = array_unique(array_merge($defaults, $existing));
    sort($types, SORT_STRING | SORT_FLAG_CASE);
    return $types;
}

function stock_balance(?string $office = null, $project = '__all__', $category = '__all__'): array {
    // The project and category tests belong in WHERE, never in the LEFT
    // JOIN's ON — a condition in ON would only blank the joined totals and
    // still return every item of the other projects/categories.
    [$pSql] = project_condition('i.ProjectID', $project);
    [$cSql] = category_condition('i.CategoryID', $category);
    $where = [];
    $params = [];
    if ($office !== null) {
        $where[] = 'i.Office = ?';
        $params[] = $office;
    }
    if ($pSql !== '') {
        $where[] = substr($pSql, 5); // drop the leading " AND "
    }
    if ($cSql !== '') {
        $where[] = substr($cSql, 5);
    }
    $sql = '
        SELECT i.ItemID, i.ItemName, i.ItemNameLocal, i.Description, i.Unit, i.Office, i.ProjectID,
               COALESCE(r.qty_in, 0)  AS TotalReceived,
               COALESCE(o.qty_out, 0) AS TotalReleased,
               COALESCE(r.qty_in, 0) - COALESCE(o.qty_out, 0) AS CurrentBalance,
               i.CategoryID
        FROM Items i
        LEFT JOIN (SELECT ItemID, SUM(Quantity) qty_in FROM StockReceiptDetails GROUP BY ItemID) r
               ON r.ItemID = i.ItemID
        LEFT JOIN (SELECT ItemID, SUM(Quantity) qty_out FROM StockReleaseDetails GROUP BY ItemID) o
               ON o.ItemID = i.ItemID
    ' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.ItemID';
    if ($params) {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function item_balance(int $itemId): float {
    $stmt = db()->prepare('
        SELECT COALESCE((SELECT SUM(Quantity) FROM StockReceiptDetails WHERE ItemID = ?), 0)
             - COALESCE((SELECT SUM(Quantity) FROM StockReleaseDetails WHERE ItemID = ?), 0) AS bal
    ');
    $stmt->execute([$itemId, $itemId]);
    return (float)$stmt->fetch()['bal'];
}

function flash_set(string $msg): void {
    session_start_once();
    $_SESSION['flash'] = $msg;
}

function flash_get(): ?string {
    session_start_once();
    if (!empty($_SESSION['flash'])) {
        $m = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $m;
    }
    return null;
}

function is_https_request(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    // Many hosts sit behind a load balancer/proxy that terminates TLS and
    // forwards plain HTTP internally — this header is how the app finds out
    // the original request was actually HTTPS.
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
}

function session_start_once(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // Only mark the cookie HTTPS-only when the request actually came in
            // over HTTPS — so this keeps working during local http:// testing
            // but locks it down automatically once the site is served over TLS.
            'secure' => is_https_request(),
        ]);
        session_start();
    }
}

// ==================== Accounts & authentication ====================

function all_users(): array {
    return db()->query('SELECT * FROM Users ORDER BY Active DESC, Username')->fetchAll();
}

function find_user_by_username(string $username): ?array {
    $stmt = db()->prepare('SELECT * FROM Users WHERE Username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Establishes the logged-in session for a Users row (post password_verify()).
// Regenerates the session ID to prevent session fixation across the login boundary.
function login_user(array $user): void {
    session_start_once();
    session_regenerate_id(true);
    $_SESSION['user'] = ['UserID' => (int)$user['UserID']];
}

function logout_user(): void {
    session_start_once();
    $_SESSION = [];
    session_regenerate_id(true);
}

// ==================== Login throttling ====================
// A simple per-account lockout so a password can't just be brute-forced by
// retrying forever: after LOGIN_MAX_ATTEMPTS consecutive wrong passwords,
// the account is locked for LOGIN_LOCKOUT_SECONDS. A correct login resets
// the counter. Deliberately keyed to the account (not the IP/session), and
// deliberately silent about *why* — same generic "Incorrect username or
// password" wording as every other failure — until the lock actually kicks
// in, so this doesn't add a new way to enumerate valid usernames beyond
// what a timing/behavior difference already would.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 600; // 10 minutes

function login_is_locked(array $user): bool {
    return !empty($user['LockedUntil']) && strtotime($user['LockedUntil']) > time();
}

function login_seconds_remaining(array $user): int {
    if (empty($user['LockedUntil'])) return 0;
    return max(0, strtotime($user['LockedUntil']) - time());
}

function login_register_failure(array $user): void {
    $attempts = (int)$user['FailedLoginAttempts'] + 1;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $lockUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS);
        db()->prepare('UPDATE Users SET FailedLoginAttempts = 0, LockedUntil = ? WHERE UserID = ?')
            ->execute([$lockUntil, $user['UserID']]);
    } else {
        db()->prepare('UPDATE Users SET FailedLoginAttempts = ? WHERE UserID = ?')
            ->execute([$attempts, $user['UserID']]);
    }
}

function login_register_success(int $userId): void {
    db()->prepare('UPDATE Users SET FailedLoginAttempts = 0, LockedUntil = NULL WHERE UserID = ?')->execute([$userId]);
}

function current_user(): ?array {
    return $_SESSION['user_full'] ?? null;
}

function redirect_to_login(): void {
    $next = $_SERVER['REQUEST_URI'] ?? '';
    // Only ever redirect back to a same-site relative path — never let an
    // absolute or protocol-relative URL in the query string send someone
    // through this app to an external site after login.
    $safeNext = ($next !== '' && strpos($next, '://') === false && substr($next, 0, 2) !== '//') ? $next : '';
    header('Location: login' . ($safeNext ? ('?next=' . urlencode($safeNext)) : ''));
    exit;
}

// Call at the top of every page that requires a logged-in user. Re-checks the
// user's row in the database on every request (not just at login) so a
// deactivated account or a forced password reset takes effect immediately,
// even for a session that's already open.
function require_login(): void {
    session_start_once();
    if (empty($_SESSION['user']['UserID'])) {
        redirect_to_login();
    }

    $stmt = db()->prepare('SELECT * FROM Users WHERE UserID = ?');
    $stmt->execute([$_SESSION['user']['UserID']]);
    $row = $stmt->fetch();

    if (!$row || !(int)$row['Active']) {
        logout_user();
        redirect_to_login();
    }

    $_SESSION['user_full'] = [
        'UserID' => (int)$row['UserID'],
        'Username' => $row['Username'],
        'FullName' => $row['FullName'] ?: $row['Username'],
        'Role' => $row['Role'],
        'Office' => $row['Office'] ?? null,
        'MustChangePassword' => (int)$row['MustChangePassword'],
    ];

    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($row['MustChangePassword'] && !in_array($current, ['my_account.php', 'logout.php'], true)) {
        flash_set('Please set a new password before continuing.');
        header('Location: my_account');
        exit;
    }
}

// Call at the top of admin-only pages, after require_login() would otherwise run —
// this calls it internally, so a single require_admin() is enough.
function require_admin(): void {
    require_login();
    if (($_SESSION['user_full']['Role'] ?? '') !== 'admin') {
        flash_set('That page is for admin accounts only.');
        header('Location: index');
        exit;
    }
}

// ==================== Office split ====================
// Every Item, Health Facility, GRN and Stock Card belongs to exactly one of
// two offices. A staff account is assigned one office and only ever sees
// that office's data; an admin isn't restricted by office at all and can
// filter/view either or both. See config/db.php's stockapp_migrate() for
// the schema side of this.

function is_admin(): bool {
    return (current_user()['Role'] ?? '') === 'admin';
}

function office_label(?string $office): string {
    switch ($office) {
        case 'provincial': return 'Provincial Office';
        case 'main': return 'Main Office';
        default: return 'Unassigned';
    }
}

// The full set of valid office keys => display labels, for building <select>s.
function office_options(): array {
    return ['provincial' => 'Provincial Office', 'main' => 'Main Office'];
}

// The value to filter Office-scoped list/report queries by:
//   - staff: always their own assigned office. A staff account with no
//     office assigned yet gets a sentinel that matches no real row, so it
//     fails closed (sees nothing) rather than accidentally seeing everyone's
//     data or crashing on a null/empty comparison.
//   - admin: whatever ?office= they picked ('provincial'/'main'), or null to
//     mean "no filter — show every office combined".
function office_scope(): ?string {
    if (is_admin()) {
        $requested = $_GET['office'] ?? $_POST['office'] ?? '';
        return array_key_exists($requested, office_options()) ? $requested : null;
    }
    $mine = current_user()['Office'] ?? null;
    return $mine !== null && array_key_exists($mine, office_options()) ? $mine : '__unassigned__';
}

// ==================== Projects ====================
// Projects are an org-wide dimension on top of the office split: an item, a
// GRN or a stock card belongs to at most ONE project, and records with no
// project (NULL — everything that predates projects) are their own group,
// "No project". A project filter value is one of:
//   '__all__' — no filtering at all (the default, today's behaviour)
//   'none'    — only records with no project
//   <int>     — only that project's records
function project_scope() {
    $raw = trim((string)($_GET['project'] ?? $_POST['project'] ?? ''));
    if ($raw === 'none') return 'none';
    if ($raw !== '' && ctype_digit($raw)) return (int)$raw;
    return '__all__';
}

// SQL fragment + params for a project filter on $column. The ID is cast to
// int before it is placed in the SQL, so nothing a user typed can reach the
// query as text; '__all__' contributes nothing at all.
function project_condition(string $column, $project): array {
    if ($project === 'none') {
        return [" AND $column IS NULL", []];
    }
    if ($project !== '__all__' && ctype_digit((string)$project)) {
        return [" AND $column = " . (int)$project, []];
    }
    return ['', []];
}

function all_projects(): array {
    return db()->query('SELECT * FROM Projects ORDER BY ProjectName')->fetchAll();
}

function project_name(?int $id): string {
    if ($id === null) return '';
    $stmt = db()->prepare('SELECT ProjectName FROM Projects WHERE ProjectID = ?');
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: '');
}

// What to show where a record's project would be named.
function project_label(?int $id): string {
    return $id === null ? 'No project' : (project_name($id) ?: 'Missing project');
}

// The <option> list of every project plus "No project"; $includeAll adds an
// "All projects" option with an empty value, for filter bars.
function project_options_html($selected, bool $includeAll = false): string {
    $selected = (string)$selected;
    $out = '';
    if ($includeAll) {
        $out .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>All projects</option>';
    }
    $out .= '<option value="none"' . ($selected === 'none' ? ' selected' : '') . '>No project</option>';
    foreach (all_projects() as $p) {
        $val = (string)(int)$p['ProjectID'];
        $out .= '<option value="' . $val . '"' . ($selected === $val ? ' selected' : '') . '>'
              . h($p['ProjectName']) . '</option>';
    }
    return $out;
}

// A ready-made <select> of every project plus "No project".
function project_select_field(string $name, $selected, string $attrs = ''): string {
    return '<select name="' . h($name) . '" ' . $attrs . '>'
         . project_options_html($selected)
         . '</select>';
}

// ==================== Item categories (بخش بندی) ====================
// Categories describe ITEMS (consumables, non-medical equipment, …). Filter
// values mirror the project ones:
//   '__all__' — no filtering, 'none' — only items with no category,
//   <int>     — only that category's items.
function category_scope() {
    $raw = trim((string)($_GET['category'] ?? $_POST['category'] ?? ''));
    if ($raw === 'none') return 'none';
    if ($raw !== '' && ctype_digit($raw)) return (int)$raw;
    return '__all__';
}

function category_condition(string $column, $category): array {
    if ($category === 'none') {
        return [" AND $column IS NULL", []];
    }
    if ($category !== '__all__' && ctype_digit((string)$category)) {
        return [" AND $column = " . (int)$category, []];
    }
    return ['', []];
}

function all_categories(): array {
    return db()->query('SELECT * FROM ItemCategories ORDER BY CategoryName')->fetchAll();
}

function category_name(?int $id): string {
    if ($id === null) return '';
    $stmt = db()->prepare('SELECT CategoryName FROM ItemCategories WHERE CategoryID = ?');
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: '');
}

function category_label(?int $id): string {
    return $id === null ? 'No category' : (category_name($id) ?: 'Missing category');
}

function category_options_html($selected, bool $includeAll = false): string {
    $selected = (string)$selected;
    $out = '';
    if ($includeAll) {
        $out .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>All categories</option>';
    }
    $out .= '<option value="none"' . ($selected === 'none' ? ' selected' : '') . '>No category</option>';
    foreach (all_categories() as $c) {
        $val = (string)(int)$c['CategoryID'];
        $out .= '<option value="' . $val . '"' . ($selected === $val ? ' selected' : '') . '>'
              . h($c['CategoryName'])
              . ($c['CategoryNameLocal'] ? ' — ' . h($c['CategoryNameLocal']) : '')
              . '</option>';
    }
    return $out;
}

function category_select_field(string $name, $selected, string $attrs = ''): string {
    return '<select name="' . h($name) . '" ' . $attrs . '>'
         . category_options_html($selected)
         . '</select>';
}

// Gate for viewing/editing/deleting one specific record by ID: true if this
// user is allowed to touch a row whose Office column is $recordOffice.
function can_access_office(?string $recordOffice): bool {
    if (is_admin()) return true;
    $mine = current_user()['Office'] ?? null;
    return $mine !== null && $recordOffice !== null && $mine === $recordOffice;
}

// Renders an Office <select> (used on the Items/Health Facilities/GRN/Stock
// Card forms, admin only — staff never see this, their own office is applied
// automatically) with $selected pre-chosen.
function office_select_field(string $name, ?string $selected, string $extraAttrs = ''): string {
    $html = '<select name="' . h($name) . '" ' . $extraAttrs . '>';
    foreach (office_options() as $key => $label) {
        $sel = ($selected === $key) ? ' selected' : '';
        $html .= '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

// Short office code used inside auto-generated document numbers — Provincial
// Office and Main Office only, so this is a plain two-way switch rather than
// a third map to keep in sync with office_options().
function office_code(?string $office): string {
    return $office === 'main' ? 'MO' : 'PO';
}

// Suggests the next per-office item ID, e.g. "MO-01" (Main Office) or
// "PO-01" (Provincial Office) — a plain sequential number scoped to each
// office's own set of items, zero-padded to at least 2 digits. Unlike
// next_document_number() below (a suggested, freely-editable text default),
// this is the actual stored ItemCode, so it's based on the highest existing
// number for that office's prefix rather than a row count — that way a
// deleted item's code is never handed out again to a different item.
function next_item_code(?string $office): string {
    $prefix = office_code($office);
    $stmt = db()->prepare('SELECT ItemCode FROM Items WHERE Office = ? AND ItemCode LIKE ?');
    $stmt->execute([$office, $prefix . '-%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string)$code, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . '-' . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT);
}

// Suggests the next document number for a new GRN/Stock Card, e.g.
// "GRN-PO-2026-AUG-001" or "STC-MO-2026-AUG-014" — office code + the current
// year and month (so numbering naturally restarts at 001 each new month)
// + a zero-padded sequence number one past however many documents already
// exist for that office with that same prefix. This is only ever a
// *suggested* default pre-filled into an editable text field — nothing
// stops a user from typing something else, and nothing enforces uniqueness
// server-side (same as the field always worked before this).
function next_document_number(string $prefix, string $table, string $column, ?string $office): string {
    $base = $prefix . '-' . office_code($office) . '-' . date('Y') . '-' . strtoupper(date('M')) . '-';
    $stmt = db()->prepare("SELECT COUNT(*) FROM $table WHERE Office = ? AND $column LIKE ?");
    $stmt->execute([$office, $base . '%']);
    $seq = (int)$stmt->fetchColumn() + 1;
    return $base . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

// ==================== Printed subject line ====================
// The subject line printed under the document title on a stock card. The
// classic line below is what every card printed before per-card subjects
// existed (kept byte-for-byte — typo and all — so old cards print exactly
// as they always have). Cards created afterwards store their own Subject on
// the row (see StockReleases.Subject in config/db.php). The subject is
// editable on the stock card form for new AND existing cards, so an older
// card's line can be corrected later; saving with a blank field writes the
// classic line. Bulk imports are the exception — they only set Subject on
// cards they create and never rewrite an existing card's subject.

function release_subject_classic(): string {
    return 'Stationery adn Hygiene for the month of (July,Aug,Sep)2026';
}

// The subject to show/print for one card: its own stored Subject if it has
// a non-blank one, the classic line otherwise (cards created before the
// Subject column existed).
function release_subject(?string $stored): string {
    $stored = trim((string)($stored ?? ''));
    return $stored !== '' ? $stored : release_subject_classic();
}

// The subject of the most recent stock card in $office that has a non-blank
// Subject, or null if that office has never used one — used to pre-fill the
// editable "Printed subject" field when creating a new card or importing,
// so a month's cards stay consistent. $office === null matches nothing
// (Office = NULL is never true in SQL), so it safely just returns null.
function last_release_subject(?string $office): ?string {
    $stmt = db()->prepare("SELECT Subject FROM StockReleases WHERE Office = ? AND Subject IS NOT NULL AND TRIM(Subject) <> '' ORDER BY ReleaseID DESC LIMIT 1");
    $stmt->execute([$office]);
    $subject = trim((string)$stmt->fetchColumn());
    return $subject !== '' ? $subject : null;
}

// ==================== CSRF protection ====================
// Applied to the forms that change security-sensitive state: login, user
// management, and password changes.

function csrf_token(): string {
    session_start_once();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): bool {
    session_start_once();
    return isset($_POST['csrf_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf_token']);
}

// ==================== Backup & restore ====================
// The entire app's data lives in one SQLite file (see config/db.php). These
// helpers back it up — a self-contained point-in-time copy, safe to take
// while the app is in normal live use — and restore it either from one of
// those stored copies or from a file an admin uploads (e.g. pulled off a
// previous server). See backup.php / backup_download.php.

function backup_dir(): string {
    $dir = db_file_path_dir() . '/backups';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

// The folder the live database file lives in (data/), protected from direct
// web access by data/.htaccess — backups inherit that same protection since
// they live in a subfolder of it, with no .htaccess of their own to
// override it.
function db_file_path_dir(): string {
    return dirname(db_file_path());
}

// Every stored backup's filename is generated here — timestamp plus a short
// random suffix to avoid collisions — never taken verbatim from user input,
// so anything found inside backup_dir() can be trusted to have come from
// this feature, not from an upload landing somewhere unexpected.
function backup_filename(string $tag = ''): string {
    $stamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(2));
    return 'stock_management_' . ($tag !== '' ? $tag . '_' : '') . $stamp . '.sqlite';
}

// Point-in-time, consistent snapshot of the live database, written to a new
// file under backup_dir(). Uses SQLite's own VACUUM INTO (safe to run
// against a database that's in normal concurrent use) where available,
// falling back to a plain file copy on older SQLite builds that predate it.
function backup_create(string $tag = ''): string {
    $dest = backup_dir() . '/' . backup_filename($tag);
    try {
        db()->exec('VACUUM INTO ' . db()->quote($dest));
    } catch (Throwable $e) {
        if (!@copy(db_file_path(), $dest)) {
            throw new RuntimeException('Could not create a backup file: ' . $e->getMessage());
        }
    }
    return $dest;
}

// Every backup file, newest first.
function backup_list(): array {
    $files = glob(backup_dir() . '/*.sqlite') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return array_map(fn($f) => [
        'name' => basename($f),
        'size' => filesize($f),
        'mtime' => filemtime($f),
    ], $files);
}

// Resolves a backup filename coming from a form field back to a real path
// strictly inside backup_dir() — basename() strips any directory traversal,
// and the realpath()/prefix check refuses anything that doesn't genuinely
// resolve inside that folder (e.g. a symlink escape). Returns null if the
// name doesn't resolve to a real, existing file there.
function backup_resolve(string $name): ?string {
    $dir = realpath(backup_dir());
    $path = ($dir !== false) ? realpath($dir . '/' . basename($name)) : false;
    if ($dir === false || $path === false) return null;
    if (strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0) return null;
    return $path;
}

// Formats a byte count for display, e.g. "2.4 MB".
function backup_human_size(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// True (a validation message) if $path does NOT look like a genuine, intact
// copy of this app's database — wrong file header, fails SQLite's own
// integrity check, or missing the Users table this app depends on for
// login — null if it looks fine. Guards against restoring a corrupt,
// truncated, or unrelated file over the live database.
function backup_validate(string $path): ?string {
    if (!is_file($path) || filesize($path) < 100) {
        return 'That file is empty or too small to be a database backup.';
    }
    $header = @file_get_contents($path, false, null, 0, 16);
    if ($header !== "SQLite format 3\000") {
        return 'That file is not a SQLite database.';
    }
    try {
        $test = new PDO('sqlite:' . $path);
        $test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $check = $test->query('PRAGMA integrity_check')->fetchColumn();
        if (strtolower((string)$check) !== 'ok') {
            return 'This database file failed its integrity check and cannot be restored.';
        }
        $hasUsers = (int)$test->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='Users'")->fetchColumn();
        if (!$hasUsers) {
            return 'This does not look like a Stock Management backup (no Users table found).';
        }
    } catch (Throwable $e) {
        return 'Could not open this file as a database: ' . $e->getMessage();
    } finally {
        $test = null;
    }
    return null;
}

// Replaces the live database with $sourcePath, after first validating it
// and taking a safety snapshot of the live database (tagged
// "before-restore") so a bad restore can always be undone by restoring that
// snapshot in turn. Throws a RuntimeException with a user-facing message on
// any failure and leaves the live database untouched if it does.
function backup_restore(string $sourcePath): void {
    $problem = backup_validate($sourcePath);
    if ($problem !== null) {
        throw new RuntimeException($problem);
    }

    // Safety net first — if this fails, abort rather than risk an
    // unrecoverable restore.
    backup_create('before-restore');

    $liveDbPath = db_file_path();
    if (!@copy($sourcePath, $liveDbPath)) {
        throw new RuntimeException('Could not write the restored database file — check folder permissions on data/.');
    }
    // Clear any stray write-ahead-log/journal files left by the old
    // database so the next connection reads only the file just restored.
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        $stray = $liveDbPath . $suffix;
        if (is_file($stray)) @unlink($stray);
    }
}
