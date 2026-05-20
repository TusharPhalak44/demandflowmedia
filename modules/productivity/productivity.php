<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);
ensureCsrfToken();
$user = getCurrentUser();
// Allow admins to view a specific agent by passing ?agent_id=ID
$requestedAgentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$agentId = (in_array($user['role'], ['admin'], true) && $requestedAgentId > 0) ? $requestedAgentId : (int)$user['id'];
// Fetch display name if admin is viewing another agent
$viewingOther = (int)$agentId !== (int)$user['id'];
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

$viewStats = getAgentMonthlyStats($agentId, $viewYear, $viewMonth);
$prevStats = getAgentMonthlyStats($agentId, $prevYear, $prevMonth);
$isLocked = isProductivityMonthLocked($viewYear, $viewMonth);
$holidays = getHolidaysForMonth($viewYear, $viewMonth, 'US');

$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$rangeMode = false;

$filterMonthStats = function(array $s, string $from, string $to): array {
    if (empty($s['target']) || empty($s['daily']) || $from === '' || $to === '') return $s;
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false || $toTs === false) return $s;
    if ($fromTs > $toTs) return $s;

    $target = is_array($s['target'] ?? null) ? $s['target'] : [];
    $dailyTarget = (int)($target['daily_target'] ?? 0);
    if ($dailyTarget <= 0) return $s;

    $daily = [];
    $totalLeads = 0;
    $totalMql = 0.0;
    $daysMet = 0;
    $daysElapsed = 0;
    $dailyInc = 0;

    foreach (($s['daily'] ?? []) as $row) {
        $d = (string)($row['date'] ?? '');
        $dTs = $d !== '' ? strtotime($d) : false;
        if ($dTs === false) continue;
        if ($dTs < $fromTs || $dTs > $toTs) continue;
        $daysElapsed++;
        $daily[] = $row;
        $totalLeads += (int)($row['total_leads'] ?? 0);
        $totalMql += (float)($row['achieved_mql'] ?? 0);
        if (!empty($row['met_daily_target'])) $daysMet++;
        $dailyInc += (int)($row['daily_incentive'] ?? 0);
    }

    $workingDays = $daysElapsed;
    $overallPercent = $workingDays > 0 ? round((($totalMql / ((float)$dailyTarget * (float)$workingDays)) * 100.0), 1) : 0.0;

    $s['period']['working_days'] = $workingDays;
    $s['period']['days_elapsed'] = $daysElapsed;
    $s['stats']['total_leads'] = $totalLeads;
    $s['stats']['total_mql'] = $totalMql;
    $s['stats']['qualified_total'] = $totalMql;
    $s['stats']['total_calls'] = $totalMql;
    $s['stats']['days_met_daily'] = $daysMet;
    $s['stats']['overall_percent'] = $overallPercent;
    $s['stats']['met_monthly'] = false;
    $s['incentives']['daily_total'] = $dailyInc;
    $s['incentives']['monthly_bonus'] = 0;
    $s['incentives']['total'] = $dailyInc;
    $s['daily'] = $daily;
    return $s;
};

if ($dateFrom !== '' && $dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $monthStart = sprintf('%04d-%02d-01', $viewYear, $viewMonth);
    $monthEnd = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear));
    if ($dateFrom >= $monthStart && $dateTo <= $monthEnd) {
        $viewStats = $filterMonthStats($viewStats, $dateFrom, $dateTo);
        $rangeMode = true;
    }
}

function monthName($y, $m) { return date('F Y', mktime(0,0,0,$m,1,$y)); }
?>
<?php $pageTitle = 'Productivity'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo $viewingOther ? ('Productivity: ' . htmlspecialchars($agentName)) : 'Your Productivity'; ?></h5>
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <?php if (in_array($user['role'], ['admin'], true) && $requestedAgentId > 0): ?>
                            <input type="hidden" name="agent_id" value="<?php echo (int)$requestedAgentId; ?>">
                        <?php endif; ?>
                        <input type="month" name="month" class="form-control form-control-sm" value="<?php echo htmlspecialchars($selMonthStr); ?>">
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                        <button class="btn btn-light btn-sm" type="submit"><i class="bi bi-funnel"></i> View</button>
                        <a class="btn btn-outline-light btn-sm" href="incentives.php?month=<?php echo urlencode($selMonthStr); ?><?php echo (in_array($user['role'], ['admin'], true) && $requestedAgentId>0) ? '&agent_id='.(int)$requestedAgentId : ''; ?>"><i class="bi bi-cash-stack"></i> Incentives</a>
                    </form>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-end mb-2">
                        <span class="badge bg-<?php echo $isLocked ? 'danger' : 'secondary'; ?>"><?php echo $isLocked ? 'Payroll Locked' : 'Payroll Open'; ?></span>
                    </div>
                    <ul class="nav nav-tabs" id="prodTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="cur-tab" data-bs-toggle="tab" data-bs-target="#current" type="button" role="tab"><i class="bi bi-calendar3 me-1"></i><?php echo $rangeMode ? 'Selected Range' : 'Selected Month'; ?> (<?php echo htmlspecialchars(monthName($viewYear, $viewMonth)); ?>)</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="prev-tab" data-bs-toggle="tab" data-bs-target="#previous" type="button" role="tab"><i class="bi bi-arrow-left-right me-1"></i>Previous Month (<?php echo htmlspecialchars(monthName($prevYear, $prevMonth)); ?>)</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="holidays-tab" data-bs-toggle="tab" data-bs-target="#holidays" type="button" role="tab"><i class="bi bi-calendar-event me-1"></i>Holidays</button>
                        </li>
                        <?php if (in_array($user['role'], ['admin'], true)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="export-tab" data-bs-toggle="tab" data-bs-target="#export" type="button" role="tab"><i class="bi bi-download me-1"></i>Export</button>
                        </li>
                        <?php endif; ?>
                    </ul>
                    <div class="tab-content pt-3">
                        <div class="tab-pane fade show active" id="current" role="tabpanel">
                            <?php $s = $viewStats; ?>
                            <?php include __DIR__ . '/productivity_section_proposed.php'; ?>
                        </div>
                        <div class="tab-pane fade" id="previous" role="tabpanel">
                            <?php $s = $prevStats; ?>
                            <?php include __DIR__ . '/productivity_section_proposed.php'; ?>
                        </div>
                        <div class="tab-pane fade" id="holidays" role="tabpanel">
                            <?php if (empty($holidays)): ?>
                                <div class="text-muted">No US national holidays in this month.</div>
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
                        <?php if (in_array($user['role'], ['admin'], true)): ?>
                        <div class="tab-pane fade" id="export" role="tabpanel">
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-outline-primary btn-sm" href="productivity-export.php?month=<?php echo urlencode($selMonthStr); ?><?php echo $requestedAgentId>0 ? '&agent_id='.(int)$requestedAgentId : ''; ?>"><i class="bi bi-download"></i> Download CSV</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
