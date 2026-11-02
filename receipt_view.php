<?php
require __DIR__ . '/includes/functions.php';
require_login();

$receiptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$receipt = db()->prepare('
    SELECT sr.*,
           cu.FullName AS CreatedByName, cu.Username AS CreatedByUsername,
           uu.FullName AS UpdatedByName, uu.Username AS UpdatedByUsername
    FROM StockReceipts sr
    LEFT JOIN Users cu ON cu.UserID = sr.CreatedByUserID
    LEFT JOIN Users uu ON uu.UserID = sr.UpdatedByUserID
    WHERE sr.ReceiptID = ?
');
$receipt->execute([$receiptId]);
$receipt = $receipt->fetch();

if (!$receipt || !can_access_office($receipt['Office'] ?? null)) {
    flash_set('GRN not found.');
    header('Location: receipts');
    exit;
}

$lines = db()->prepare('
    SELECT rd.*, i.ItemCode, i.ItemName, i.ItemNameLocal, i.Description, i.Unit
    FROM StockReceiptDetails rd
    JOIN Items i ON i.ItemID = rd.ItemID
    WHERE rd.ReceiptID = ?
    ORDER BY rd.ItemID
');
$lines->execute([$receiptId]);
$lines = $lines->fetchAll();

$pageTitle = 'GRN ' . $receipt['GRNNumber'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>GRN <?= h($receipt['GRNNumber']) ?></h1>
    <p>Goods Received Note details — Stock In record.</p>
  </div>
  <div style="display:flex;gap:10px;">
    <a class="btn secondary" href="receipts">&larr; Back to list</a>
    <a class="btn secondary" href="receipt_print?id=<?= $receipt['ReceiptID'] ?>" target="_blank">Print</a>
    <a class="btn" href="receipt_form?id=<?= $receipt['ReceiptID'] ?>">Edit GRN &amp; items</a>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h2>GRN details</h2></div>
  <div class="detail-grid">
    <div>
      <div class="label">GRN number</div>
      <div class="value"><?= h($receipt['GRNNumber']) ?></div>
    </div>
    <div>
      <div class="label">GRN date</div>
      <div class="value"><?= $receipt['ReceiptDate'] ? h($receipt['ReceiptDate']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Supplier</div>
      <div class="value"><?= $receipt['Supplier'] ? h($receipt['Supplier']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Group</div>
      <div class="value"><?= $receipt['GroupName'] ? h($receipt['GroupName']) : '<span class="muted">—</span>' ?></div>
    </div>
    <div>
      <div class="label">Project</div>
      <div class="value"><?= h(project_label($receipt['ProjectID'] ?? null)) ?></div>
    </div>
    <?php if (is_admin()): ?>
    <div>
      <div class="label">Office</div>
      <div class="value"><?= h(office_label($receipt['Office'] ?? null)) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="detail-grid" style="padding-top:0;">
    <div>
      <div class="label">Created</div>
      <div class="value muted" style="font-size:13px;font-weight:500;">
        <?php if ($receipt['CreatedAt']): ?>
          <?= h($receipt['CreatedByName'] ?: $receipt['CreatedByUsername'] ?: 'Unknown user') ?> — <?= h(substr($receipt['CreatedAt'], 0, 16)) ?>
        <?php else: ?>
          — <span title="Created before user accounts were added">(before user tracking)</span>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <div class="label">Last updated</div>
      <div class="value muted" style="font-size:13px;font-weight:500;">
        <?php if ($receipt['UpdatedAt']): ?>
          <?= h($receipt['UpdatedByName'] ?: $receipt['UpdatedByUsername'] ?: 'Unknown user') ?> — <?= h(substr($receipt['UpdatedAt'], 0, 16)) ?>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Items received</h2>
    <span class="muted"><?= count($lines) ?> line item(s)</span>
  </div>
  <table>
    <thead>
      <tr><th>Item #</th><th>Item Name</th><th>Item Name (Dari)</th><th>Description</th><th>Unit</th><th class="num">Quantity</th></tr>
    </thead>
    <tbody>
      <?php if (!$lines): ?>
        <tr><td colspan="6" class="muted">No items recorded for this GRN.</td></tr>
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
  <a class="btn" href="receipt_form?id=<?= $receipt['ReceiptID'] ?>">Edit this GRN</a>
  <a class="btn secondary" href="receipt_print?id=<?= $receipt['ReceiptID'] ?>" target="_blank">Print this GRN</a>
  <a class="btn secondary" href="receipts">Back to all GRNs</a>
  <form method="post" action="receipts" style="display:inline;" onsubmit="return confirm('Delete this GRN and all its item lines? This cannot be undone.')">
    <?= csrf_field() ?>
    <input type="hidden" name="delete_receipt" value="<?= $receipt['ReceiptID'] ?>">
    <button type="submit" class="btn danger">Delete this GRN</button>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
