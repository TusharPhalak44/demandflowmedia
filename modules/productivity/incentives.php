<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();
$user = getCurrentUser();

$requestedAgentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$isAdmin = in_array((string)($user['role'] ?? ''), ['admin'], true);
$agentId = ($isAdmin && $requestedAgentId > 0) ? $requestedAgentId : (int)($user['id'] ?? 0);

$viewingOther = (int)$agentId !== (int)($user['id'] ?? 0);
$agentName = $user['full_name'] ?? 'You';
if ($viewingOther) {
    $connUser = getDbConnection();
    if ($stmtU = $connUser->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1')) {
        $stmtU->bind_param('i', $agentId);
        if ($stmtU->execute()) {
            $resU = $stmtU->get_result();
            if ($resU && $rowU = $resU->fetch_assoc()) { $agentName = $rowU['full_name']; }
        }
        $stmtU->close();
    }
}

$now = new DateTime();
$selMonthStr = isset($_GET['month']) ? (string)$_GET['month'] : $now->format('Y-m');
if (preg_match('/^(\d{4})-(\d{2})$/', $selMonthStr, $m)) {
    $viewYear = (int)$m[1];
    $viewMonth = (int)$m[2];
} else {
    $viewYear = (int)$now->format('Y');
    $viewMonth = (int)$now->format('m');
    $selMonthStr = sprintf('%04d-%02d', $viewYear, $viewMonth);
}

$prev = (new DateTime(sprintf('%04d-%02d-01', $viewYear, $viewMonth)))->modify('first day of last month');
$prevYear = (int)$prev->format('Y');
$prevMonth = (int)$prev->format('m');

$stats = getAgentMonthlyStats($agentId, $viewYear, $viewMonth);
$prevStats = getAgentMonthlyStats($agentId, $prevYear, $prevMonth);
$isLocked = isProductivityMonthLocked($viewYear, $viewMonth);

$target = is_array($stats['target'] ?? null) ? $stats['target'] : [];
$dailyTarget = (int)($target['daily_target'] ?? 0);
$period = is_array($stats['period'] ?? null) ? $stats['period'] : [];
$workingDays = (int)($period['working_days'] ?? 0);
$overallPercent = (float)($stats['stats']['overall_percent'] ?? 0);
$daysMetDaily = (int)($stats['stats']['days_met_daily'] ?? 0);
$daysElapsed = (int)($period['days_elapsed'] ?? 0);
$totalMql = (float)($stats['stats']['total_mql'] ?? 0);

$dailyIncTotal = (int)($stats['incentives']['daily_total'] ?? 0);
$monthlyInc = (int)($stats['incentives']['monthly_bonus'] ?? 0);
$totalInc = (int)($stats['incentives']['total'] ?? 0);
$combined = $totalInc + (int)($prevStats['incentives']['total'] ?? 0);

function monthName($y, $m) { return date('F Y', mktime(0,0,0,$m,1,$y)); }
?>
<?php $pageTitle = 'Incentives'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-cash-stack fs-5"></i>
                        <div class="fw-semibold">
                            <?php echo $viewingOther ? ('Incentives: ' . htmlspecialchars($agentName)) : 'Your Incentives'; ?>
                        </div>
                    </div>
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <?php if ($isAdmin && $requestedAgentId > 0): ?>
                            <input type="hidden" name="agent_id" value="<?php echo (int)$requestedAgentId; ?>">
                        <?php endif; ?>
                        <input type="month" name="month" class="form-control form-control-sm" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                        <button class="btn btn-light btn-sm" type="submit"><i class="bi bi-calendar3"></i> View</button>
                        <a class="btn btn-outline-light btn-sm" href="productivity.php?month=<?php echo urlencode($selMonthStr); ?><?php echo $isAdmin && $requestedAgentId>0 ? '&agent_id='.(int)$requestedAgentId : ''; ?>"><i class="bi bi-graph-up-arrow"></i> Productivity</a>
                    </form>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-end mb-2">
                        <span class="badge bg-<?php echo $isLocked ? 'danger' : 'secondary'; ?>"><?php echo $isLocked ? 'Productivity Locked' : 'Productivity Open'; ?></span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Daily Target (MQL)</div>
                                <div class="fs-5 fw-semibold"><?php echo number_format($dailyTarget); ?></div>
                                <div class="text-muted small">Working Days: <?php echo number_format($workingDays); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Overall Productivity</div>
                                <div class="fs-5 fw-semibold"><?php echo number_format($overallPercent, 1); ?>%</div>
                                <div class="text-muted small">Days Met: <?php echo number_format($daysMetDaily); ?> / <?php echo number_format($daysElapsed); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Daily Incentives</div>
                                <div class="fs-5 fw-semibold text-success">Rs. <?php echo number_format($dailyIncTotal, 0); ?></div>
                                <div class="text-muted small">Total MQL: <?php echo number_format($totalMql, 2); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Monthly Incentive</div>
                                <div class="fs-5 fw-semibold">Rs. <?php echo number_format($monthlyInc, 0); ?></div>
                                <div class="text-muted small">Threshold: 90%+</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100 text-center">
                                <div class="text-muted small">Selected Month (<?php echo htmlspecialchars(monthName($viewYear, $viewMonth)); ?>)</div>
                                <div class="fs-4 fw-semibold">Rs. <?php echo number_format($totalInc, 0); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100 text-center">
                                <div class="text-muted small">Previous Month (<?php echo htmlspecialchars(monthName($prevYear, $prevMonth)); ?>)</div>
                                <div class="fs-4 fw-semibold">Rs. <?php echo number_format((int)($prevStats['incentives']['total'] ?? 0), 0); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100 text-center">
                                <div class="text-muted small">Combined</div>
                                <div class="fs-4 fw-semibold">Rs. <?php echo number_format($combined, 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-outline-primary btn-sm" href="productivity-export.php?month=<?php echo urlencode($selMonthStr); ?><?php echo $isAdmin && $requestedAgentId>0 ? '&agent_id='.(int)$requestedAgentId : ''; ?>"><i class="bi bi-download"></i> Download CSV</a>
                    </div>
                </div>
            </div>

            <?php $s = $stats; ?>
            <?php include __DIR__ . '/incentives_section.php'; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
