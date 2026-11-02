<?php
require __DIR__ . '/includes/functions.php';
require_login();
require __DIR__ . '/includes/xlsx_writer.php';

// One file, two separate export abilities — the GRN section exports GRN
// details (GRN list + per-GRN item quantities), the Cards section exports
// Card details (Card list + per-card item quantities). Nothing is mixed.
$type = (($_GET['type'] ?? 'grn') === 'card') ? 'card' : 'grn';

// Scopes every export below to the office currently in scope (an admin's
// ?office= filter, or a staff member's own office) — an admin with no
// filter selected exports every office's data combined.
$scope = office_scope();
$scopedOut = ($scope === '__unassigned__');

$pdo = db();

if ($type === 'grn') {
    // ==================== GRN details export ====================
    if ($scopedOut) {
        $grnList = [];
    } elseif ($scope !== null) {
        $stmt = $pdo->prepare('
            SELECT r.GRNNumber, r.ReceiptDate, r.Supplier, r.GroupName,
                   (SELECT COUNT(*) FROM StockReceiptDetails WHERE ReceiptID=r.ReceiptID) AS LineItems
            FROM StockReceipts r WHERE r.Office = ? ORDER BY r.ReceiptID
        ');
        $stmt->execute([$scope]);
        $grnList = $stmt->fetchAll();
    } else {
        $grnList = $pdo->query('
            SELECT r.GRNNumber, r.ReceiptDate, r.Supplier, r.GroupName,
                   (SELECT COUNT(*) FROM StockReceiptDetails WHERE ReceiptID=r.ReceiptID) AS LineItems
            FROM StockReceipts r ORDER BY r.ReceiptID
        ')->fetchAll();
    }

    $itemsSql = '
        SELECT i.ItemID, i.ItemName, i.ItemNameLocal, i.Description, i.Unit,
               COALESCE(r.qty_in, 0) AS TotalReceived
        FROM Items i
        LEFT JOIN (SELECT ItemID, SUM(Quantity) qty_in FROM StockReceiptDetails GROUP BY ItemID) r ON r.ItemID = i.ItemID
    ';
    if ($scopedOut) {
        $items = [];
    } elseif ($scope !== null) {
        $stmt = $pdo->prepare($itemsSql . ' WHERE i.Office = ? ORDER BY i.ItemID');
        $stmt->execute([$scope]);
        $items = $stmt->fetchAll();
    } else {
        $items = $pdo->query($itemsSql . ' ORDER BY i.ItemID')->fetchAll();
    }

    // Unscoped by design — only $docOrder (built from the already-scoped
    // $grnList below) and $items (already scoped above) are ever used to
    // read from this lookup, so an other-office GRN/item pair is simply
    // never looked up, never rendered.
    $matrixRows = $pdo->query('
        SELECT rd.ItemID, r.GRNNumber, SUM(rd.Quantity) AS Quantity
        FROM StockReceiptDetails rd
        JOIN StockReceipts r ON r.ReceiptID = rd.ReceiptID
        GROUP BY rd.ItemID, r.GRNNumber
    ')->fetchAll();

    $docOrder = array_column($grnList, 'GRNNumber');
    $qtyLookup = [];
    foreach ($matrixRows as $m) {
        $qtyLookup[$m['ItemID'] . '|' . $m['GRNNumber']] = $m['Quantity'];
    }

    // --- Sheet 1: GRN list ---
    $sheet1Rows = [];
    $sheet1Rows[] = [['v' => 'GRNs (' . count($grnList) . ')', 's' => 'title']];
    $sheet1Rows[] = [
        ['v' => 'GRN Number', 's' => 'header'], ['v' => 'Group', 's' => 'header'], ['v' => 'Receipt Date', 's' => 'header'],
        ['v' => 'Supplier', 's' => 'header'], ['v' => 'Line Items', 's' => 'header'],
    ];
    $headerRowNum = count($sheet1Rows);
    foreach ($grnList as $g) {
        $sheet1Rows[] = [
            ['v' => $g['GRNNumber']], ['v' => $g['GroupName']], ['v' => $g['ReceiptDate']],
            ['v' => $g['Supplier']], ['v' => (int)$g['LineItems'], 's' => 'number'],
        ];
    }
    $lastRow = count($sheet1Rows);

    $sheet1 = [
        'name' => 'GRN List',
        'rows' => $sheet1Rows,
        'colWidths' => [22, 10, 14, 22, 12],
        'freeze' => ['x' => 0, 'y' => $headerRowNum],
        'autofilter' => 'A' . $headerRowNum . ':E' . $lastRow,
    ];

    $metaHeaders = ['Item ID', 'Item Name', 'Item Name (Dari)', 'Description', 'Unit', 'Total Qty Received'];
    $totalsField = 'TotalReceived';
    $filenamePrefix = 'GRN_Details';
} else {
    // ==================== Stock Card details export ====================
    $cardSql = '
        SELECT sr.StockCardNo, sr.ReleaseDate, hf.HFName, d.DistrictName, sr.ReceiverName, sr.GroupName,
               (SELECT COUNT(*) FROM StockReleaseDetails WHERE ReleaseID=sr.ReleaseID) AS LineItems
        FROM StockReleases sr
        LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
        LEFT JOIN Districts d ON d.DistrictID = hf.DistrictID
    ';
    if ($scopedOut) {
        $cardList = [];
    } elseif ($scope !== null) {
        $stmt = $pdo->prepare($cardSql . ' WHERE sr.Office = ? ORDER BY sr.ReleaseID');
        $stmt->execute([$scope]);
        $cardList = $stmt->fetchAll();
    } else {
        $cardList = $pdo->query($cardSql . ' ORDER BY sr.ReleaseID')->fetchAll();
    }

    $itemsSql = '
        SELECT i.ItemID, i.ItemName, i.ItemNameLocal, i.Description, i.Unit,
               COALESCE(o.qty_out, 0) AS TotalOut
        FROM Items i
        LEFT JOIN (SELECT ItemID, SUM(Quantity) qty_out FROM StockReleaseDetails GROUP BY ItemID) o ON o.ItemID = i.ItemID
    ';
    if ($scopedOut) {
        $items = [];
    } elseif ($scope !== null) {
        $stmt = $pdo->prepare($itemsSql . ' WHERE i.Office = ? ORDER BY i.ItemID');
        $stmt->execute([$scope]);
        $items = $stmt->fetchAll();
    } else {
        $items = $pdo->query($itemsSql . ' ORDER BY i.ItemID')->fetchAll();
    }

    // Unscoped by design — same reasoning as the GRN branch above: only
    // $docOrder (from the scoped $cardList) and $items (already scoped) are
    // ever used to read from this lookup.
    $matrixRows = $pdo->query('
        SELECT rd.ItemID, sr.StockCardNo, SUM(rd.Quantity) AS Quantity
        FROM StockReleaseDetails rd
        JOIN StockReleases sr ON sr.ReleaseID = rd.ReleaseID
        GROUP BY rd.ItemID, sr.StockCardNo
    ')->fetchAll();

    $docOrder = array_column($cardList, 'StockCardNo');
    $qtyLookup = [];
    foreach ($matrixRows as $m) {
        $qtyLookup[$m['ItemID'] . '|' . $m['StockCardNo']] = $m['Quantity'];
    }

    // --- Sheet 1: Stock Cards list ---
    $sheet1Rows = [];
    $sheet1Rows[] = [['v' => 'Stock Cards (' . count($cardList) . ')', 's' => 'title']];
    $sheet1Rows[] = [
        ['v' => 'Stock Card No', 's' => 'header'], ['v' => 'Group', 's' => 'header'], ['v' => 'Release Date', 's' => 'header'],
        ['v' => 'Facility', 's' => 'header'], ['v' => 'District', 's' => 'header'],
        ['v' => 'Receiver Name', 's' => 'header'], ['v' => 'Line Items', 's' => 'header'],
    ];
    $headerRowNum = count($sheet1Rows);
    foreach ($cardList as $c) {
        $sheet1Rows[] = [
            ['v' => $c['StockCardNo']], ['v' => $c['GroupName']], ['v' => $c['ReleaseDate']],
            ['v' => $c['HFName']], ['v' => $c['DistrictName']],
            ['v' => $c['ReceiverName']], ['v' => (int)$c['LineItems'], 's' => 'number'],
        ];
    }
    $lastRow = count($sheet1Rows);

    $sheet1 = [
        'name' => 'Stock Cards List',
        'rows' => $sheet1Rows,
        'colWidths' => [22, 10, 14, 22, 16, 20, 12],
        'freeze' => ['x' => 0, 'y' => $headerRowNum],
        'autofilter' => 'A' . $headerRowNum . ':G' . $lastRow,
    ];

    $metaHeaders = ['Item ID', 'Item Name', 'Item Name (Dari)', 'Description', 'Unit', 'Total Qty Out'];
    $totalsField = 'TotalOut';
    $filenamePrefix = 'StockCards_Details';
}

// --- Sheet 2: Items — master data + total + per-document quantity matrix ---
$nMeta = count($metaHeaders);

$headerRow = [];
foreach ($metaHeaders as $h) $headerRow[] = ['v' => $h, 's' => 'header'];
foreach ($docOrder as $docNo) $headerRow[] = ['v' => $docNo, 's' => 'header_rotated'];

$sheet2Rows = [$headerRow];
foreach ($items as $it) {
    $row = [
        ['v' => $it['ItemID']],
        ['v' => $it['ItemName']],
        ['v' => $it['ItemNameLocal']],
        ['v' => $it['Description']],
        ['v' => $it['Unit']],
        ['v' => $it[$totalsField], 's' => 'number'],
    ];
    foreach ($docOrder as $docNo) {
        $qty = $qtyLookup[$it['ItemID'] . '|' . $docNo] ?? null;
        $row[] = $qty !== null ? ['v' => $qty, 's' => 'number'] : null;
    }
    $sheet2Rows[] = $row;
}

$metaWidths = [9, 26, 20, 26, 10, 16];
$colWidths = $metaWidths;
foreach ($docOrder as $docNo) $colWidths[] = 6;

$sheet2 = [
    'name' => 'Items',
    'rows' => $sheet2Rows,
    'colWidths' => $colWidths,
    'rowHeights' => [1 => 150],
    'freeze' => ['x' => $nMeta, 'y' => 1],
    'autofilter' => 'A1:' . xlsx_col_letter($nMeta + count($docOrder)) . count($sheet2Rows),
];

$tmpPath = tempnam(sys_get_temp_dir(), 'xlsx');
write_xlsx($tmpPath, [$sheet1, $sheet2]);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
$officeSuffix = $scope !== null ? ('_' . preg_replace('/[^A-Za-z0-9]+/', '', office_label($scope))) : '';
header('Content-Disposition: attachment; filename="' . $filenamePrefix . $officeSuffix . '_' . date('Y-m-d') . '.xlsx"');
header('Content-Length: ' . filesize($tmpPath));
header('Pragma: no-cache');
readfile($tmpPath);
unlink($tmpPath);
exit;
