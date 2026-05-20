<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();
$user = getCurrentUser();

$message = '';
$messageType = 'success';

// Defaults to current month
$now = new DateTime();
$currentYear = (int)$now->format('Y');
$currentMonth = (int)$now->format('m');

$selMonthStr = isset($_GET['month']) ? $_GET['month'] : $now->format('Y-m'); // YYYY-MM
if (preg_match('/^(\d{4})-(\d{2})$/', $selMonthStr, $m)) {
    $viewYear = (int)$m[1];
    $viewMonth = (int)$m[2];
} else {
    $viewYear = $currentYear;
    $viewMonth = $currentMonth;
}

// Handle month lock/unlock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['lock_month','unlock_month'], true)) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $monthStr = $_POST['month'] ?? $now->format('Y-m');
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $mm)) {
            $yy = (int)$mm[1];
            $mo = (int)$mm[2];
        } else {
            $yy = $currentYear; $mo = $currentMonth;
        }
        if ($_POST['action'] === 'lock_month') {
            $ok = lockProductivityMonth($yy, $mo, (int)$user['id']);
            $message = $ok ? 'Payroll month locked and snapshots generated.' : 'Failed to lock payroll month.';
            $messageType = $ok ? 'success' : 'danger';
        } else {
            $ok = unlockProductivityMonth($yy, $mo);
            $message = $ok ? 'Payroll month unlocked.' : 'Failed to unlock payroll month.';
            $messageType = $ok ? 'success' : 'danger';
        }
        $viewYear = $yy; $viewMonth = $mo;
    }
}

// Handle target assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_target') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $agentId = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : 0;
        $monthStr = $_POST['month'] ?? $now->format('Y-m');
        $dailyTarget = isset($_POST['daily_target']) ? max(0, (int)$_POST['daily_target']) : 0;

        if (preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $mm)) {
            $yy = (int)$mm[1];
            $mo = (int)$mm[2];
        } else {
            $yy = $currentYear; $mo = $currentMonth;
        }

        if ($agentId <= 0 || $dailyTarget <= 0) {
            $message = 'Please select an agent and enter a valid daily target.';
            $messageType = 'danger';
        } elseif (isProductivityMonthLocked($yy, $mo)) {
            $message = 'Payroll month is locked. Unlock the month to change targets.';
            $messageType = 'danger';
        } else {
            if (setAgentMonthlyTarget($agentId, $yy, $mo, $dailyTarget, (int)$user['id'])) {
                $message = 'Target assigned/updated successfully.';
                $messageType = 'success';
                // Set view to selected month
                $viewYear = $yy; $viewMonth = $mo;
            } else {
                $message = 'Failed to assign target.';
                $messageType = 'danger';
            }
        }
    }
}

$agents = getAgents();
$targets = getMonthlyTargets($viewYear, $viewMonth);
$isLocked = isProductivityMonthLocked($viewYear, $viewMonth);
$holidays = getHolidaysForMonth($viewYear, $viewMonth, 'US');

function monthName($y, $m) { return date('F Y', mktime(0,0,0,$m,1,$y)); }
?>
<?php $pageTitle = 'Productivity Admin'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Assign Productivity Targets</h5>
                    <div class="d-flex align-items-center gap-2">
                        <form method="get" class="d-flex align-items-center">
                            <input type="month" class="form-control form-control-sm me-2" name="month" value="<?php echo htmlspecialchars(sprintf('%04d-%02d', $viewYear, $viewMonth)); ?>">
                            <button class="btn btn-light btn-sm" type="submit"><i class="bi bi-calendar"></i> View</button>
                        </form>
                        <a class="btn btn-light btn-sm" href="productivity-export.php?month=<?php echo htmlspecialchars(sprintf('%04d-%02d', $viewYear, $viewMonth)); ?>"><i class="bi bi-download"></i> Export CSV</a>
                        <form method="post" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="month" value="<?php echo htmlspecialchars(sprintf('%04d-%02d', $viewYear, $viewMonth)); ?>">
                            <?php if ($isLocked): ?>
                                <input type="hidden" name="action" value="unlock_month">
                                <button class="btn btn-warning btn-sm" type="submit"><i class="bi bi-unlock"></i> Unlock</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="lock_month">
                                <button class="btn btn-danger btn-sm" type="submit"><i class="bi bi-lock"></i> Lock Payroll</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
                    <?php endif; ?>
                    <form method="post" action="productivity-admin.php" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="assign_target">
                        <div class="col-md-3">
                            <label class="form-label">Agent</label>
                            <select name="agent_id" class="form-select" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <option value="">Select Agent</option>
                                <?php foreach ($agents as $a): ?>
                                    <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Month</label>
                            <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars(sprintf('%04d-%02d', $viewYear, $viewMonth)); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Daily Target - MQLs</label>
                            <input type="number" name="daily_target" class="form-control" min="1" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit" <?php echo $isLocked ? 'disabled' : ''; ?>><i class="bi bi-check2-circle"></i> Assign Target</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">US National Holidays: <?php echo htmlspecialchars(monthName($viewYear, $viewMonth)); ?></h6>
                    <span class="badge bg-<?php echo $isLocked ? 'danger' : 'secondary'; ?>"><?php echo $isLocked ? 'Payroll Locked' : 'Payroll Open'; ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($holidays)): ?>
                        <div class="text-muted">No holidays in this month.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Holiday</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($holidays as $h): ?>
                                        <tr>
                                            <td class="font-monospace"><?php echo htmlspecialchars((string)($h['holiday_date'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars((string)($h['name'] ?? '')); ?></td>
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

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0">Month Overview: <?php echo htmlspecialchars(monthName($viewYear, $viewMonth)); ?></h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover">
                            <thead>
                                <tr>
                                    <th><i class="bi bi-person"></i> Agent</th>
                                    <th><i class="bi bi-briefcase"></i> Working Days</th>
                                    <th><i class="bi bi-flag"></i> Daily Target (MQL)</th>
                                    <th><i class="bi bi-bar-chart"></i> Delivered (MQL)</th>
                                    <th><i class="bi bi-graph-up"></i> Overall %</th>
                                    <th><i class="bi bi-check2-circle"></i> Days Met (>=100%)</th>
                                    <th><i class="bi bi-award"></i> Status</th>
                                    <th><i class="bi bi-cash-coin"></i> Daily Incentives</th>
                                    <th><i class="bi bi-wallet2"></i> Monthly Incentive</th>
                                    <th><i class="bi bi-cash-stack"></i> Total Incentives</th>
                                    <th class="text-end pe-3">Export</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($targets)): ?>
                                    <tr><td colspan="11" class="text-center">No targets set for this month.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($targets as $t): ?>
                                        <?php $stats = getAgentMonthlyStats((int)$t['agent_id'], $viewYear, $viewMonth); ?>
                                        <tr>
                                            <td>
                                                <a href="productivity.php?agent_id=<?php echo (int)$t['agent_id']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($t['agent_name']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo (int)($stats['period']['working_days'] ?? $t['working_days']); ?></td>
                                            <td><?php echo (int)$t['daily_target']; ?></td>
                                            <td><?php echo number_format((float)($stats['stats']['total_mql'] ?? 0), 2); ?></td>
                                            <td><?php echo number_format((float)($stats['stats']['overall_percent'] ?? 0), 1); ?>%</td>
                                            <td><?php echo (int)($stats['stats']['days_met_daily'] ?? $stats['stats']['days_met_target'] ?? 0); ?></td>
                                            <td>
                                                <?php 
                                                $st = $stats['stats']['status'] ?? 'normal';
                                                $metMonthly = !empty($stats['stats']['met_monthly']);
                                                if ($metMonthly) { echo '<span class="badge bg-success"><i class="bi bi-trophy"></i> Eligible</span>'; }
                                                elseif ($st === 'no_target') { echo '<span class="badge bg-secondary"><i class="bi bi-hourglass-split"></i> No Target</span>'; }
                                                else { echo '<span class="badge bg-secondary"><i class="bi bi-hourglass-split"></i> In Progress</span>'; }
                                                ?>
                                            </td>
                                            <td>Rs. <?php echo number_format($stats['incentives']['daily_total'] ?? 0); ?></td>
                                            <td>Rs. <?php echo number_format($stats['incentives']['monthly_bonus'] ?? 0); ?></td>
                                            <td><strong>Rs. <?php echo number_format($stats['incentives']['total'] ?? 0); ?></strong></td>
                                            <td class="text-end pe-3">
                                                <a class="btn btn-outline-secondary btn-sm" href="productivity-export.php?month=<?php echo htmlspecialchars(sprintf('%04d-%02d', $viewYear, $viewMonth)); ?>&agent_id=<?php echo (int)$t['agent_id']; ?>"><i class="bi bi-filetype-csv"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
