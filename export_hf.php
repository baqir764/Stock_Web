<?php
require __DIR__ . '/includes/functions.php';
require_login();
require __DIR__ . '/includes/xlsx_writer.php';

$scope = office_scope();
$hfs = ($scope === '__unassigned__') ? [] : all_health_facilities($scope);

$headers = ['HF ID', 'HF Name', 'Province', 'District', 'HF Type', 'In Charge Person'];

$rows = [];
$rows[] = [['v' => 'Health Facilities (' . count($hfs) . ')', 's' => 'title']];
$headerRow = [];
foreach ($headers as $hd) $headerRow[] = ['v' => $hd, 's' => 'header'];
$rows[] = $headerRow;
$headerRowNum = count($rows);

foreach ($hfs as $hf) {
    $rows[] = [
        ['v' => (int)$hf['HFID']],
        ['v' => $hf['HFName']],
        ['v' => $hf['Province']],
        ['v' => $hf['DistrictName']],
        ['v' => $hf['HFType']],
        ['v' => $hf['InChargePerson']],
    ];
}
$lastRow = count($rows);

$sheet = [
    'name' => 'Health Facilities',
    'rows' => $rows,
    'colWidths' => [8, 26, 14, 16, 10, 22],
    'freeze' => ['x' => 0, 'y' => $headerRowNum],
    'autofilter' => 'A' . $headerRowNum . ':' . xlsx_col_letter(count($headers)) . $lastRow,
];

$tmpPath = tempnam(sys_get_temp_dir(), 'xlsx');
write_xlsx($tmpPath, [$sheet]);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
$officeSuffix = $scope !== null ? ('_' . preg_replace('/[^A-Za-z0-9]+/', '', office_label($scope))) : '';
header('Content-Disposition: attachment; filename="HealthFacilities' . $officeSuffix . '_' . date('Y-m-d') . '.xlsx"');
header('Content-Length: ' . filesize($tmpPath));
header('Pragma: no-cache');
readfile($tmpPath);
unlink($tmpPath);
exit;
