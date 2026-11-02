<?php
require __DIR__ . '/includes/functions.php';
require_login();
require __DIR__ . '/includes/xlsx_writer.php';

// Exports only the office currently in scope (an admin's ?office= filter, or
// a staff member's own office) — an admin with no filter selected exports
// every office's items combined, same as the on-screen "All offices" view.
$scope = office_scope();

$pdo = db();
$sql = '
    SELECT i.*,
           COALESCE(r.qty_in, 0)  AS TotalReceived,
           COALESCE(o.qty_out, 0) AS TotalReleased
    FROM Items i
    LEFT JOIN (SELECT ItemID, SUM(Quantity) qty_in FROM StockReceiptDetails GROUP BY ItemID) r ON r.ItemID = i.ItemID
    LEFT JOIN (SELECT ItemID, SUM(Quantity) qty_out FROM StockReleaseDetails GROUP BY ItemID) o ON o.ItemID = i.ItemID
';
// Sorted by the public Item # to match the Items list page — see the
// identical comment in items.php. ItemID is only an internal row address.
$codeOrder = 'i.ItemCode';
if ($scope === '__unassigned__') {
    $items = [];
} elseif ($scope !== null) {
    $stmt = $pdo->prepare($sql . " WHERE i.Office = ? ORDER BY $codeOrder");
    $stmt->execute([$scope]);
    $items = $stmt->fetchAll();
} else {
    $items = $pdo->query($sql . " ORDER BY $codeOrder")->fetchAll();
}

// Exactly ONE identifier column: the public "Item #". The old "Item ID"
// (internal row address) and "Item Code" (the retired per-office code) are
// gone — see stockapp_item_codes() in config/db.php.
$headers = ['Item #', 'Item Name', 'Item Name (Dari)', 'Description', 'Unit', 'Opening Qty In', 'Opening Qty Out', 'Total Received', 'Total Released', 'Current Balance'];

$rows = [];
$rows[] = [['v' => 'Items (' . count($items) . ')', 's' => 'title']];
$headerRow = [];
foreach ($headers as $hd) $headerRow[] = ['v' => $hd, 's' => 'header'];
$rows[] = $headerRow;
$headerRowNum = count($rows);

foreach ($items as $it) {
    $balance = (float)$it['TotalReceived'] - (float)$it['TotalReleased'];
    $rows[] = [
        ['v' => $it['ItemCode'] ?? ''],
        ['v' => $it['ItemName']],
        ['v' => $it['ItemNameLocal']],
        ['v' => $it['Description']],
        ['v' => $it['Unit']],
        ['v' => (float)$it['OpeningQtyIn'], 's' => 'number'],
        ['v' => (float)$it['OpeningQtyOut'], 's' => 'number'],
        ['v' => (float)$it['TotalReceived'], 's' => 'number'],
        ['v' => (float)$it['TotalReleased'], 's' => 'number'],
        ['v' => $balance, 's' => 'number'],
    ];
}
$lastRow = count($rows);

$sheet = [
    'name' => 'Items',
    'rows' => $rows,
    'colWidths' => [16, 26, 20, 26, 10, 13, 13, 12, 12, 12],
    'freeze' => ['x' => 0, 'y' => $headerRowNum],
    'autofilter' => 'A' . $headerRowNum . ':' . xlsx_col_letter(count($headers)) . $lastRow,
];

$tmpPath = tempnam(sys_get_temp_dir(), 'xlsx');
write_xlsx($tmpPath, [$sheet]);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
$officeSuffix = $scope !== null ? ('_' . preg_replace('/[^A-Za-z0-9]+/', '', office_label($scope))) : '';
header('Content-Disposition: attachment; filename="Items' . $officeSuffix . '_' . date('Y-m-d') . '.xlsx"');
header('Content-Length: ' . filesize($tmpPath));
header('Pragma: no-cache');
readfile($tmpPath);
unlink($tmpPath);
exit;
