<?php
require __DIR__ . '/includes/functions.php';
// Schema dump — diagnostic only, so it's admin-gated rather than reachable by
// every staff account. Fine to delete this file entirely once you no longer
// need it for troubleshooting.
require_admin();
$db = db();
echo "--- Items Table ---\n";
foreach($db->query('PRAGMA table_info(Items)') as $row) {
    echo $row['name'] . " (" . $row['type'] . ")\n";
}
echo "\n--- StockReceipts Table ---\n";
foreach($db->query('PRAGMA table_info(StockReceipts)') as $row) {
    echo $row['name'] . " (" . $row['type'] . ")\n";
}
echo "\n--- StockReleases Table ---\n";
foreach($db->query('PRAGMA table_info(StockReleases)') as $row) {
    echo $row['name'] . " (" . $row['type'] . ")\n";
}
