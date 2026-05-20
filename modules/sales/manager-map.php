<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','admin']);
ensureCsrfToken();

$conn = getDbConnection();
$message = '';
$messageType = '';

$managers = [];
$rs = $conn->query("SELECT id, full_name, role FROM users WHERE role = 'sales_manager' ORDER BY full_name");
if ($rs) $managers = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$sdrs = [];
$rs = $conn->query("SELECT id, full_name, role FROM users WHERE role = 'sdr' ORDER BY full_name");
if ($rs) $sdrs = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$map = [];
$rs = $conn->query("SELECT manager_user_id, sdr_user_id FROM sales_manager_sdr_map");
if ($rs) {
    while ($r = $rs->fetch_assoc()) {
        $mId = (int)$r['manager_user_id'];
        $sId = (int)$r['sdr_user_id'];
        if (!isset($map[$mId])) $map[$mId] = [];
        $map[$mId][$sId] = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $managerId = (int)($_POST['manager_user_id'] ?? 0);
        $selected = $_POST['sdr_user_ids'] ?? [];
        if ($managerId <= 0) {
            $message = 'Select a Sales Manager.';
            $messageType = 'danger';
        } else {
            $conn->query("DELETE FROM sales_manager_sdr_map WHERE manager_user_id = ".$managerId);
            if (is_array($selected) && !empty($selected)) {
                $stmt = $conn->prepare("INSERT IGNORE INTO sales_manager_sdr_map (manager_user_id, sdr_user_id, created_at) VALUES (?,?,NOW())");
                foreach ($selected as $sid) {
                    $sdrId = (int)$sid;
                    if ($sdrId <= 0) continue;
                    $stmt->bind_param('ii', $managerId, $sdrId);
                    $stmt->execute();
                }
                $stmt->close();
            }
            $message = 'Mapping updated.';
            $messageType = 'success';

            $map[$managerId] = [];
            if (is_array($selected)) {
                foreach ($selected as $sid) {
                    $sId = (int)$sid;
                    if ($sId > 0) $map[$managerId][$sId] = true;
                }
            }
        }
    }
}
?>

<?php $pageTitle = 'Manager ↔ SDR Mapping'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Manager ↔ SDR Mapping</h3>
      <div class="text-muted small">Sales Directors assign SDRs to Sales Managers.</div>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType ?: 'info'); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <?php if (empty($managers)): ?>
      <div class="col-12">
        <div class="alert alert-warning mb-0">No Sales Managers found. Create Sales Manager users first.</div>
      </div>
    <?php else: ?>
      <?php foreach ($managers as $m): ?>
        <?php $mId = (int)$m['id']; ?>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="fw-semibold"><?php echo htmlspecialchars(formatUserNameWithRole(($m['full_name'] ?? ''), ($m['role'] ?? 'sales_manager'))); ?></div>
              <span class="badge bg-secondary"><?php echo isset($map[$mId]) ? count($map[$mId]) : 0; ?> SDRs</span>
            </div>
            <div class="card-body">
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="manager_user_id" value="<?php echo $mId; ?>">
                <div class="row g-2">
                  <?php if (empty($sdrs)): ?>
                    <div class="col-12 text-muted">No SDR users found.</div>
                  <?php else: ?>
                    <?php foreach ($sdrs as $s): ?>
                      <?php $sId = (int)$s['id']; ?>
                      <div class="col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="sdr_user_ids[]" value="<?php echo $sId; ?>" id="m<?php echo $mId; ?>_s<?php echo $sId; ?>" <?php echo (!empty($map[$mId][$sId])) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="m<?php echo $mId; ?>_s<?php echo $sId; ?>">
                            <?php echo htmlspecialchars(formatUserNameWithRole(($s['full_name'] ?? ''), ($s['role'] ?? 'sdr'))); ?>
                          </label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="mt-3 d-flex justify-content-end">
                  <button class="btn btn-primary btn-sm" type="submit">Save Mapping</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

