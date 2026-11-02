<?php
// Production-safe error handling: never show raw PHP errors/paths to visitors
// (a real information-disclosure risk on a public host), but keep logging them
// so problems are still diagnosable. Safe to leave on for local testing too —
// the built-in PHP dev server still prints logged errors to its own console.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
ini_set('error_log', $logDir . '/php-error.log');

// The single source of truth for where the live database file lives — used
// by db() below and by the backup/restore helpers in includes/functions.php.
function db_file_path(): string {
    return __DIR__ . '/../data/stock_management.sqlite';
}

// Single PDO SQLite connection, shared by every page.
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = db_file_path();
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        stockapp_migrate($pdo);
    }
    return $pdo;
}

// ==================== The ONE item number ("Item #") ====================
// Warehousing rule: an item has exactly ONE number people see, write on a
// bin label and type into a spreadsheet. It belongs to one project AND one
// office, so each (project, office) pair owns an independent sequence:
//
//   P1-MO-0001   project 1, Main Office
//   P1-PO-0001   project 1, Provincial Office
//   NP-MO-0001   No project, Main Office ("NP" = No Project)
//   NP-PO-0001   No project, Provincial Office
//
// The integer Items.ItemID stays ONLY as the database's technical primary
// key for StockReceiptDetails / StockReleaseDetails (8,878+ rows point at
// it). It is never printed, never typed and never exported. Items.ItemNo
// (the old Excel numbers) is retired: kept as a dormant column so an older
// backup still restores cleanly, but no page reads or writes it any more.
// See also next_item_code() / normalize_item_code() in includes/functions.php.

function item_code_prefix($projectId, ?string $office): string {
    $tag = ($projectId === null || $projectId === '') ? 'NP' : 'P' . (int)$projectId;
    return $tag . '-' . ($office === 'main' ? 'MO' : 'PO');
}

// One-time, deterministic conversion of every legacy item number (old
// "MO-01" / "PO-01" codes, blank codes, and the retired ItemNo values) to
// the single Item # format above. Deterministic means it is safe to run
// again: ordering by project, office then ItemID always produces the same
// numbers, so restoring an old backup can never shuffle anyone's codes.
// Also creates the UNIQUE index that makes the business key a real database
// constraint instead of just a convention in PHP.
function stockapp_item_codes(PDO $pdo): void {
    $rows = $pdo->query('SELECT ItemID, ItemCode, ProjectID, Office FROM Items ORDER BY COALESCE(ProjectID, 0), Office, ItemID')->fetchAll();

    $needsConversion = false;
    foreach ($rows as $row) {
        if (!preg_match('/^(?:P\d+|NP)-(?:MO|PO)-\d{4,}$/', (string)($row['ItemCode'] ?? ''))) {
            $needsConversion = true;
            break;
        }
    }

    if ($needsConversion) {
        $pdo->beginTransaction();
        try {
            // Two phases. Clearing first means the UNIQUE index (if it was
            // already created on a previous run) never sees two rows briefly
            // holding the same number while the new ones are being assigned.
            $pdo->exec('UPDATE Items SET ItemCode = NULL');
            $upd = $pdo->prepare('UPDATE Items SET ItemCode = ? WHERE ItemID = ?');
            $seq = [];
            foreach ($rows as $row) {
                $prefix = item_code_prefix($row['ProjectID'], $row['Office']);
                $seq[$prefix] = ($seq[$prefix] ?? 0) + 1;
                $upd->execute([
                    $prefix . '-' . str_pad((string)$seq[$prefix], 4, '0', STR_PAD_LEFT),
                    $row['ItemID'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Partial index: NULL/blank rows are allowed (they are fixed on the next
    // connection), but two real item numbers can never be the same.
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS UQ_Items_ItemCode
                ON Items(ItemCode)
                WHERE ItemCode IS NOT NULL AND TRIM(ItemCode) <> ''");
}

// Idempotent, self-healing schema migration — runs on every connection but
// only ever touches something the first time it's missing, so it's safe to
// ship as part of a normal update rather than needing a separate manual step.
function stockapp_migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Users (
            UserID INTEGER PRIMARY KEY AUTOINCREMENT,
            Username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            PasswordHash TEXT NOT NULL,
            FullName TEXT,
            Role TEXT NOT NULL DEFAULT 'staff',
            Active INTEGER NOT NULL DEFAULT 1,
            MustChangePassword INTEGER NOT NULL DEFAULT 0,
            CreatedAt TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // Audit + grouping columns on GRNs and Stock Cards — added only if missing.
    // GroupName tags a GRN/Stock Card with a free-text group label for organizing the lists.
    $auditCols = [
        'CreatedByUserID' => 'INTEGER',
        'UpdatedByUserID' => 'INTEGER',
        'CreatedAt' => 'TEXT',
        'UpdatedAt' => 'TEXT',
        'GroupName' => 'TEXT',
    ];
    foreach (['StockReceipts', 'StockReleases'] as $table) {
        $existing = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        foreach ($auditCols as $col => $type) {
            if (!in_array($col, $existing, true)) {
                $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $type);
            }
        }
    }

    // Subject — the free-text line printed at the top of a stock card
    // (e.g. the classic "Stationery adn Hygiene for the month of ..."). It's
    // stored when the card is created and can be edited later on the stock
    // card form, for existing and old cards alike (see release_form.php,
    // whose UPDATE includes it). Bulk imports never rewrite it — they only
    // set it on cards they create. Rows created before this column exists
    // keep NULL and print the classic line as a fallback — see
    // release_subject() in functions.php.
    $stockReleasesExists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='StockReleases'")->fetchColumn();
    if ($stockReleasesExists) {
        $existingReleaseCols = array_column($pdo->query('PRAGMA table_info(StockReleases)')->fetchAll(), 'name');
        if (!in_array('Subject', $existingReleaseCols, true)) {
            $pdo->exec('ALTER TABLE StockReleases ADD COLUMN Subject TEXT');
        }
    }

    // Projects — an org-wide programme that a piece of work belongs to. An
    // item, a GRN or a stock card can each be filed under ONE project, or left
    // with none: NULL is where everything created before projects existed
    // stays ("No project"), so old records are never moved or rewritten.
    // Projects span both offices — the office split still applies to every
    // record exactly as before; a project is an extra, independent dimension.
    // Because a GRN/card carries its own ProjectID and may only contain items
    // of that same project, each project's stock is completely separate.
    $pdo->exec('CREATE TABLE IF NOT EXISTS Projects (
        ProjectID INTEGER PRIMARY KEY AUTOINCREMENT,
        ProjectName TEXT NOT NULL,
        Description TEXT,
        CreatedByUserID INTEGER,
        CreatedAt TEXT,
        UpdatedByUserID INTEGER,
        UpdatedAt TEXT
    )');
    foreach (['Items', 'StockReceipts', 'StockReleases'] as $projectTable) {
        $existingCols = array_column($pdo->query('PRAGMA table_info(' . $projectTable . ')')->fetchAll(), 'name');
        if (!in_array('ProjectID', $existingCols, true)) {
            $pdo->exec('ALTER TABLE ' . $projectTable . ' ADD COLUMN ProjectID INTEGER');
        }
    }

    // Item categories — the owner's "بخش بندی" of the item master, e.g.
    // consumables vs. non-medical equipment. An item belongs to at most ONE
    // category; NULL means "No category", which is where every item created
    // before categories existed stays. Categories describe ITEMS only — a GRN
    // or stock card can hold lines of several categories, so documents are
    // never filtered by category, only item-based reports are.
    $categoriesExisted = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ItemCategories'")->fetchColumn();
    $pdo->exec('CREATE TABLE IF NOT EXISTS ItemCategories (
        CategoryID INTEGER PRIMARY KEY AUTOINCREMENT,
        CategoryName TEXT NOT NULL,
        CategoryNameLocal TEXT,
        CreatedAt TEXT
    )');
    if (!$categoriesExisted) {
        // Seed the two sections the owner asked for, once, on a brand-new
        // install only — never resurrecting categories someone deleted.
        $pdo->exec("INSERT INTO ItemCategories (CategoryName, CategoryNameLocal, CreatedAt) VALUES
            ('Consumables', 'مواد مصرفی', datetime('now')),
            ('Non-medical equipment', 'وسایل غیر طبی', datetime('now'))");
    }
    $existingItemCols = array_column($pdo->query('PRAGMA table_info(Items)')->fetchAll(), 'name');
    if (!in_array('CategoryID', $existingItemCols, true)) {
        $pdo->exec('ALTER TABLE Items ADD COLUMN CategoryID INTEGER');
    }

    // Login throttling — tracks consecutive failed sign-in attempts per
    // account and a temporary lockout expiry, so a password can't just be
    // brute-forced by retrying forever. See the login_* helpers in functions.php.
    $existingUserCols = array_column($pdo->query('PRAGMA table_info(Users)')->fetchAll(), 'name');
    if (!in_array('FailedLoginAttempts', $existingUserCols, true)) {
        $pdo->exec('ALTER TABLE Users ADD COLUMN FailedLoginAttempts INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('LockedUntil', $existingUserCols, true)) {
        $pdo->exec('ALTER TABLE Users ADD COLUMN LockedUntil TEXT');
    }

    // Office split — every operational record belongs to either the
    // Provincial Office or the Main Office ('provincial' / 'main'), and every
    // staff account is assigned to one of the two so its data is
    // automatically tagged and scoped without them ever picking it by hand.
    // Admins aren't restricted by their own Office (if any) — see
    // office_scope()/can_access_office() in functions.php.
    //
    // Users.Office is left NULL for every account that existed before this
    // column was added (including the seeded admin) — nothing is guessed on
    // an account's behalf; an admin assigns each one's office from the Users
    // page. A staff account with no Office assigned sees no records at all
    // (fail-closed) until it's assigned one.
    if (!in_array('Office', $existingUserCols, true)) {
        $pdo->exec('ALTER TABLE Users ADD COLUMN Office TEXT');
    }

    // Items/HealthFacilities/StockReceipts/StockReleases: existing rows
    // predate the office split, so there's no per-row way to know which
    // office they actually belonged to. Defaulting them to 'main' (rather
    // than leaving them NULL and invisible to everyone but admin) keeps
    // today's data usable by whichever office turns out to be "Main" —
    // review it via the Office filter on each list and move anything that's
    // actually Provincial using that record's Office field.
    foreach (['Items', 'HealthFacilities', 'StockReceipts', 'StockReleases'] as $table) {
        $tableExists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
        if (!$tableExists) continue;
        $cols = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        if (!in_array('Office', $cols, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN Office TEXT DEFAULT 'main'");
        }
    }

    // Province — a free-text field on Health Facilities, separate from
    // District. Districts here are all within one province by default, but a
    // facility like a Main Office can sit in a different province entirely,
    // so this isn't tied to the Districts list the way DistrictID is.
    $hfTableExists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='HealthFacilities'")->fetchColumn();
    if ($hfTableExists) {
        $existingHfCols = array_column($pdo->query('PRAGMA table_info(HealthFacilities)')->fetchAll(), 'name');
        if (!in_array('Province', $existingHfCols, true)) {
            $pdo->exec('ALTER TABLE HealthFacilities ADD COLUMN Province TEXT');
        }
    }

    // LowStockLevel — a per-item minimum balance the store keeper sets by hand
    // (e.g. Item A's cutoff is 10, Item B's is 3). The Items list compares each
    // item's current balance against its own value here to show a Low/Out of
    // stock status, rather than applying one cutoff to every item.
    $itemsTableExists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Items'")->fetchColumn();
    if ($itemsTableExists) {
        $existingItemCols = array_column($pdo->query('PRAGMA table_info(Items)')->fetchAll(), 'name');
        if (!in_array('LowStockLevel', $existingItemCols, true)) {
            $pdo->exec('ALTER TABLE Items ADD COLUMN LowStockLevel REAL');
        }

        // ItemCode is now the ONE public "Item #". It is just a column here —
        // the format, the one-time conversion of old numbers and the UNIQUE
        // constraint all live in stockapp_item_codes() at the end of this
        // migration, after ProjectID and Office are guaranteed to exist.
        // The retired ItemNo column is deliberately left in place (dormant)
        // so restoring an older backup still works; nothing reads it.
        if (!in_array('ItemCode', $existingItemCols, true)) {
            $pdo->exec('ALTER TABLE Items ADD COLUMN ItemCode TEXT');
        }
    }

    // Seed one admin account the very first time this runs, so there's a way
    // to log in at all before any accounts exist. Forces a password change on
    // first login rather than leaving the seeded password in place.
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM Users')->fetchColumn();
    if ($userCount === 0) {
        $hash = password_hash('Aaiwo#Stock2026', PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO Users (Username, PasswordHash, FullName, Role, MustChangePassword) VALUES (?,?,?,?,1)')
            ->execute(['admin', $hash, 'Administrator', 'admin']);
    }

    // Last, once ProjectID/Office/ItemCode all exist: give every item its one
    // public number and enforce uniqueness. See the block at the top of this
    // file for the format and the why.
    stockapp_item_codes($pdo);
}
