<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

requireRole(['admin','director','operations_director','operations_manager']);
ensureDatabaseSchema();
ensureCsrfToken();

$conn = getDbConnection();
$now = new DateTime();

$preset = isset($_GET['range_preset']) ? (string)$_GET['range_preset'] : 'current_month';
$startInput = isset($_GET['start_date']) ? (string)$_GET['start_date'] : '';
$endInput = isset($_GET['end_date']) ? (string)$_GET['end_date'] : '';
$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;

$rangeStart = $now->format('Y-m-01') . ' 00:00:00';
$rangeEnd = $now->format('Y-m-t') . ' 23:59:59';
$rangeLabel = 'Current Month';
try {
    $nowDT = new DateTime();
    if ($preset === 'last_day') {
        $yStart = (clone $nowDT)->modify('yesterday')->setTime(0,0,0);
        $yEnd = (clone $nowDT)->modify('yesterday')->setTime(23,59,59);
        $rangeStart = $yStart->format('Y-m-d H:i:s');
        $rangeEnd = $yEnd->format('Y-m-d H:i:s');
        $rangeLabel = 'Last Day (' . $yStart->format('d-m-Y') . ')';
    } elseif ($preset === 'last_week') {
        $thisMon = (clone $nowDT)->modify('monday this week')->setTime(0,0,0);
        $lastMon = (clone $thisMon)->modify('-7 days');
        $lastSun = (clone $lastMon)->modify('+6 days')->setTime(23,59,59);
        $rangeStart = $lastMon->format('Y-m-d H:i:s');
        $rangeEnd = $lastSun->format('Y-m-d H:i:s');
        $rangeLabel = 'Last Week ' . $lastMon->format('d-m-Y') . ' → ' . $lastSun->format('d-m-Y');
    } elseif ($preset === 'current_month') {
        $rangeStart = $nowDT->format('Y-m-01') . ' 00:00:00';
        $rangeEnd = $nowDT->format('Y-m-t') . ' 23:59:59';
        $rangeLabel = 'Current Month';
    } elseif ($preset === 'custom') {
        if ($startInput !== '' && $endInput !== '') {
            $s = DateTime::createFromFormat('Y-m-d', $startInput);
            $e = DateTime::createFromFormat('Y-m-d', $endInput);
            if ($s && $e) {
                $s->setTime(0,0,0); $e->setTime(23,59,59);
                $rangeStart = $s->format('Y-m-d H:i:s');
                $rangeEnd = $e->format('Y-m-d H:i:s');
                $rangeLabel = $s->format('d M Y') . ' → ' . $e->format('d M Y');
            }
        }
    }
} catch (Throwable $e) {}

$agents = [];
$rs = $conn->query("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role IN ('agent','operations_agent','operations_manager','operations_director') ORDER BY full_name");
if ($rs) $agents = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

if ($agentId <= 0 && !empty($agents)) $agentId = (int)($agents[0]['id'] ?? 0);

$agentName = '';
foreach ($agents as $a) {
    if ((int)($a['id'] ?? 0) === $agentId) { $agentName = (string)($a['full_name'] ?? ''); break; }
}
if ($agentName === '') $agentName = 'Agent';

$summary = ['generated' => 0, 'pending_qa' => 0, 'qualified' => 0, 'disqualified' => 0, 'delivered' => 0, 'forms' => 0, 'forms_pending' => 0];
$trendRows = [];
if ($agentId > 0) {
    $stmt = $conn->prepare("
        SELECT
          COUNT(*) AS generated,
          SUM(CASE WHEN (qa_status IS NULL OR qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
          SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
          SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) AS forms,
          SUM(CASE WHEN qa_status = 'Qualified' AND form_done = 'No' THEN 1 ELSE 0 END) AS forms_pending
        FROM leads
        WHERE agent_id = ? AND created_at BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $rangeStart, $rangeEnd);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc() ?: $summary;
        $stmt->close();
    }

    $stmt = $conn->prepare("
        SELECT DATE(created_at) AS d,
               COUNT(*) AS generated,
               SUM(CASE WHEN qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
               SUM(CASE WHEN client_delivery_status='Delivered' THEN 1 ELSE 0 END) AS delivered
        FROM leads
        WHERE agent_id = ? AND created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY d ASC
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $rangeStart, $rangeEnd);
        $stmt->execute();
        $trendRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$todayDate = $now->format('Y-m-d');
$lateMin = 0;
$attStatus = '';
$stmt = $conn->prepare("SELECT late_minutes, status FROM hr_attendance_days WHERE user_id = ? AND work_date = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('is', $agentId, $todayDate);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $lateMin = (int)($r['late_minutes'] ?? 0);
    $attStatus = (string)($r['status'] ?? '');
}

$yy = (int)$now->format('Y');
$mo = (int)$now->format('m');
$target = getAgentMonthlyTarget($agentId, $yy, $mo) ?: ['daily_target' => 0, 'working_days' => 0, 'monthly_target' => 0];
$dailyTarget = (int)($target['daily_target'] ?? 0);
$monthlyTarget = (int)($target['monthly_target'] ?? 0);
$workingDays = (int)($target['working_days'] ?? 0);

$daysInRange = 0;
if (!empty($trendRows)) {
    $daysInRange = count($trendRows);
} else {
    $sd = strtotime(substr($rangeStart,0,10));
    $ed = strtotime(substr($rangeEnd,0,10));
    if ($sd !== false && $ed !== false && $ed >= $sd) $daysInRange = (int)floor(($ed - $sd) / 86400) + 1;
}
$avgPerDay = $daysInRange > 0 ? ((int)$summary['qualified'] / $daysInRange) : 0.0;
$projMonth = $workingDays > 0 ? round($avgPerDay * $workingDays, 1) : 0.0;
$projPct = $monthlyTarget > 0 ? min(100.0, round(($projMonth / $monthlyTarget) * 100, 1)) : 0.0;

$agentRows = [];
$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.role,
        COUNT(l.id) AS generated,
        SUM(CASE WHEN (l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
        SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered
    FROM users u
    LEFT JOIN leads l ON l.agent_id = u.id AND l.created_at BETWEEN ? AND ?
    WHERE u.is_active = 1 AND u.role IN ('agent','operations_agent','operations_manager','operations_director')
    GROUP BY u.id
    ORDER BY qualified DESC, delivered DESC, generated DESC, u.full_name ASC
");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $agentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$ty = (int)date('Y', strtotime(substr($rangeStart, 0, 10)));
$tm = (int)date('m', strtotime(substr($rangeStart, 0, 10)));
$targets = getMonthlyTargets($ty, $tm);
$targetMap = [];
foreach ($targets as $t) $targetMap[(int)($t['agent_id'] ?? 0)] = $t;
$daysInRangeForPerf = $daysInRange > 0 ? $daysInRange : 1;
foreach ($agentRows as &$ar) {
    $aid = (int)($ar['id'] ?? 0);
    $t = $targetMap[$aid] ?? null;
    $dt = (int)($t['daily_target'] ?? 0);
    $q = (int)($ar['qualified'] ?? 0);
    $ar['daily_target'] = $dt;
    $ar['avg_qualified_per_day'] = $daysInRangeForPerf > 0 ? round($q / $daysInRangeForPerf, 2) : 0.0;
    $ar['performance_pct'] = ($dt > 0 && $daysInRangeForPerf > 0) ? round(($q / max(1, $dt * $daysInRangeForPerf)) * 100, 1) : null;
    $ar['delivery_pct'] = ((int)($ar['generated'] ?? 0) > 0) ? round(((int)($ar['delivered'] ?? 0) / max(1, (int)($ar['generated'] ?? 0))) * 100, 1) : 0.0;
}
unset($ar);

$pageTitle = 'Agent Analytics';
include __DIR__ . '/../../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 admin-dashboard">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Agent Analytics</div>
            <div class="text-muted small"><?php echo htmlspecialchars($agentName . ' · ' . $rangeLabel); ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../../dashboard/admin-dashboard.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3" method="get">
        <div class="col-md-3">
            <label class="form-label small text-muted">Agent</label>
            <select class="form-select form-select-sm" name="agent_id" onchange="this.form.submit();">
                <?php foreach ($agents as $a): ?>
                    <?php $id = (int)($a['id'] ?? 0); ?>
                    <option value="<?php echo $id; ?>" <?php echo $id===$agentId?'selected':''; ?>>
                        <?php echo htmlspecialchars((string)($a['full_name'] ?? '') . ' · ' . (string)($a['role'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Quick Range</label>
            <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
                <option value="current_month" <?php echo $preset==='current_month'?'selected':''; ?>>Current Month</option>
                <option value="last_day" <?php echo $preset==='last_day'?'selected':''; ?>>Last Day</option>
                <option value="last_week" <?php echo $preset==='last_week'?'selected':''; ?>>Last Week</option>
                <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Start</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars(substr($rangeStart,0,10)); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">End</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars(substr($rangeEnd,0,10)); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Generated</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['generated']); ?></div>
                        <div class="small text-muted">Forms: <?php echo number_format((int)$summary['forms']); ?> • Pending: <?php echo number_format((int)$summary['forms_pending']); ?></div>
                    </div>
                    <div class="text-primary fs-3"><i class="bi bi-collection"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Pending QA</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['pending_qa']); ?></div>
                    </div>
                    <div class="text-warning fs-3"><i class="bi bi-hourglass-split"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Qualified</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['qualified']); ?></div>
                    </div>
                    <div class="text-success fs-3"><i class="bi bi-check2-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Disqualified</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['disqualified']); ?></div>
                    </div>
                    <div class="text-danger fs-3"><i class="bi bi-x-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Delivered</div>
                        <div class="h4 mb-0"><?php echo number_format((int)$summary['delivered']); ?></div>
                    </div>
                    <div class="fs-3" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Today Attendance</div>
                        <div class="h5 mb-0"><?php echo htmlspecialchars($attStatus !== '' ? $attStatus : '—'); ?></div>
                        <div class="small text-muted">Late: <?php echo number_format($lateMin); ?> min</div>
                    </div>
                    <div class="text-info fs-3"><i class="bi bi-calendar-check"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-people me-1"></i>Agent Performance (<?php echo htmlspecialchars($rangeLabel); ?>)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Agent</th>
                            <th class="text-end">Generated</th>
                            <th class="text-end">Qualified</th>
                            <th class="text-end">Pending QA</th>
                            <th class="text-end">Disq</th>
                            <th class="text-end">Delivered</th>
                            <th class="text-end">Delivery%</th>
                            <th class="text-end">Target/Day</th>
                            <th class="text-end">Avg Q/Day</th>
                            <th class="text-end">Perf%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agentRows as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?><div class="text-muted small"><?php echo htmlspecialchars((string)($r['role'] ?? '')); ?></div></td>
                                <td class="text-end"><?php echo number_format((int)($r['generated'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['qualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['pending_qa'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['disqualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                <td class="text-end"><span class="badge bg-primary"><?php echo number_format((float)($r['delivery_pct'] ?? 0), 1); ?>%</span></td>
                                <td class="text-end"><?php echo number_format((int)($r['daily_target'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((float)($r['avg_qualified_per_day'] ?? 0), 2); ?></td>
                                <td class="text-end">
                                    <?php if (($r['performance_pct'] ?? null) === null): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <span class="badge <?php echo ((float)$r['performance_pct'] >= 100.0) ? 'bg-success' : (((float)$r['performance_pct'] >= 80.0) ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                                            <?php echo number_format((float)$r['performance_pct'], 1); ?>%
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($agentRows)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-bullseye me-1"></i>Target & Projection (<?php echo htmlspecialchars($now->format('M Y')); ?>)</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6"><div class="border rounded p-3"><div class="text-muted small">Daily Target</div><div class="h4 mb-0"><?php echo number_format($dailyTarget); ?></div><div class="small text-muted">Working days: <?php echo number_format($workingDays); ?></div></div></div>
                        <div class="col-6"><div class="border rounded p-3"><div class="text-muted small">Monthly Target</div><div class="h4 mb-0"><?php echo number_format($monthlyTarget); ?></div><div class="small text-muted">Projected qualified: <?php echo number_format($projMonth, 1); ?></div></div></div>
                        <div class="col-12"><div class="border rounded p-3"><div class="text-muted small">Projected Productivity</div><div class="h4 mb-0"><?php echo number_format($projPct, 1); ?>%</div><div class="small text-muted">Based on avg qualified/day in selected range</div></div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-activity me-1"></i>Daily Trend</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Date</th><th class="text-end">Generated</th><th class="text-end">Qualified</th><th class="text-end">Delivered</th></tr></thead>
                            <tbody>
                                <?php foreach ($trendRows as $t): ?>
                                    <tr>
                                        <td class="text-muted"><?php echo htmlspecialchars((string)($t['d'] ?? '')); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['generated'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['qualified'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['delivered'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($trendRows)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/layout/app_end.php'; ?>
