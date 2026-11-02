<?php
require __DIR__ . '/includes/functions.php';
require_login();

$releaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$release = db()->prepare('
    SELECT sr.*, hf.HFName, hf.HFType, hf.DistrictID, d.DistrictName,
           cu.FullName AS CreatedByName, cu.Username AS CreatedByUsername,
           uu.FullName AS UpdatedByName, uu.Username AS UpdatedByUsername
    FROM StockReleases sr
    LEFT JOIN HealthFacilities hf ON hf.HFID = sr.HFID
    LEFT JOIN Districts d ON d.DistrictID = hf.DistrictID
    LEFT JOIN Users cu ON cu.UserID = sr.CreatedByUserID
    LEFT JOIN Users uu ON uu.UserID = sr.UpdatedByUserID
    WHERE sr.ReleaseID = ?
');
$release->execute([$releaseId]);
$release = $release->fetch();

if (!$release || !can_access_office($release['Office'] ?? null)) {
    flash_set('Stock card not found.');
    header('Location: releases');
    exit;
}

$lines = db()->prepare('
    SELECT rd.*, i.ItemCode, i.ItemName, i.ItemNameLocal, i.Description, i.Unit
    FROM StockReleaseDetails rd
    JOIN Items i ON i.ItemID = rd.ItemID
    WHERE rd.ReleaseID = ?
    ORDER BY rd.ItemID
');
$lines->execute([$releaseId]);
$lines = $lines->fetchAll();

$pageTitle = 'Stock Card ' . $release['StockCardNo'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Stock Card <?= h($release['StockCardNo']) ?></h1>
    <p>Stock Out record — items issued from the store.</p>
  </div>
  <div style="display:flex;gap:10px;">
    <a class="btn secondary" href="releases">&larr; Back to list</a>
    <a class="btn secondary" href="release_print?id=<?= $release['ReleaseID'] ?>" target="_blank">Print</a>
    <a class="btn" href="release_form?id=<?= $release['ReleaseID'] ?>">Edit stock card &amp; items</a>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Stock card details</h2></div>
  <div class="detail-grid">
    <div>
      <div class="label">Stock card number</div>
      <div class="value"><?= h($release['StockCardNo']) ?></div>
    </div>
    <div>
      <div class="label">Release date</div>
      <div class="value"><?= $release['ReleaseDate'] ? h($release['ReleaseDate']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Receiving facility</div>
      <div class="value">
        <?php if ($release['HFName']): ?>
          <?= h($release['HFName']) ?><?php if ($release['DistrictName']): ?> <span class="muted">— <?= h($release['DistrictName']) ?></span><?php endif; ?>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <div class="label">Facility type</div>
      <div class="value"><?= $release['HFType'] ? h($release['HFType']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Receiver name</div>
      <div class="value"><?= $release['ReceiverName'] ? h($release['ReceiverName']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Storekeeper</div>
      <div class="value"><?= $release['StorekeeperName'] ? h($release['StorekeeperName']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Group</div>
      <div class="value"><?= $release['GroupName'] ? h($release['GroupName']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Project</div>
      <div class="value"><?= h(project_label($release['ProjectID'] ?? null)) ?></div>
    </div>
    <div>
      <div class="label">Printed subject</div>
      <!-- The card's current stored subject — including any edit made later
           on the stock card form — or the classic line for old cards that
           have nothing stored yet. Exactly what Print will show. -->
      <div class="value"><?= h(release_subject($release['Subject'] ?? null)) ?></div>
    </div>
    <?php if (is_admin()): ?>
    <div>
      <div class="label">Office</div>
      <div class="value"><?= h(office_label($release['Office'] ?? null)) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="detail-grid" style="padding-top:0;">
    <div>
      <div class="label">Created</div>
      <div class="value muted" style="font-size:13px;font-weight:500;">
        <?php if ($release['CreatedAt']): ?>
          <?= h($release['CreatedByName'] ?: $release['CreatedByUsername'] ?: 'Unknown user') ?> — <?= h(substr($release['CreatedAt'], 0, 16)) ?>
        <?php else: ?>
          — <span title="Created before user accounts were added">(before user tracking)</span>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <div class="label">Last updated</div>
      <div class="value muted" style="font-size:13px;font-weight:500;">
        <?php if ($release['UpdatedAt']): ?>
          <?= h($release['UpdatedByName'] ?: $release['UpdatedByUsername'] ?: 'Unknown user') ?> — <?= h(substr($release['UpdatedAt'], 0, 16)) ?>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Items issued</h2>
    <span class="muted"><?= count($lines) ?> line item(s)</span>
  </div>
  <table>
    <thead>
      <tr><th>Item #</th><th>Item Name</th><th>Item Name (Dari)</th><th>Description</th><th>Unit</th><th class="num">Quantity</th></tr>
    </thead>
    <tbody>
      <?php if (!$lines): ?>
        <tr><td colspan="6" class="muted">No items recorded for this stock card.</td></tr>
      <?php endif; ?>
      <?php foreach ($lines as $line): ?>
        <tr>
          <td class="muted"><?= h($line['ItemCode'] ?? '') ?></td>
          <td><?= h($line['ItemName']) ?></td>
          <td class="muted"><?= $line['ItemNameLocal'] ? h($line['ItemNameLocal']) : '—' ?></td>
          <td class="muted"><?= h($line['Description']) ?></td>
          <td><?= h($line['Unit']) ?></td>
          <td class="num"><?= number_format($line['Quantity'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="actions">
  <a class="btn" href="release_form?id=<?= $release['ReleaseID'] ?>">Edit this stock card</a>
  <a class="btn secondary" href="release_print?id=<?= $release['ReleaseID'] ?>" target="_blank">Print this stock card</a>
  <a class="btn secondary" href="releases">Back to all Release cards</a>
  <form method="post" action="releases" style="display:inline;" onsubmit="return confirm('Delete this stock card and all its item lines? This cannot be undone.')">
    <?= csrf_field() ?>
    <input type="hidden" name="delete_release" value="<?= $release['ReleaseID'] ?>">
    <button type="submit" class="btn danger">Delete this stock card</button>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
