<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','sales_director']);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

$message = '';
$messageType = 'success';

$now = new DateTime();
$selMonthStr = isset($_GET['month']) ? (string)$_GET['month'] : $now->format('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $selMonthStr, $m)) {
    $selMonthStr = $now->format('Y-m');
    $m = [$selMonthStr, (int)$now->format('Y'), (int)$now->format('m')];
}
$viewYear = (int)$m[1];
$viewMonth = (int)$m[2];

$today = $now->format('Y-m-d');
$rate = getUsdInrRate($today);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_targets') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        try {
            $monthStr = (string)($_POST['month'] ?? $selMonthStr);
            if (preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $mm)) {
                $viewYear = (int)$mm[1];
                $viewMonth = (int)$mm[2];
                $selMonthStr = sprintf('%04d-%02d', $viewYear, $viewMonth);
            }

            $rateInput = trim((string)($_POST['usd_inr_rate'] ?? ''));
            if ($rateInput !== '') {
                $r = (float)$rateInput;
                if ($r > 0) {
                    setUsdInrRate($today, $r, $userId);
                    $rate = $r;
                }
            }

            $newAcc = $_POST['target_new_accounts'] ?? [];
            $revUsd = $_POST['target_revenue_usd'] ?? [];
            if (!is_array($newAcc)) $newAcc = [];
            if (!is_array($revUsd)) $revUsd = [];

            $saved = 0;
            foreach (getSalesTargetAssignableUsers() as $u) {
                $uid = (int)($u['id'] ?? 0);
                if ($uid <= 0) continue;
                $tNew = isset($newAcc[$uid]) ? max(0, (int)$newAcc[$uid]) : 0;
                $tRev = isset($revUsd[$uid]) ? max(0.0, (float)$revUsd[$uid]) : 0.0;
                if (upsertSalesMonthlyTarget($uid, $viewYear, $viewMonth, $tNew, $tRev, $userId)) $saved++;
            }

            $message = 'Targets saved for ' . $saved . ' users.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$assignable = getSalesTargetAssignableUsers();
$targets = [];
if (!empty($assignable)) {
    $conn = getDbConnection();
    $ids = array_map(fn($r) => (int)$r['id'], $assignable);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT t.*, u.full_name AS assigned_by_name, u.role AS assigned_by_role
        FROM sales_targets t
        LEFT JOIN users u ON u.id = t.assigned_by
        WHERE t.year = ? AND t.month = ? AND t.user_id IN ($in)";
    $stmt = $conn->prepare($sql);
    $types = 'ii' . str_repeat('i', count($ids));
    $stmt->bind_param($types, $viewYear, $viewMonth, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $targets[(int)$r['user_id']] = $r;
    }
}

$pageTitle = 'Sales Targets';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Sales Productivity Targets</h3>
      <div class="text-muted small">Assign monthly New Accounts and Revenue targets to SDRs and Sales Managers.</div>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="month" class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">
      <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-calendar me-1"></i>View</button>
    </form>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="save_targets">
    <input type="hidden" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>">

    <div class="card mb-3">
      <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-currency-exchange me-1"></i>FX Rate</span>
        <span class="text-muted small"><?php echo htmlspecialchars($today); ?></span>
      </div>
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label">USD → INR (today)</label>
            <input class="form-control form-control-sm" name="usd_inr_rate" value="<?php echo htmlspecialchars($rate !== null ? (string)$rate : ''); ?>" placeholder="e.g. 83.25">
          </div>
          <div class="col-md-8 text-muted small">
            This rate is used for INR columns only. Targets are stored in USD.
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-bullseye me-1"></i>Targets</span>
        <span class="text-muted small"><?php echo htmlspecialchars(date('F Y', mktime(0, 0, 0, $viewMonth, 1, $viewYear))); ?></span>
      </div>
      <div class="card-body">

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>User</th>
                <th>Job Title</th>
                <th class="text-nowrap">New Accounts Target</th>
                <th class="text-nowrap">Revenue Target (USD)</th>
                <th class="text-nowrap">Revenue Target (INR)</th>
                <th class="text-muted text-nowrap">Assigned</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($assignable)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No Sales users found.</td></tr>
              <?php else: ?>
                <?php foreach ($assignable as $u): ?>
                  <?php
                    $uid = (int)($u['id'] ?? 0);
                    $t = $targets[$uid] ?? null;
                    $tNew = (int)($t['target_new_accounts'] ?? 0);
                    $tUsd = (float)($t['target_revenue_usd'] ?? 0);
                    $tInr = ($rate !== null) ? ($tUsd * (float)$rate) : null;
                    $assignedAt = (string)($t['assigned_at'] ?? '');
                    $assignedBy = trim((string)($t['assigned_by_name'] ?? ''));
                    $assignedByRole = trim((string)($t['assigned_by_role'] ?? ''));
                  ?>
                  <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars((string)($u['job_title'] ?? '')); ?></td>
                    <td style="max-width: 180px;">
                      <input type="number" min="0" class="form-control form-control-sm" name="target_new_accounts[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars((string)$tNew); ?>">
                    </td>
                    <td style="max-width: 220px;">
                      <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="target_revenue_usd[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars(number_format($tUsd, 2, '.', '')); ?>">
                    </td>
                    <td class="text-muted small">
                      <?php echo $tInr !== null ? htmlspecialchars(number_format($tInr, 2)) : '—'; ?>
                    </td>
                    <td class="text-muted small">
                      <?php if ($assignedAt !== ''): ?>
                        <?php echo htmlspecialchars(date('d M, H:i', strtotime($assignedAt))); ?>
                        <?php if ($assignedBy !== ''): ?>
                          <div><?php echo htmlspecialchars(formatUserNameWithRole($assignedBy, $assignedByRole)); ?></div>
                        <?php endif; ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <a href="dashboard.php" class="btn btn-light btn-sm">Back</a>
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i>Save Targets</button>
        </div>
      </div>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
