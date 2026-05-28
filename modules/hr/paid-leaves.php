<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

$allowedRoles = function_exists('getKnownRoles') ? getKnownRoles() : ['admin'];
requireRole($allowedRoles);
ensureCsrfToken();

$user = getCurrentUser();
$viewerId = (int)($user['id'] ?? 0);
$targetId = $viewerId;
if (isAdmin() && isset($_GET['user_id'])) {
    $targetId = (int)$_GET['user_id'];
}
if ($targetId <= 0) $targetId = $viewerId;

$asOf = isset($_GET['as_of']) ? (string)$_GET['as_of'] : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
}

$conn = getDbConnection();
$targetUser = null;
$stmtU = $conn->prepare("SELECT id, full_name, role FROM users WHERE id = ? LIMIT 1");
if ($stmtU) {
    $stmtU->bind_param('i', $targetId);
    $stmtU->execute();
    $targetUser = $stmtU->get_result()->fetch_assoc() ?: null;
    $stmtU->close();
}
if (!$targetUser) {
    header('Location: hr-dashboard');
    exit;
}

$sum = getPaidLeaveKittyForUser((int)$targetId, $asOf);
$entitled = (int)($sum['entitled'] ?? 0);
$taken = (int)($sum['taken'] ?? 0);
$pending = (int)($sum['pending'] ?? 0);
$joinDate = (string)($sum['join_date'] ?? '');
$takenDates = is_array($sum['taken_dates'] ?? null) ? $sum['taken_dates'] : [];
$upcoming = is_array($sum['upcoming_holidays'] ?? null) ? $sum['upcoming_holidays'] : [];

$pageTitle = 'Paid Leaves';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <?php
    $badges = [
      ['text' => 'As of ' . $asOf, 'class' => 'bg-light text-dark font-monospace'],
    ];
    hrRenderHeader(
      [
        ['label' => 'HR', 'href' => 'hr-dashboard'],
        ['label' => 'Paid Leaves'],
      ],
      'Paid Leave Kitty',
      'Monthly paid leave balance and usage history',
      [
        ['label' => 'Attendance', 'href' => 'attendance', 'icon' => 'bi-fingerprint', 'class' => 'btn-outline-primary'],
        ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-secondary'],
      ],
      $badges
    );
  ?>

  <div class="row g-3">
    <div class="col-12">
      <div class="card hr-card">
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <?php if (isAdmin()): ?>
              <div class="col-md-5">
                <label class="form-label small text-muted">User</label>
                <select class="form-select form-select-sm" name="user_id">
                  <?php
                    $users = getInternalPayrollUsers();
                    foreach ($users as $u) {
                      $uid = (int)($u['id'] ?? 0);
                      $nm = (string)($u['full_name'] ?? '');
                      if ($uid <= 0) continue;
                      $sel = $uid === (int)$targetId ? 'selected' : '';
                      echo '<option value="' . (int)$uid . '" ' . $sel . '>' . htmlspecialchars($nm) . '</option>';
                    }
                  ?>
                </select>
              </div>
            <?php endif; ?>
            <div class="col-md-3">
              <label class="form-label small text-muted">As Of</label>
              <input type="date" class="form-control form-control-sm" name="as_of" value="<?php echo htmlspecialchars($asOf); ?>">
            </div>
            <div class="col-md-2">
              <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Refresh</button>
            </div>
            <div class="col-md-2">
              <div class="small text-muted">Joining Date</div>
              <div class="fw-semibold"><?php echo htmlspecialchars($joinDate !== '' ? $joinDate : '—'); ?></div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <?php hrKpi('Entitled (Months)', number_format($entitled), 'bi-calendar3'); ?>
    </div>
    <div class="col-md-4">
      <?php hrKpi('Paid Leaves Taken', number_format($taken), 'bi-calendar2-check', '', 'text-success'); ?>
    </div>
    <div class="col-md-4">
      <?php hrKpi('Pending in Kitty', number_format($pending), 'bi-inbox', '', $pending > 0 ? 'text-primary' : 'text-muted'); ?>
    </div>

    <div class="col-lg-7">
      <div class="card hr-card h-100">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-list-check me-1"></i> Paid Leave History</span>
          <span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)($targetUser['full_name'] ?? '')); ?></span>
        </div>
        <div class="card-body">
          <?php if (empty($takenDates)): ?>
            <div class="text-muted">No paid leaves recorded.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Day</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($takenDates as $d): ?>
                    <?php
                      $dayName = '';
                      try {
                        $dt = new DateTimeImmutable($d . ' 00:00:00', hrBaseTz());
                        $dayName = $dt->format('l');
                      } catch (Throwable $e) {
                        $dayName = '';
                      }
                    ?>
                    <tr>
                      <td class="font-monospace"><?php echo htmlspecialchars($d); ?></td>
                      <td class="text-muted"><?php echo htmlspecialchars($dayName); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card hr-card h-100">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-megaphone me-1"></i> Upcoming Holidays</div>
        <div class="card-body">
          <?php if (empty($upcoming)): ?>
            <div class="text-muted">No upcoming holidays found.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Holiday</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($upcoming as $h): ?>
                    <?php
                      $hd = (string)($h['holiday_date'] ?? '');
                      $hn = (string)($h['name'] ?? ($h['holiday_name'] ?? ''));
                    ?>
                    <tr>
                      <td class="font-monospace"><?php echo htmlspecialchars($hd); ?></td>
                      <td><?php echo htmlspecialchars($hn); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
