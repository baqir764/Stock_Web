<?php
require __DIR__ . '/includes/functions.php';
require_login();

$releaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$release = db()->prepare('
    SELECT sr.*, hf.HFName, hf.HFType, hf.DistrictID, d.DistrictName
    FROM StockReleases sr
    LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
    LEFT JOIN Districts d ON d.DistrictID = hf.DistrictID
    WHERE sr.ReleaseID = ?
');
$release->execute([$releaseId]);
$release = $release->fetch();

if (!$release || !can_access_office($release['Office'] ?? null)) {
    http_response_code(404);
    echo 'Stock card not found.';
    exit;
}

$lines = db()->prepare('
    SELECT rd.*, i.ItemName, i.ItemNameLocal, i.Description, i.Unit
    FROM StockReleaseDetails rd
    JOIN Items i ON i.ItemID = rd.ItemID
    WHERE rd.ReleaseID = ?
    ORDER BY rd.ItemID
');
$lines->execute([$releaseId]);
$lines = $lines->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock Card <?= h($release['StockCardNo']) ?> — Print</title>
<style>
  @page { size: A4; margin: 16mm 14mm; }
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    color: #1B2621;
    margin: 0;
    background: #fff;
  }
  .bar {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 10px 16px;
    background: #F1F3F0;
    border-bottom: 1px solid #DEE3DD;
  }
  .bar button, .bar a {
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 600;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
  }
  .bar button { background: #0E5C56; color: #fff; border: none; }
  .bar a { background: #fff; color: #1B2621; border: 1px solid #DEE3DD; }
  .sheet { max-width: 780px; margin: 24px auto; padding: 0 16px 40px; }
  .doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-bottom: 2px solid #0E5C56;
    padding-bottom: 10px;
    margin-bottom: 18px;
  }
  .doc-header-brand { display: flex; align-items: center; gap: 12px; }
  .doc-logo { width: 52px; height: 52px; object-fit: contain; flex-shrink: 0; }
  .org-name { font-size: 15px; font-weight: 700; color: #0A413D; letter-spacing: .02em; }
  .org-full { font-size: 10.5px; font-weight: 600; color: #52625A; text-transform: uppercase; letter-spacing: .03em; margin: 1px 0 6px; }
  .doc-title { font-size: 20px; font-weight: 700; color: #0A413D; }
  table.meta { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  table.meta th, table.meta td { border: 1px solid #DEE3DD; padding: 8px 10px; font-size: 13px; text-align: left; }
  table.meta th { background: #F1F3F0; width: 130px; font-weight: 600; color: #52625A; }
  table.items { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 26px; }
  table.items th, table.items td { border: 1px solid #DEE3DD; padding: 6px 9px; text-align: left; }
  table.items th { background: #F1F3F0; font-weight: 600; color: #52625A; text-transform: uppercase; font-size: 11px; letter-spacing: .03em; }
  table.items td.num, table.items th.num { text-align: right; font-variant-numeric: tabular-nums; }
  table.items tbody tr:nth-child(even) { background: #FAFBF9; }
  .sign-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-top: 48px; }
  .sign-line { border-top: 1px solid #1B2621; margin-top: 46px; }
  .sign-label { font-size: 12px; color: #52625A; margin-top: 6px; }
  @media print {
    .no-print { display: none !important; }
    .sheet { margin: 0; max-width: none; padding: 0; }
  }
</style>
</head>
<body>
  <div class="bar no-print">
    <button onclick="window.print()">Print</button>
    <a href="release_view?id=<?= (int)$release['ReleaseID'] ?>">&larr; Back to stock card</a>
  </div>

  <div class="sheet">
    <div class="doc-header">
      <div class="doc-header-brand">
        <img src="assets/logo-color.png" alt="AAIWO" class="doc-logo">
        <div>
          <div class="org-name">AAIWO</div>
          <div class="org-full">Afghan Alfouz Innovational Welfare Organization</div>
          <div class="doc-title">Stock Release Card</div>
          <!-- The card's own stored subject, editable later on the stock card
               form. Old cards from before per-card subjects exist have a NULL
               Subject and fall back to the classic line (see
               release_subject()). -->
          <div class="org-full"> <b>Subject:</b> <?= h(release_subject($release['Subject'] ?? null)) ?></div>
        </div>
      </div>
    </div>

    <table class="meta">
      <tr>
        <th>Stock Card No</th><td><?= h($release['StockCardNo']) ?></td>
        <th>Date</th><td><?= $release['ReleaseDate'] ? h($release['ReleaseDate']) : '—' ?></td>
      </tr>
      <tr>
        <th>Receiving Facility</th>
        <td>
          <?php if ($release['HFName']): ?>
            <?= h($release['HFName']) ?><?php if ($release['DistrictName']): ?> — <?= h($release['DistrictName']) ?><?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <th>Facility Type</th>
        <td><?= $release['HFType'] ? h($release['HFType']) : '—' ?></td>
      </tr>
      <tr>
        <th>Receiver Name</th><td colspan="3"><?= $release['ReceiverName'] ? h($release['ReceiverName']) : '—' ?></td>
      </tr>
      <?php if (($release['ProjectID'] ?? null) !== null): ?>
      <tr>
        <th>Project</th><td colspan="3"><?= h(project_name((int)$release['ProjectID'])) ?></td>
      </tr>
      <?php endif; ?>
    </table>

    <table class="items">
      <thead>
        <tr>
          <th>#</th><th>Item Name</th><th>Item Name (Dari)</th><th>Description</th><th>Unit</th><th class="num">Quantity</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$lines): ?>
          <tr><td colspan="6">No items recorded for this stock card.</td></tr>
        <?php endif; ?>
        <?php foreach ($lines as $i => $line): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= h($line['ItemName']) ?></td>
            <td><?= $line['ItemNameLocal'] ? h($line['ItemNameLocal']) : '—' ?></td>
            <td><?= $line['Description'] ? h($line['Description']) : '—' ?></td>
            <td><?= h($line['Unit']) ?></td>
            <td class="num"><?= number_format($line['Quantity'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="sign-grid">
      <div><div class="sign-line"></div><div class="sign-label">Released by (Store Keeper)<?= $release['StorekeeperName'] ? ' — ' . h($release['StorekeeperName']) : '' ?></div></div>
      <div><div class="sign-line"></div><div class="sign-label">Received by (Facility In-charge)</div></div>
    </div>
  </div>

  <script>
    window.addEventListener('load', function () { window.print(); });
  </script>
</body>
</html>
