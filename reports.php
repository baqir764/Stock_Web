<?php
require __DIR__ . '/includes/functions.php';
require_admin();

$pageTitle = 'Reports';
require __DIR__ . '/includes/header.php';

$report = $_GET['report'] ?? 'balance';
$scope = office_scope();
// Every report on this page can be limited to one project (or to the "No
// project" group). Projects are the owner's way of keeping each programme's
// stock and its reporting completely separate.
$project = project_scope();
$category = category_scope();
[$pSql, $pParams] = project_condition('sr.ProjectID', $project);
// Categories describe items, so inside the movement queries the test goes on
// the joined Items row (alias it). Document lists are never filtered by
// category: one GRN or stock card can hold items of several categories.
$cSql = $category === '__all__' ? '' : ($category === 'none' ? ' AND it.CategoryID IS NULL' : (' AND it.CategoryID = ' . (int)$category));
// Query-string tail carrying the chosen project through tab links, the
// office dropdown and the item picker.
$projQs = $project === '__all__' ? '' : '&project=' . urlencode((string)$project);
$catQs = $category === '__all__' ? '' : '&category=' . urlencode((string)$category);

// ==================== Date-range filter ====================
// Shared by the date-aware reports (By Date, Releases by Facility and the
// Item Ledger). Dates are stored as plain 'YYYY-MM-DD' TEXT columns, so a
// string comparison IS a correct date comparison — no date functions needed.
// An empty side means "open ended": from the beginning, or up to today.
// Shape AND calendar check: '2026-13-99' matches the shape but is not a real
// day, and treating it as a date would silently hide every record. An
// unusable value is therefore dropped, i.e. treated as "not provided".
$dateOk = static function (string $v): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1) {
        return '';
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : '';
};
$fromRaw = $dateOk(trim((string)($_GET['from'] ?? '')));
$toRaw = $dateOk(trim((string)($_GET['to'] ?? '')));
$datesSwapped = false;
if ($fromRaw !== '' && $toRaw !== '' && $fromRaw > $toRaw) {
    // Someone picked the newer date first — quietly put them the right way
    // round instead of showing an empty report.
    [$fromRaw, $toRaw] = [$toRaw, $fromRaw];
    $datesSwapped = true;
}
$hasPeriod = ($fromRaw !== '' || $toRaw !== '');

// The By Date report opens on "this month so far" so there is something
// sensible on screen; &all=1 (the Clear dates link) shows every date instead.
if ($report === 'dates' && !$hasPeriod && !isset($_GET['all'])) {
    $fromRaw = date('Y-m-01');
    $toRaw = date('Y-m-d');
    $hasPeriod = true;
}

// Bounds used inside SQL. Open ends become sentinels that always compare
// true, so one query shape covers every from/to combination.
$fromEff = $fromRaw !== '' ? $fromRaw : '0000-01-01';
$toEff = $toRaw !== '' ? $toRaw : '9999-12-31';

// Human-readable period line, printed on the report itself so a paper copy
// says which dates it covers.
$dateNice = static fn(string $d): string => date('j M Y', strtotime($d));
$periodLabel = $hasPeriod
    ? ($fromRaw !== '' ? $dateNice($fromRaw) : 'Beginning') . ' — ' . ($toRaw !== '' ? $dateNice($toRaw) : 'present')
    : 'All dates';

// Query-string tail that carries the chosen dates through every tab link,
// the office dropdown and the item picker, so a period survives navigation.
$dateQs = ($fromRaw !== '' ? '&from=' . urlencode($fromRaw) : '')
        . ($toRaw !== '' ? '&to=' . urlencode($toRaw) : '');

$balances = ($scope === '__unassigned__') ? [] : stock_balance($scope, $project, $category);

// ==================== Releases by facility (optionally within the period) ==
$periodSql = $hasPeriod ? ' AND sr.ReleaseDate BETWEEN :pf AND :pt' : '';
if ($scope === '__unassigned__') {
    $byHF = [];
} elseif ($scope !== null) {
    $byHF = db()->prepare('
        SELECT hf.HFName, d.DistrictName, COUNT(DISTINCT sr.ReleaseID) AS card_count
        FROM StockReleaseDetails srd
        JOIN StockReleases sr ON sr.ReleaseID = srd.ReleaseID
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        LEFT JOIN Districts d ON d.DistrictID = hf.DistrictID
        WHERE sr.Office = :office' . $periodSql . $pSql . '
        GROUP BY hf.HFID
        ORDER BY card_count DESC
    ');
    $byHF->execute(['office' => $scope] + ($hasPeriod ? ['pf' => $fromEff, 'pt' => $toEff] : []));
    $byHF = $byHF->fetchAll();
} else {
    $byHF = db()->prepare('
        SELECT hf.HFName, d.DistrictName, COUNT(DISTINCT sr.ReleaseID) AS card_count
        FROM StockReleaseDetails srd
        JOIN StockReleases sr ON sr.ReleaseID = srd.ReleaseID
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        LEFT JOIN Districts d ON d.DistrictID = hf.DistrictID
        WHERE 1=1' . $periodSql . $pSql . '
        GROUP BY hf.HFID
        ORDER BY card_count DESC
    ');
    $byHF->execute($hasPeriod ? ['pf' => $fromEff, 'pt' => $toEff] : []);
    $byHF = $byHF->fetchAll();
}

// ==================== By Date report ====================
// Three views of the chosen period: what arrived (GRNs), what went out
// (stock cards), and per item: opening balance + in − out = closing balance.
$receivedIn = [];
$releasedIn = [];
$movement = [];
$totIn = 0.0;
$totOut = 0.0;

if ($report === 'dates' && $scope !== '__unassigned__') {
    $officeSql = $scope !== null ? ' AND sr.Office = :office' : '';
    $officeVal = $scope !== null ? ['office' => $scope] : [];
    $bounds = ['pf' => $fromEff, 'pt' => $toEff];

    $stmt = db()->prepare('
        SELECT sr.ReceiptID, sr.GRNNumber, sr.ReceiptDate, sr.Supplier, sr.Office,
               SUM(rd.Quantity) AS Qty, COUNT(DISTINCT rd.ItemID) AS ItemCount
        FROM StockReceiptDetails rd
        JOIN StockReceipts sr ON sr.ReceiptID = rd.ReceiptID
        WHERE sr.ReceiptDate BETWEEN :pf AND :pt' . $officeSql . $pSql . '
        GROUP BY sr.ReceiptID
        ORDER BY sr.ReceiptDate, sr.ReceiptID
    ');
    $stmt->execute($bounds + $officeVal);
    $receivedIn = $stmt->fetchAll();

    $stmt = db()->prepare('
        SELECT sr.ReleaseID, sr.StockCardNo, sr.ReleaseDate, sr.ReceiverName, sr.Office,
               COALESCE(hf.HFName, sr.ReceiverName) AS Party,
               SUM(rd.Quantity) AS Qty, COUNT(DISTINCT rd.ItemID) AS ItemCount
        FROM StockReleaseDetails rd
        JOIN StockReleases sr ON sr.ReleaseID = rd.ReleaseID
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        WHERE sr.ReleaseDate BETWEEN :pf AND :pt' . $officeSql . $pSql . '
        GROUP BY sr.ReleaseID
        ORDER BY sr.ReleaseDate, sr.ReleaseID
    ');
    $stmt->execute($bounds + $officeVal);
    $releasedIn = $stmt->fetchAll();

    // Per-item aggregates: quantities before the period (the opening balance)
    // and inside it. Rows with no date at all count as "before", never as
    // inside the period — an unknown date can't be claimed for a period.
    $stmt = db()->prepare('
        SELECT rd.ItemID,
               SUM(CASE WHEN COALESCE(sr.ReceiptDate, \'0000-01-01\') < :pf1 THEN rd.Quantity ELSE 0 END) AS BeforeIn,
               SUM(CASE WHEN sr.ReceiptDate BETWEEN :pf2 AND :pt2 THEN rd.Quantity ELSE 0 END) AS InPeriod
        FROM StockReceiptDetails rd
        JOIN StockReceipts sr ON sr.ReceiptID = rd.ReceiptID
        JOIN Items it ON it.ItemID = rd.ItemID
        WHERE 1=1' . $officeSql . $pSql . $cSql . '
        GROUP BY rd.ItemID
    ');
    $stmt->execute(['pf1' => $fromEff, 'pf2' => $fromEff, 'pt2' => $toEff] + $officeVal);
    foreach ($stmt->fetchAll() as $r) {
        $movement[(int)$r['ItemID']]['beforeIn'] = (float)$r['BeforeIn'];
        $movement[(int)$r['ItemID']]['in'] = (float)$r['InPeriod'];
    }
    $stmt = db()->prepare('
        SELECT rd.ItemID,
               SUM(CASE WHEN COALESCE(sr.ReleaseDate, \'0000-01-01\') < :pf1 THEN rd.Quantity ELSE 0 END) AS BeforeOut,
               SUM(CASE WHEN sr.ReleaseDate BETWEEN :pf2 AND :pt2 THEN rd.Quantity ELSE 0 END) AS OutPeriod
        FROM StockReleaseDetails rd
        JOIN StockReleases sr ON sr.ReleaseID = rd.ReleaseID
        JOIN Items it ON it.ItemID = rd.ItemID
        WHERE 1=1' . $officeSql . $pSql . $cSql . '
        GROUP BY rd.ItemID
    ');
    $stmt->execute(['pf1' => $fromEff, 'pf2' => $fromEff, 'pt2' => $toEff] + $officeVal);
    foreach ($stmt->fetchAll() as $r) {
        $movement[(int)$r['ItemID']]['beforeOut'] = (float)$r['BeforeOut'];
        $movement[(int)$r['ItemID']]['out'] = (float)$r['OutPeriod'];
    }

    // Keep only items that actually moved (or had stock) around the period,
    // and look up their names/units in one go.
    $names = [];
    $nameStmt = db()->prepare('SELECT ItemID, ItemCode, ItemName, Unit, Office, CategoryID FROM Items');
    $nameStmt->execute();
    foreach ($nameStmt->fetchAll() as $it) {
        $names[(int)$it['ItemID']] = $it;
    }
    $movement = array_filter($movement, static fn(array $m): bool =>
        ($m['beforeIn'] - $m['beforeOut']) != 0.0 || $m['in'] != 0.0 || $m['out'] != 0.0);
    ksort($movement);

    foreach ($receivedIn as $r) $totIn += (float)$r['Qty'];
    foreach ($releasedIn as $r) $totOut += (float)$r['Qty'];
}

// ==================== Item ledger — trace one item across every GRN it was
// received on and every stock card it was released against ====================
$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$itemsForPicker = ($scope === '__unassigned__') ? [] : all_items($scope, $project, $category);
$selectedItem = null;
$ledger = [];
$totalIn = 0.0;
$totalOut = 0.0;
$openingBalance = 0.0; // balance just before the period, when one is chosen

if ($report === 'item' && $itemId > 0) {
    $stmt = db()->prepare('SELECT * FROM Items WHERE ItemID = ?');
    $stmt->execute([$itemId]);
    $found = $stmt->fetch();

    if ($found && can_access_office($found['Office'] ?? null)
        && ($scope === null || ($found['Office'] ?? null) === $scope)
        && ($project === '__all__' || ($project === 'none' ? ($found['ProjectID'] ?? null) === null : (int)($found['ProjectID'] ?? -1) === (int)$project))
        && ($category === '__all__' || ($category === 'none' ? ($found['CategoryID'] ?? null) === null : (int)($found['CategoryID'] ?? -1) === (int)$category))) {
        $selectedItem = $found;

        // With a period chosen, the ledger lists only that period's movements
        // and the running Balance column starts from the balance the item had
        // when the period opened (so the column still adds up correctly).
        // Placeholder names must be unique inside one statement (PDO/SQLite
        // binds by name), hence pf1/pt1 on the GRN side and pf2/pt2 on the
        // stock-card side of the UNION.
        $inPeriodSql = $hasPeriod ? ' AND sr.ReceiptDate BETWEEN :pf1 AND :pt1' : '';
        $outPeriodSql = $hasPeriod ? ' AND sr.ReleaseDate BETWEEN :pf2 AND :pt2' : '';
        $ledgerStmt = db()->prepare('
            SELECT * FROM (
                SELECT \'in\' AS DocType, sr.ReceiptID AS RefID, sr.GRNNumber AS DocNo, sr.ReceiptDate AS DocDate,
                       sr.Supplier AS Party, sr.Office AS Office, rd.Quantity AS Qty
                FROM StockReceiptDetails rd
                JOIN StockReceipts sr ON sr.ReceiptID = rd.ReceiptID
                WHERE rd.ItemID = :item1' . ($scope !== null ? ' AND sr.Office = :office1' : '') . $inPeriodSql . $pSql . '
                UNION ALL
                SELECT \'out\' AS DocType, sr.ReleaseID AS RefID, sr.StockCardNo AS DocNo, sr.ReleaseDate AS DocDate,
                       COALESCE(hf.HFName, sr.ReceiverName) AS Party, sr.Office AS Office, rd.Quantity AS Qty
                FROM StockReleaseDetails rd
                JOIN StockReleases sr ON sr.ReleaseID = rd.ReleaseID
                LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
                WHERE rd.ItemID = :item2' . ($scope !== null ? ' AND sr.Office = :office2' : '') . $outPeriodSql . $pSql . '
            ) ledger
            ORDER BY COALESCE(DocDate, \'9999-99-99\') ASC, DocType ASC, RefID ASC
        ');
        $params = ['item1' => $itemId, 'item2' => $itemId];
        if ($scope !== null) {
            $params['office1'] = $scope;
            $params['office2'] = $scope;
        }
        if ($hasPeriod) {
            $params['pf1'] = $fromEff;
            $params['pt1'] = $toEff;
            $params['pf2'] = $fromEff;
            $params['pt2'] = $toEff;
        }
        $ledgerStmt->execute($params);
        $ledger = $ledgerStmt->fetchAll();

        foreach ($ledger as $row) {
            if ($row['DocType'] === 'in') {
                $totalIn += (float)$row['Qty'];
            } else {
                $totalOut += (float)$row['Qty'];
            }
        }

        if ($fromRaw !== '') {
            $stmt = db()->prepare('
                SELECT COALESCE(SUM(rd.Quantity), 0) FROM StockReceiptDetails rd
                JOIN StockReceipts sr ON sr.ReceiptID = rd.ReceiptID
                WHERE rd.ItemID = ? AND COALESCE(sr.ReceiptDate, \'0000-01-01\') < ?'
                . ($scope !== null ? ' AND sr.Office = ?' : '') . $pSql);
            $stmt->execute($scope !== null ? [$itemId, $fromRaw, $scope] : [$itemId, $fromRaw]);
            $beforeIn = (float)$stmt->fetchColumn();
            $stmt = db()->prepare('
                SELECT COALESCE(SUM(rd.Quantity), 0) FROM StockReleaseDetails rd
                JOIN StockReleases sr ON sr.ReleaseID = rd.ReleaseID
                WHERE rd.ItemID = ? AND COALESCE(sr.ReleaseDate, \'0000-01-01\') < ?'
                . ($scope !== null ? ' AND sr.Office = ?' : '') . $pSql);
            $stmt->execute($scope !== null ? [$itemId, $fromRaw, $scope] : [$itemId, $fromRaw]);
            $openingBalance = $beforeIn - (float)$stmt->fetchColumn();
        }
    }
}
?>
<div class="page-head">
  <div>
    <h1>Reports</h1>
    <p>Printable summaries<?= is_admin() ? h($scope === null ? ' — all offices' : (' — ' . office_label($scope))) : '' ?><?= $project !== '__all__' ? h(' — ' . project_label($project === 'none' ? null : $project)) : '' ?><?= $category !== '__all__' ? h(' — ' . category_label($category === 'none' ? null : $category)) : '' ?>.</p>
  </div>
  <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <?php if (is_admin()): ?>
      <select class="search-box" style="min-width:170px;" onchange="location.href='reports?report=<?= h($report) ?>'+(this.value?('&office='+this.value):'')<?= ($report === 'item' && $itemId) ? "+'&item_id=" . $itemId . "'" : '' ?>+'<?= h($dateQs . $projQs . $catQs) ?>'">
        <option value="">All offices (combined)</option>
        <?php foreach (office_options() as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $scope === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select class="search-box" style="min-width:170px;" onchange="location.href='reports?report=<?= h($report) ?>'+(this.value?('&project='+this.value):'')<?= $scope !== null ? "+'&office=" . urlencode($scope) . "'" : '' ?><?= ($report === 'item' && $itemId) ? "+'&item_id=" . $itemId . "'" : '' ?>+'<?= h($dateQs . $catQs) ?>'">
      <?= project_options_html($project === '__all__' ? '' : $project, true) ?>
    </select>
    <select class="search-box" style="min-width:170px;" onchange="location.href='reports?report=<?= h($report) ?>'+(this.value?('&category='+this.value):'')<?= $scope !== null ? "+'&office=" . urlencode($scope) . "'" : '' ?><?= ($report === 'item' && $itemId) ? "+'&item_id=" . $itemId . "'" : '' ?>+'<?= h($dateQs . $projQs) ?>'">
      <?= category_options_html($category === '__all__' ? '' : $category, true) ?>
    </select>
    <a class="btn secondary small <?= $report==='balance'?'active':'' ?>" href="reports?report=balance<?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?><?= h($dateQs . $projQs . $catQs) ?>">Stock Balance</a>
    <a class="btn secondary small <?= $report==='dates'?'active':'' ?>" href="reports?report=dates<?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?><?= h($dateQs . $projQs . $catQs) ?>">By Date</a>
    <a class="btn secondary small <?= $report==='byhf'?'active':'' ?>" href="reports?report=byhf<?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?><?= h($dateQs . $projQs . $catQs) ?>">Releases by Facility</a>
    <a class="btn secondary small <?= $report==='item'?'active':'' ?>" href="reports?report=item<?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?><?= h($dateQs . $projQs . $catQs) ?>">Item Ledger</a>
    <button type="button" class="btn small" onclick="window.print()" title="Print this report, or use it to save as PDF">Print</button>
  </div>
</div>

<?php if ($scope === '__unassigned__'): ?>
  <div class="flash no-print" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);">Your account has no office assigned yet — contact an administrator to assign you to the Provincial Office or Main Office.</div>
<?php endif; ?>

<?php if ($report !== 'balance' && $scope !== '__unassigned__'): ?>
  <!-- Date-range bar. Kept off the printed page (no-print); the chosen period
       is repeated inside each report's heading, where it DOES print. -->
  <div class="panel no-print" style="margin-bottom:16px;">
    <form method="get" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;padding:16px 22px;">
      <input type="hidden" name="report" value="<?= h($report) ?>">
      <?php if ($scope !== null): ?><input type="hidden" name="office" value="<?= h($scope) ?>"><?php endif; ?>
      <?php if ($project !== '__all__'): ?><input type="hidden" name="project" value="<?= h((string)$project) ?>"><?php endif; ?>
      <?php if ($category !== '__all__'): ?><input type="hidden" name="category" value="<?= h((string)$category) ?>"><?php endif; ?>
      <?php if ($report === 'item' && $itemId): ?><input type="hidden" name="item_id" value="<?= (int)$itemId ?>"><?php endif; ?>
      <div class="field" style="margin-bottom:0;">
        <label>From date</label>
        <input type="date" name="from" value="<?= h($fromRaw) ?>">
      </div>
      <div class="field" style="margin-bottom:0;">
        <label>To date</label>
        <input type="date" name="to" value="<?= h($toRaw) ?>">
      </div>
      <button class="btn small" type="submit">Show report</button>
      <a class="btn secondary small" href="reports?report=<?= h($report) ?><?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?><?php if ($report === 'item' && $itemId): ?>&item_id=<?= (int)$itemId ?><?php endif; ?><?php if ($report === 'dates'): ?>&all=1<?php endif; ?><?= h($projQs . $catQs) ?>">Clear dates</a>
      <span class="muted" style="font-size:12px;">Leave a side empty for "from the beginning" or "up to today". Showing: <strong><?= h($periodLabel) ?></strong></span>
    </form>
    <?php if ($datesSwapped): ?>
      <div class="muted" style="padding:0 22px 12px;font-size:12px;">The two dates were the wrong way round, so they were swapped for you.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($report === 'byhf'): ?>
  <div class="panel">
    <div class="panel-head">
      <h2>Stock cards issued, by health facility</h2>
      <?php if ($hasPeriod): ?><span class="muted">Period: <?= h($periodLabel) ?></span><?php endif; ?>
      <?php if ($category !== '__all__'): ?><span class="muted">Category filtering is not applied here — a stock card can hold items of several categories.</span><?php endif; ?>
    </div>
    <table>
      <thead><tr><th>Facility</th><th>District</th><th class="num">Stock cards</th></tr></thead>
      <tbody>
        <?php foreach ($byHF as $r): ?>
          <tr>
            <td><?= h($r['HFName'] ?: 'Unmatched / Provincial Office / Hospital') ?></td>
            <td><?= h($r['DistrictName']) ?></td>
            <td class="num"><?= (int)$r['card_count'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php elseif ($report === 'dates'): ?>
  <?php if ($scope === '__unassigned__'): ?>
    <div class="flash" style="background:var(--amber-tint);border-color:#EAD2A8;color:var(--amber);">No office is assigned to your account, so there is nothing to report on.</div>
  <?php else: ?>
  <div class="panel">
    <div class="panel-head">
      <h2>Stock movement by date</h2>
      <span class="muted">Period: <?= h($periodLabel) ?><?= is_admin() ? h($scope === null ? ' — all offices' : (' — ' . office_label($scope))) : '' ?></span>
    </div>
    <div class="detail-grid">
      <div>
        <div class="label">Received in period</div>
        <div class="value"><?= number_format($totIn, 2) ?> (<?= count($receivedIn) ?> GRN<?= count($receivedIn) === 1 ? '' : 's' ?>)</div>
      </div>
      <div>
        <div class="label">Released in period</div>
        <div class="value"><?= number_format($totOut, 2) ?> (<?= count($releasedIn) ?> card<?= count($releasedIn) === 1 ? '' : 's' ?>)</div>
      </div>
      <div>
        <div class="label">Net change</div>
        <div class="value"><strong><?= number_format($totIn - $totOut, 2) ?></strong></div>
      </div>
    </div>
  </div>

  <?php if ($category !== '__all__'): ?>
    <p class="muted" style="margin:0 0 10px;">The document lists below show whole GRNs and stock cards (they can mix categories). Category filtering applies to the per-item table, Stock Balance and the Item Ledger.</p>
  <?php endif; ?>
  <div class="panel">
    <div class="panel-head"><h2>Received (GRNs) in the period</h2></div>
    <table>
      <thead><tr><th>Date</th><th>GRN number</th><th>Supplier</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th class="num">Item lines</th><th class="num">Total qty</th></tr></thead>
      <tbody>
        <?php if (!$receivedIn): ?>
          <tr><td colspan="<?= is_admin() ? 6 : 5 ?>" class="muted">Nothing was received in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($receivedIn as $r): ?>
          <tr>
            <td class="muted"><?= $r['ReceiptDate'] ? h($r['ReceiptDate']) : '—' ?></td>
            <td><a href="receipt_view?id=<?= (int)$r['ReceiptID'] ?>"><?= h($r['GRNNumber']) ?></a></td>
            <td><?= $r['Supplier'] ? h($r['Supplier']) : '<span class="muted">—</span>' ?></td>
            <?php if (is_admin()): ?><td class="muted"><?= h(office_label($r['Office'] ?? null)) ?></td><?php endif; ?>
            <td class="num"><?= (int)$r['ItemCount'] ?></td>
            <td class="num"><?= number_format((float)$r['Qty'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Released (stock cards) in the period</h2></div>
    <table>
      <thead><tr><th>Date</th><th>Stock card</th><th>Facility / receiver</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th class="num">Item lines</th><th class="num">Total qty</th></tr></thead>
      <tbody>
        <?php if (!$releasedIn): ?>
          <tr><td colspan="<?= is_admin() ? 6 : 5 ?>" class="muted">Nothing was released in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($releasedIn as $r): ?>
          <tr>
            <td class="muted"><?= $r['ReleaseDate'] ? h($r['ReleaseDate']) : '—' ?></td>
            <td><a href="release_view?id=<?= (int)$r['ReleaseID'] ?>"><?= h($r['StockCardNo']) ?></a></td>
            <td><?= $r['Party'] ? h($r['Party']) : '<span class="muted">—</span>' ?></td>
            <?php if (is_admin()): ?><td class="muted"><?= h(office_label($r['Office'] ?? null)) ?></td><?php endif; ?>
            <td class="num"><?= (int)$r['ItemCount'] ?></td>
            <td class="num"><?= number_format((float)$r['Qty'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <div class="panel-head">
      <h2>Per item — opening, movement, closing</h2>
      <span class="muted">Balance at the start of the period, plus what came in and went out inside it.</span>
    </div>
    <table>
      <thead><tr><th>Item #</th><th>Item</th><th>Unit</th><th>Category</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th class="num">Opening</th><th class="num">Received</th><th class="num">Released</th><th class="num">Closing</th></tr></thead>
      <tbody>
        <?php if (!$movement): ?>
          <tr><td colspan="<?= is_admin() ? 9 : 8 ?>" class="muted">No stock movement in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($movement as $id => $m):
          $opening = $m['beforeIn'] - $m['beforeOut'];
          $closing = $opening + $m['in'] - $m['out'];
          $item = $names[$id] ?? null;
        ?>
          <tr>
            <td class="muted"><?= h($item['ItemCode'] ?? '') ?></td>
            <td><a href="reports?report=item&item_id=<?= (int)$id ?><?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?><?= h($dateQs . $projQs . $catQs) ?>"><?= h($item['ItemName'] ?? ('Item ' . $id)) ?></a></td>
            <td><?= h($item['Unit'] ?? '') ?></td>
            <td><?= h(category_label((int)($item['CategoryID'] ?? 0) ?: null)) ?></td>
            <?php if (is_admin()): ?><td class="muted"><?= h(office_label($item['Office'] ?? null)) ?></td><?php endif; ?>
            <td class="num"><?= number_format($opening, 2) ?></td>
            <td class="num"><?= number_format($m['in'], 2) ?></td>
            <td class="num"><?= number_format($m['out'], 2) ?></td>
            <td class="num"><strong><?= number_format($closing, 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php elseif ($report === 'item'): ?>
  <div class="panel">
    <div class="panel-head">
      <h2>Item ledger — trace receipts &amp; releases</h2>
      <span class="muted">Find every GRN an item was received on, and every stock card it was released against.<?= $hasPeriod ? ' Period: ' . h($periodLabel) : '' ?></span>
    </div>
    <div class="no-print" style="padding:18px 22px;border-bottom:1px solid var(--line);">
      <div class="field" style="max-width:420px;margin-bottom:0;">
        <label>Item</label>
        <select onchange="location.href='reports?report=item'+(this.value?('&item_id='+this.value):'')<?= $scope !== null ? "+'&office=" . urlencode($scope) . "'" : '' ?>+'<?= h($dateQs . $projQs . $catQs) ?>'">
          <option value="">— Select an item —</option>
          <?php foreach ($itemsForPicker as $it): ?>
            <option value="<?= (int)$it['ItemID'] ?>" <?= $itemId === (int)$it['ItemID'] ? 'selected' : '' ?>>
              <?= h(($it['ItemCode'] ?? '') !== '' ? $it['ItemCode'] . ' — ' : '') ?><?= h($it['ItemName']) ?><?= $it['ItemNameLocal'] ? ' — ' . h($it['ItemNameLocal']) : '' ?><?= is_admin() ? ' (' . h(office_label($it['Office'] ?? null)) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($itemId && $selectedItem): ?>
      <div class="detail-grid">
        <div>
          <div class="label">Item #</div>
          <div class="value"><?= h($selectedItem['ItemCode'] ?? '') ?></div>
        </div>
        <div>
          <div class="label">Item</div>
          <div class="value"><?= h($selectedItem['ItemName']) ?></div>
        </div>
        <div>
          <div class="label">Local name</div>
          <div class="value"><?= $selectedItem['ItemNameLocal'] ? h($selectedItem['ItemNameLocal']) : '<span class="muted">—</span>' ?></div>
        </div>
        <div>
          <div class="label">Unit</div>
          <div class="value"><?= h($selectedItem['Unit']) ?></div>
        </div>
        <?php if (is_admin()): ?>
        <div>
          <div class="label">Office</div>
          <div class="value"><?= h(office_label($selectedItem['Office'] ?? null)) ?></div>
        </div>
        <?php endif; ?>
        <div>
          <div class="label"><?= $hasPeriod ? 'Received in period' : 'Total received' ?></div>
          <div class="value"><?= number_format($totalIn, 2) ?></div>
        </div>
        <div>
          <div class="label"><?= $hasPeriod ? 'Released in period' : 'Total released' ?></div>
          <div class="value"><?= number_format($totalOut, 2) ?></div>
        </div>
        <?php if ($hasPeriod && $fromRaw !== ''): ?>
        <div>
          <div class="label">Opening balance</div>
          <div class="value"><?= number_format($openingBalance, 2) ?></div>
        </div>
        <?php endif; ?>
        <div>
          <div class="label"><?= $hasPeriod ? ($toRaw !== '' ? 'Balance on ' . h($dateNice($toRaw)) : 'Balance at end of period') : 'Current balance' ?></div>
          <div class="value"><strong><?= number_format($openingBalance + $totalIn - $totalOut, 2) ?></strong></div>
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Movement</th>
            <th>Document</th>
            <th>Party</th>
            <?php if (is_admin()): ?><th>Office</th><?php endif; ?>
            <th class="num">Received</th>
            <th class="num">Released</th>
            <th class="num">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$ledger): ?>
            <tr><td colspan="<?= is_admin() ? 8 : 7 ?>" class="muted"><?= $hasPeriod ? 'No GRNs or stock cards reference this item inside the chosen period.' : 'No GRNs or stock cards reference this item yet.' ?></td></tr>
          <?php endif; ?>
          <?php $running = $openingBalance; foreach ($ledger as $row):
            $qty = (float)$row['Qty'];
            $running += ($row['DocType'] === 'in') ? $qty : -$qty;
          ?>
            <tr>
              <td class="muted"><?= $row['DocDate'] ? h($row['DocDate']) : '—' ?></td>
              <td><?= $row['DocType'] === 'in' ? 'Received (GRN)' : 'Released (Stock Card)' ?></td>
              <td><a href="<?= $row['DocType'] === 'in' ? 'receipt_view?id=' . (int)$row['RefID'] : 'release_view?id=' . (int)$row['RefID'] ?>"><?= h($row['DocNo']) ?></a></td>
              <td><?= $row['Party'] ? h($row['Party']) : '<span class="muted">—</span>' ?></td>
              <?php if (is_admin()): ?><td class="muted"><?= h(office_label($row['Office'] ?? null)) ?></td><?php endif; ?>
              <td class="num"><?= $row['DocType'] === 'in' ? number_format($qty, 2) : '' ?></td>
              <td class="num"><?= $row['DocType'] === 'out' ? number_format($qty, 2) : '' ?></td>
              <td class="num"><?= number_format($running, 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php elseif ($itemId): ?>
      <div class="flash" style="margin:18px 22px;background:var(--red-tint);border-color:#E3B7B1;color:var(--red);">That item wasn't found, or isn't in the office you're viewing.</div>
    <?php else: ?>
      <p class="muted" style="padding:0 22px 20px;">Pick an item above — for example "Pen (Blue)" — to see exactly which GRN(s) it was received on and which stock card(s) it was released against. Add dates above to see only a period, with the balance it started and ended with.</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="panel">
    <div class="panel-head">
      <h2>Stock balance — received vs. released</h2>
      <span class="muted">A live snapshot of right now — this one report is never limited by the dates above.</span>
    </div>
    <table>
      <thead><tr><th>Item #</th><th>Item</th><th>Unit</th><th>Category</th><?php if (is_admin()): ?><th>Office</th><?php endif; ?><th class="num">Received</th><th class="num">Released</th><th class="num">Balance</th></tr></thead>
      <tbody>
        <?php foreach ($balances as $b): ?>
          <tr>
            <td class="muted"><?= h($b['ItemCode'] ?? '') ?></td>
            <td><a href="reports?report=item&item_id=<?= (int)$b['ItemID'] ?><?= $scope !== null ? ('&office=' . urlencode($scope)) : '' ?><?= h($dateQs . $projQs . $catQs) ?>"><?= h($b['ItemName']) ?></a></td>
            <td><?= h($b['Unit']) ?></td>
            <td><?= h(category_label((int)($b['CategoryID'] ?? 0) ?: null)) ?></td>
            <?php if (is_admin()): ?><td class="muted"><?= h(office_label($b['Office'] ?? null)) ?></td><?php endif; ?>
            <td class="num"><?= number_format($b['TotalReceived']) ?></td>
            <td class="num"><?= number_format($b['TotalReleased']) ?></td>
            <td class="num"><strong><?= number_format($b['CurrentBalance']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
