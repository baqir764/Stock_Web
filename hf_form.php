<?php
require __DIR__ . '/includes/functions.php';
require_login();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$hf = ['HFName'=>'','DistrictID'=>'','HFType'=>'','InChargePerson'=>'','Office'=>null,'Province'=>''];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM HealthFacilities WHERE HFID = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found || !can_access_office($found['Office'] ?? null)) {
        flash_set('Facility not found.');
        header('Location: health_facilities');
        exit;
    }
    $hf = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired — please try that again.';
    } elseif ($id && !can_access_office($hf['Office'] ?? null)) {
        $error = 'You do not have access to this facility.';
    } else {
        $name = trim($_POST['HFName']);
        $districtId = $_POST['DistrictID'] !== '' ? (int)$_POST['DistrictID'] : null;
        $type = trim($_POST['HFType']);
        $inCharge = trim($_POST['InChargePerson'] ?? '');
        $province = trim($_POST['Province'] ?? '');
        if ($province === '') $province = null;

        if (is_admin()) {
            $office = $_POST['Office'] ?? '';
            $office = array_key_exists($office, office_options()) ? $office : null;
        } else {
            $office = current_user()['Office'] ?? null;
        }

        if ($name === '') {
            $error = 'Facility name is required.';
        } elseif ($office === null) {
            $error = is_admin()
                ? 'Please choose which office this facility belongs to.'
                : 'Your account has no office assigned yet — contact an administrator.';
        } else {
            if ($id) {
                $stmt = db()->prepare('UPDATE HealthFacilities SET HFName=?, DistrictID=?, HFType=?, InChargePerson=?, Office=?, Province=? WHERE HFID=?');
                $stmt->execute([$name, $districtId, $type, $inCharge, $office, $province, $id]);
                flash_set('Facility updated.');
            } else {
                $stmt = db()->prepare('INSERT INTO HealthFacilities (HFName, DistrictID, HFType, InChargePerson, Office, Province) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$name, $districtId, $type, $inCharge, $office, $province]);
                flash_set('Facility added.');
            }
            header('Location: health_facilities');
            exit;
        }
    }
}

$districts = all_districts();
$pageTitle = $id ? 'Edit facility' : 'New facility';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><h1><?= $id ? 'Edit facility' : 'New facility' ?></h1></div>
</div>

<?php if (!empty($error)): ?><div class="flash" style="background:var(--red-tint);border-color:#E3B7B1;color:var(--red);"><?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form class="stack" method="post" style="padding:22px;">
    <?= csrf_field() ?>
    <div class="field">
      <label>Facility name</label>
      <input type="text" name="HFName" value="<?= h($hf['HFName']) ?>" required placeholder="e.g. Hadira (CHC)">
    </div>
    <div class="row2">
      <div class="field">
        <label>Province</label>
        <input type="text" name="Province" value="<?= h($hf['Province'] ?? '') ?>" placeholder="e.g. Kandahar — leave blank if same as usual">
      </div>
      <div class="field">
        <label>District</label>
        <select name="DistrictID">
          <option value="">— Select district —</option>
          <?php foreach ($districts as $d): ?>
            <option value="<?= $d['DistrictID'] ?>" <?= $hf['DistrictID']==$d['DistrictID']?'selected':'' ?>><?= h($d['DistrictName']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="row2">
      <div class="field">
        <label>Type</label>
        <input type="text" name="HFType" list="hfTypeList" value="<?= h($hf['HFType']) ?>" placeholder="e.g. SHC, BHC, CHC — or type a new type">
        <datalist id="hfTypeList">
          <?php foreach (all_hf_types() as $t): ?>
            <option value="<?= h($t) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="field">
        <label>In charge person</label>
        <input type="text" name="InChargePerson" value="<?= h($hf['InChargePerson']) ?>" placeholder="e.g. Dr. Ahmad Shah">
      </div>
    </div>
    <div class="field">
      <label>Office</label>
      <?php if (is_admin()): ?>
        <?= office_select_field('Office', $hf['Office'] ?? null) ?>
      <?php else: ?>
        <input type="text" value="<?= h(office_label(current_user()['Office'] ?? null)) ?>" disabled>
      <?php endif; ?>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Save facility</button>
      <a class="btn secondary" href="health_facilities">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
