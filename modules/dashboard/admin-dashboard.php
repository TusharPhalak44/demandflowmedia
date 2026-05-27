<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('admin');
ensureDatabaseSchema();

$conn = getDbConnection();
$now = new DateTime();
$todayDate = $now->format('Y-m-d');
$todayStart = $todayDate . ' 00:00:00';
$todayEnd = $todayDate . ' 23:59:59';
$monthStart = $now->format('Y-m-01') . ' 00:00:00';
$monthEnd = $now->format('Y-m-t') . ' 23:59:59';

$preset = isset($_GET['range_preset']) ? (string)$_GET['range_preset'] : 'current_month';
$startInput = isset($_GET['start_date']) ? (string)$_GET['start_date'] : '';
$endInput = isset($_GET['end_date']) ? (string)$_GET['end_date'] : '';

$rangeStart = $monthStart;
$rangeEnd = $monthEnd;
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
    } else {
        $preset = 'current_month';
    }
} catch (Throwable $e) {}

$todayKpi = ['generated' => 0, 'pending_qa' => 0, 'qualified' => 0, 'disqualified' => 0, 'delivered' => 0, 'forms' => 0, 'forms_pending' => 0];
$rangeKpi = $todayKpi;

$kpiSql = "
    SELECT
      COUNT(*) AS generated,
      SUM(CASE WHEN (qa_status IS NULL OR qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
      SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
      SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
      SUM(CASE WHEN (qa_status IN ('Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') OR client_delivery_status IN ('Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress')) THEN 1 ELSE 0 END) AS delivered,
      SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) AS forms,
      SUM(CASE WHEN qa_status = 'Qualified' AND form_done = 'No' THEN 1 ELSE 0 END) AS forms_pending
    FROM leads
    WHERE created_at BETWEEN ? AND ?
";
$stmt = $conn->prepare($kpiSql);
if ($stmt) {
    $stmt->bind_param('ss', $todayStart, $todayEnd);
    $stmt->execute();
    $todayKpi = $stmt->get_result()->fetch_assoc() ?: $todayKpi;
    $stmt->close();
}
$stmt = $conn->prepare($kpiSql);
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $rangeKpi = $stmt->get_result()->fetch_assoc() ?: $rangeKpi;
    $stmt->close();
}

$liveCampaigns = 0;
$stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM campaigns c
    JOIN campaign_details d ON d.campaign_id = c.id
    WHERE c.active = 1 AND d.status = 'Live'
");
if ($stmt) { $stmt->execute(); $liveCampaigns = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0); $stmt->close(); }
$activeCampaigns = 0;
$rs = $conn->query("SELECT COUNT(*) AS c FROM campaigns WHERE active = 1");
if ($rs) $activeCampaigns = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);

$onlineUsers = 0;
$onlineInternal = 0;
$rs = $conn->query("SELECT COUNT(*) AS c FROM user_presence WHERE is_online = 1");
if ($rs) $onlineUsers = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);
$rs = $conn->query("
    SELECT COUNT(*) AS c
    FROM user_presence p
    JOIN users u ON u.id = p.user_id
    WHERE p.is_online = 1 AND u.is_active = 1 AND u.role NOT LIKE 'client_%' AND u.role NOT LIKE 'vendor_%'
");
if ($rs) $onlineInternal = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);

$agentsLoggedInToday = 0;
$agentsLateToday = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM hr_attendance_days WHERE work_date = ? AND punch_in IS NOT NULL");
if ($stmt) { $stmt->bind_param('s', $todayDate); $stmt->execute(); $agentsLoggedInToday = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0); $stmt->close(); }
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM hr_attendance_days WHERE work_date = ? AND late_minutes > 0");
if ($stmt) { $stmt->bind_param('s', $todayDate); $stmt->execute(); $agentsLateToday = (int)(($stmt->get_result()->fetch_assoc() ?: [])['c'] ?? 0); $stmt->close(); }

$todayCapacity = 0;
$yy = (int)$now->format('Y');
$mo = (int)$now->format('m');
if (function_exists('productivityTargetsTableExists') && productivityTargetsTableExists()) {
    $stmt = $conn->prepare("
        SELECT
          COALESCE(SUM(
            CASE
              WHEN d.status IN ('Full Day','In Progress') THEN COALESCE(pt.daily_target,0)
              WHEN d.status = 'Half Day' THEN FLOOR(COALESCE(pt.daily_target,0) / 2)
              ELSE 0
            END
          ),0) AS cap
        FROM hr_attendance_days d
        LEFT JOIN productivity_targets pt
          ON pt.agent_id = d.user_id AND pt.year = ? AND pt.month = ?
        WHERE d.work_date = ?
    ");
    if ($stmt) {
        $stmt->bind_param('iis', $yy, $mo, $todayDate);
        $stmt->execute();
        $todayCapacity = (int)(($stmt->get_result()->fetch_assoc() ?: [])['cap'] ?? 0);
        $stmt->close();
    }
}

$invoiceTodayTotal = 0.0;
$invoiceRangeTotal = 0.0;
$paidRangeTotal = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE issue_date = ?");
if ($stmt) { $stmt->bind_param('s', $todayDate); $stmt->execute(); $invoiceTodayTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }
$sd = substr($rangeStart, 0, 10);
$ed = substr($rangeEnd, 0, 10);
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE issue_date BETWEEN ? AND ?");
if ($stmt) { $stmt->bind_param('ss', $sd, $ed); $stmt->execute(); $invoiceRangeTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) AS s FROM revenue_invoices WHERE status = 'Paid' AND issue_date BETWEEN ? AND ?");
if ($stmt) { $stmt->bind_param('ss', $sd, $ed); $stmt->execute(); $paidRangeTotal = (float)(($stmt->get_result()->fetch_assoc() ?: [])['s'] ?? 0); $stmt->close(); }

$topCampaigns = [];
$stmt = $conn->prepare("
    SELECT c.name, COUNT(l.id) AS c
    FROM leads l
    JOIN campaigns c ON c.id = l.campaign_id
    WHERE l.created_at BETWEEN ? AND ?
    GROUP BY l.campaign_id
    ORDER BY c DESC
    LIMIT 3
");
if ($stmt) { $stmt->bind_param('ss', $rangeStart, $rangeEnd); $stmt->execute(); $topCampaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; $stmt->close(); }

$topAgents = [];
$stmt = $conn->prepare("
    SELECT u.full_name, COUNT(l.id) AS c
    FROM leads l
    JOIN users u ON u.id = l.agent_id
    WHERE l.created_at BETWEEN ? AND ?
    GROUP BY l.agent_id
    ORDER BY c DESC
    LIMIT 3
");
if ($stmt) { $stmt->bind_param('ss', $rangeStart, $rangeEnd); $stmt->execute(); $topAgents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; $stmt->close(); }

$recentLeads = [];
$rs = $conn->query("
    SELECT l.id, l.company_name, l.email, l.qa_status, l.client_delivery_status, l.created_at,
           c.name AS campaign_name, u.full_name AS agent_name
    FROM leads l
    LEFT JOIN campaigns c ON c.id = l.campaign_id
    LEFT JOIN users u ON u.id = l.agent_id
    ORDER BY l.created_at DESC
    LIMIT 12
");
if ($rs) $recentLeads = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$campPerf = [];
$stmt = $conn->prepare("
    SELECT c.id, c.name, COUNT(l.id) AS leads_count
    FROM campaigns c
    LEFT JOIN leads l ON l.campaign_id = c.id AND l.created_at BETWEEN ? AND ?
    WHERE c.active = 1
    GROUP BY c.id
");
if ($stmt) { $stmt->bind_param('ss', $rangeStart, $rangeEnd); $stmt->execute(); $campPerf = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; $stmt->close(); }

$revByCamp = [];
$stmt = $conn->prepare("SELECT campaign_id, COALESCE(SUM(total),0) AS rev FROM revenue_invoices WHERE issue_date BETWEEN ? AND ? GROUP BY campaign_id");
if ($stmt) {
    $stmt->bind_param('ss', $sd, $ed);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) $revByCamp[(int)($r['campaign_id'] ?? 0)] = (float)($r['rev'] ?? 0);
}
$expByCamp = [];
$stmt = $conn->prepare("SELECT campaign_id, COALESCE(SUM(amount),0) AS exp FROM revenue_manual_expenses WHERE expense_date BETWEEN ? AND ? GROUP BY campaign_id");
if ($stmt) {
    $stmt->bind_param('ss', $sd, $ed);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) $expByCamp[(int)($r['campaign_id'] ?? 0)] = (float)($r['exp'] ?? 0);
}

$roiRows = [];
foreach ($campPerf as $r) {
    $cid = (int)($r['id'] ?? 0);
    $rev = (float)($revByCamp[$cid] ?? 0);
    $exp = (float)($expByCamp[$cid] ?? 0);
    $roi = $exp > 0 ? (($rev - $exp) / $exp) : ($rev > 0 ? 1.0 : 0.0);
    $roiRows[] = ['name' => (string)($r['name'] ?? ''), 'leads' => (int)($r['leads_count'] ?? 0), 'revenue' => $rev, 'expense' => $exp, 'roi' => $roi];
}
usort($roiRows, fn($a,$b) => ($b['roi'] <=> $a['roi']));
$top3Roi = array_slice($roiRows, 0, 3);
$bottom3Roi = array_slice(array_reverse($roiRows), 0, 3);

$dateFrom = $sd;
$dateTo = $ed;
$tabCounts = function_exists('getCampaignTabCounts') ? getCampaignTabCounts($dateFrom, $dateTo) : [];
$overviewRows = function_exists('getCampaignOverviewByStatus') ? getCampaignOverviewByStatus(null, $dateFrom, $dateTo) : [];
$deliveredByCampaign = [];
$pendingQaByCampaign = [];
$lastLeadAtByCampaign = [];
$campaignIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $overviewRows), fn($v) => $v > 0));
if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $types = str_repeat('i', count($campaignIds)) . 'ss';
    $params = array_merge($campaignIds, [$rangeStart, $rangeEnd]);
    $stmt = $conn->prepare("
        SELECT
          l.campaign_id,
          SUM(CASE WHEN l.client_delivery_status IN ('Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') THEN 1 ELSE 0 END) AS delivered,
          SUM(CASE WHEN (l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
          MAX(l.created_at) AS last_lead_at
        FROM leads l
        WHERE l.campaign_id IN ($in) AND l.created_at BETWEEN ? AND ?
        GROUP BY l.campaign_id
    ");
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $cid = (int)($r['campaign_id'] ?? 0);
            if ($cid <= 0) continue;
            $deliveredByCampaign[$cid] = (int)($r['delivered'] ?? 0);
            $pendingQaByCampaign[$cid] = (int)($r['pending_qa'] ?? 0);
            $lastLeadAtByCampaign[$cid] = $r['last_lead_at'] ?? null;
        }
    }
}

$budgetTotals = [
    'allocation_leads' => 0,
    'delivered_leads' => 0,
    'pending_leads' => 0,
    'allocation_usd' => 0.0,
    'delivered_usd' => 0.0,
    'pending_usd' => 0.0,
];
$riskCampaigns = [];
foreach ($overviewRows as $r) {
    $campId = (int)($r['id'] ?? 0);
    $alloc = (int)($r['total_leads'] ?? 0);
    $del = $campId > 0 ? (int)($deliveredByCampaign[$campId] ?? 0) : (int)($r['delivered'] ?? 0);
    $cpl = (float)($r['cpl'] ?? 0);
    $budgetTotals['allocation_leads'] += $alloc;
    $budgetTotals['delivered_leads'] += $del;
    $budgetTotals['pending_leads'] += max(0, $alloc - $del);
    $budgetTotals['allocation_usd'] += ($alloc * $cpl);
    $budgetTotals['delivered_usd'] += ($del * $cpl);
    $budgetTotals['pending_usd'] += (max(0, $alloc - $del) * $cpl);

    $status = (string)($r['status'] ?? '');
    $endDate = (string)($r['end_date'] ?? '');
    if ($status !== 'Live' || $endDate === '') continue;
    $endTs = strtotime($endDate . ' 23:59:59');
    if ($endTs === false) continue;
    $daysLeft = (int)ceil(($endTs - time()) / 86400);
    if ($daysLeft < 0 || $daysLeft > 3) continue;
    $pct = $alloc > 0 ? (int)round(($del / max(1, $alloc)) * 100) : 0;
    if ($pct >= 80) continue;
    $riskCampaigns[] = [
        'id' => $campId,
        'code' => (string)($r['code'] ?? ''),
        'name' => (string)($r['name'] ?? ''),
        'end_date' => $endDate,
        'days_left' => $daysLeft,
        'allocation' => $alloc,
        'delivered' => $del,
        'pacing_pct' => $pct,
        'pending_qa' => $campId > 0 ? (int)($pendingQaByCampaign[$campId] ?? 0) : 0,
    ];
}
usort($riskCampaigns, function($a,$b){
    $d = ($a['days_left'] ?? 0) <=> ($b['days_left'] ?? 0);
    if ($d !== 0) return $d;
    return ($a['pacing_pct'] ?? 0) <=> ($b['pacing_pct'] ?? 0);
});
$riskCampaigns = array_slice($riskCampaigns, 0, 12);

$clientRollups = [];
foreach ($overviewRows as $r) {
    $campId = (int)($r['id'] ?? 0);
    $cid = (int)($r['client_id'] ?? 0);
    $cc = trim((string)($r['client_code'] ?? ''));
    $cn = trim((string)($r['client_name'] ?? ''));
    $key = $cid > 0 ? ('id:' . $cid) : ($cc !== '' ? ('code:' . $cc) : 'no-client');
    if (!isset($clientRollups[$key])) {
        $clientRollups[$key] = [
            'client_id' => $cid,
            'client_code' => $cc,
            'client_name' => $cn,
            'campaigns' => 0,
            'live' => 0,
            'allocation_leads' => 0,
            'delivered_leads' => 0,
            'pending_qa' => 0,
            'last_lead_at' => null,
        ];
    }
    $clientRollups[$key]['campaigns'] += 1;
    if (((string)($r['status'] ?? '')) === 'Live') $clientRollups[$key]['live'] += 1;
    $alloc = (int)($r['total_leads'] ?? 0);
    $del = $campId > 0 ? (int)($deliveredByCampaign[$campId] ?? 0) : (int)($r['delivered'] ?? 0);
    $clientRollups[$key]['allocation_leads'] += $alloc;
    $clientRollups[$key]['delivered_leads'] += $del;
    $clientRollups[$key]['pending_qa'] += $campId > 0 ? (int)($pendingQaByCampaign[$campId] ?? 0) : 0;
    $ll = $campId > 0 ? ($lastLeadAtByCampaign[$campId] ?? null) : null;
    if ($ll) {
        if (!$clientRollups[$key]['last_lead_at'] || strtotime((string)$ll) > strtotime((string)$clientRollups[$key]['last_lead_at'])) {
            $clientRollups[$key]['last_lead_at'] = $ll;
        }
    }
}
$clientRollupRows = array_values($clientRollups);
foreach ($clientRollupRows as &$cr) {
    $cr['pending_leads'] = max(0, (int)$cr['allocation_leads'] - (int)$cr['delivered_leads']);
    $cr['pacing_pct'] = ((int)$cr['allocation_leads'] > 0)
        ? min(100, round(((int)$cr['delivered_leads'] / max(1, (int)$cr['allocation_leads'])) * 100))
        : 0;
}
unset($cr);
usort($clientRollupRows, fn($a,$b) => (($b['pending_leads'] ?? 0) <=> ($a['pending_leads'] ?? 0)));
$clientRollupRows = array_slice($clientRollupRows, 0, 10);

$sales = ['overdue' => 0, 'open' => 0, 'won' => 0, 'lost' => 0];
$rs = $conn->query("SELECT COUNT(*) AS c FROM sales_leads WHERE next_follow_up_at IS NOT NULL AND next_follow_up_at < NOW() AND status NOT IN ('Closed Won','Closed Lost')");
if ($rs) $sales['overdue'] = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);
$stmt = $conn->prepare("
    SELECT
      SUM(CASE WHEN status NOT IN ('Closed Won','Closed Lost') THEN 1 ELSE 0 END) AS open_cnt,
      SUM(CASE WHEN status = 'Closed Won' THEN 1 ELSE 0 END) AS won_cnt,
      SUM(CASE WHEN status = 'Closed Lost' THEN 1 ELSE 0 END) AS lost_cnt
    FROM sales_leads
    WHERE created_at BETWEEN ? AND ?
");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $sales['open'] = (int)($r['open_cnt'] ?? 0);
    $sales['won'] = (int)($r['won_cnt'] ?? 0);
    $sales['lost'] = (int)($r['lost_cnt'] ?? 0);
}

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 admin-dashboard">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Admin Dashboard</div>
            <div class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../admin/settings.php')); ?>"><i class="bi bi-sliders me-1"></i>Settings</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../admin/analytics/campaigns.php')); ?>"><i class="bi bi-graph-up-arrow me-1"></i>Analytics</a>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3" method="get">
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
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($sd); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">End</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($ed); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Online Users</div>
                        <div class="h4 mb-0"><?php echo number_format($onlineUsers); ?></div>
                        <div class="small text-muted">Internal: <?php echo number_format($onlineInternal); ?></div>
                    </div>
                    <div class="text-primary fs-2"><i class="bi bi-people"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Live Campaigns</div>
                        <div class="h4 mb-0"><?php echo number_format($liveCampaigns); ?></div>
                        <div class="small text-muted">Active: <?php echo number_format($activeCampaigns); ?> · Risk: <?php echo number_format(count($riskCampaigns)); ?></div>
                    </div>
                    <div class="text-success fs-2"><i class="bi bi-broadcast"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Agents Logged In (Today)</div>
                        <div class="h4 mb-0"><?php echo number_format($agentsLoggedInToday); ?></div>
                        <div class="small text-muted">Late: <?php echo number_format($agentsLateToday); ?> · On-time: <?php echo number_format(max(0, $agentsLoggedInToday - $agentsLateToday)); ?></div>
                    </div>
                    <div class="text-info fs-2"><i class="bi bi-box-arrow-in-right"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Max Production Capacity (Today)</div>
                        <div class="h4 mb-0"><?php echo number_format($todayCapacity); ?></div>
                        <div class="small text-muted">Avg/agent: <?php echo number_format($agentsLoggedInToday > 0 ? round($todayCapacity / max(1, $agentsLoggedInToday)) : 0); ?> · Based on targets</div>
                    </div>
                    <div class="text-warning fs-2"><i class="bi bi-bullseye"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-calendar-day me-1"></i>Today</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Generated</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['generated']); ?></div>
                                <div class="small text-muted">Forms: <?php echo number_format((int)$todayKpi['forms']); ?> • Pending: <?php echo number_format((int)$todayKpi['forms_pending']); ?></div>
                            </div>
                            <div class="text-primary fs-4"><i class="bi bi-collection"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Pending QA</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['pending_qa']); ?></div>
                            </div>
                            <div class="text-warning fs-4"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Qualified</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['qualified']); ?></div>
                            </div>
                            <div class="text-success fs-4"><i class="bi bi-check2-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Disqualified</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['disqualified']); ?></div>
                            </div>
                            <div class="text-danger fs-4"><i class="bi bi-x-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Delivered</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$todayKpi['delivered']); ?></div>
                            </div>
                            <div class="fs-4" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Revenue Issued</div>
                                <div class="h4 mb-0"><?php echo number_format($invoiceTodayTotal, 2); ?></div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-currency-dollar"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-range me-1"></i>Selected Range</span>
            <span class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Generated</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['generated']); ?></div>
                                <div class="small text-muted">Forms: <?php echo number_format((int)$rangeKpi['forms']); ?> • Pending: <?php echo number_format((int)$rangeKpi['forms_pending']); ?></div>
                            </div>
                            <div class="text-primary fs-4"><i class="bi bi-collection"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Pending QA</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['pending_qa']); ?></div>
                            </div>
                            <div class="text-warning fs-4"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Qualified</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['qualified']); ?></div>
                            </div>
                            <div class="text-success fs-4"><i class="bi bi-check2-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Disqualified</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['disqualified']); ?></div>
                            </div>
                            <div class="text-danger fs-4"><i class="bi bi-x-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-purple">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Delivered</div>
                                <div class="h4 mb-0"><?php echo number_format((int)$rangeKpi['delivered']); ?></div>
                            </div>
                            <div class="fs-4" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Revenue</div>
                                <div class="h5 mb-0"><?php echo number_format($invoiceRangeTotal, 2); ?></div>
                                <div class="small text-muted">Paid: <?php echo number_format($paidRangeTotal, 2); ?></div>
                            </div>
                            <div class="text-info fs-4"><i class="bi bi-cash-stack"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-xl-6">
                    <div class="fw-semibold mb-2">Top Campaigns (Leads)</div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Campaign</th><th class="text-end">Leads</th></tr></thead>
                        <tbody>
                            <?php foreach ($topCampaigns as $r): ?>
                                <tr><td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td><td class="text-end"><?php echo number_format((int)($r['c'] ?? 0)); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($topCampaigns)): ?><tr><td colspan="2" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="fw-semibold mb-2">Top Agents (Leads)</div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Agent</th><th class="text-end">Leads</th></tr></thead>
                        <tbody>
                            <?php foreach ($topAgents as $r): ?>
                                <tr><td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></td><td class="text-end"><?php echo number_format((int)($r['c'] ?? 0)); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($topAgents)): ?><tr><td colspan="2" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle me-1"></i>Ending Soon (Risk)</span>
                    <span class="text-muted small">Live · ≤ 3 days · &lt; 80%</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Campaign</th>
                                    <th class="text-end">Days</th>
                                    <th class="text-end">Alloc</th>
                                    <th class="text-end">Del</th>
                                    <th class="text-end">Pace</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($riskCampaigns as $r): ?>
                                    <?php
                                        $label = trim((string)($r['code'] ?? ''));
                                        if ($label === '') $label = (string)($r['name'] ?? '');
                                    ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBackUrl('../campaigns/campaign-details.php?id=' . (int)($r['id'] ?? 0))); ?>"><?php echo htmlspecialchars($label); ?></a>
                                            <div class="text-muted small">PQA: <?php echo number_format((int)($r['pending_qa'] ?? 0)); ?></div>
                                            <div class="progress mt-2" style="height:6px;">
                                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo (int)max(0, min(100, (int)($r['pacing_pct'] ?? 0))); ?>%"></div>
                                            </div>
                                        </td>
                                        <td class="text-end"><?php echo number_format((int)($r['days_left'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['allocation'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                        <td class="text-end"><span class="badge bg-warning text-dark"><?php echo number_format((int)($r['pacing_pct'] ?? 0)); ?>%</span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($riskCampaigns)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No risk campaigns in this range.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-arrows-angle-contract me-1"></i>Allocation vs Delivered</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="kpi-tile kpi-primary p-3 h-100">
                                <div class="text-muted small">Allocation (Leads)</div>
                                <div class="h4 mb-0"><?php echo number_format((int)($budgetTotals['allocation_leads'] ?? 0)); ?></div>
                                <div class="small text-muted">Pending: <?php echo number_format((int)($budgetTotals['pending_leads'] ?? 0)); ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="kpi-tile kpi-success p-3 h-100">
                                <div class="text-muted small">Delivered (Leads)</div>
                                <div class="h4 mb-0"><?php echo number_format((int)($budgetTotals['delivered_leads'] ?? 0)); ?></div>
                                <div class="small text-muted">Pacing: <?php echo number_format(((int)($budgetTotals['allocation_leads'] ?? 0)) > 0 ? round(((int)($budgetTotals['delivered_leads'] ?? 0) / max(1, (int)($budgetTotals['allocation_leads'] ?? 0))) * 100) : 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="kpi-tile kpi-info p-3 h-100">
                                <div class="text-muted small">Allocation (USD)</div>
                                <div class="h4 mb-0"><?php echo number_format((float)($budgetTotals['allocation_usd'] ?? 0), 2); ?></div>
                                <div class="small text-muted">Pending: <?php echo number_format((float)($budgetTotals['pending_usd'] ?? 0), 2); ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="kpi-tile kpi-purple p-3 h-100">
                                <div class="text-muted small">Delivered (USD)</div>
                                <div class="h4 mb-0"><?php echo number_format((float)($budgetTotals['delivered_usd'] ?? 0), 2); ?></div>
                                <div class="small text-muted">Range: <?php echo htmlspecialchars($rangeLabel); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="text-muted small mt-3">Totals are calculated using campaign CPL × leads and delivered leads in the selected range.</div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-building me-1"></i>Client Rollup</span>
                    <span class="text-muted small">Top by pending</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Client</th>
                                    <th class="text-end">Live</th>
                                    <th class="text-end">Alloc</th>
                                    <th class="text-end">Del</th>
                                    <th class="text-end">Pending</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientRollupRows as $r): ?>
                                    <?php
                                        $label = trim((string)($r['client_code'] ?? ''));
                                        if ($label === '') $label = trim((string)($r['client_name'] ?? ''));
                                        if ($label === '') $label = 'No Client';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <?php echo htmlspecialchars($label); ?>
                                            <div class="text-muted small">PQA: <?php echo number_format((int)($r['pending_qa'] ?? 0)); ?> · Pace: <?php echo number_format((int)($r['pacing_pct'] ?? 0)); ?>%</div>
                                        </td>
                                        <td class="text-end"><?php echo number_format((int)($r['live'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['allocation_leads'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($r['delivered_leads'] ?? 0)); ?></td>
                                        <td class="text-end"><span class="badge bg-secondary"><?php echo number_format((int)($r['pending_leads'] ?? 0)); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($clientRollupRows)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No client rollup data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-cash-coin me-1"></i>Earnings Overview (Campaign ROI)</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <div class="fw-semibold mb-2">Top 3</div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Campaign</th><th class="text-end">Leads</th><th class="text-end">Revenue</th><th class="text-end">ROI</th></tr></thead>
                        <tbody>
                            <?php foreach ($top3Roi as $r): ?>
                                <tr><td class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></td><td class="text-end"><?php echo number_format($r['leads']); ?></td><td class="text-end"><?php echo number_format($r['revenue'],2); ?></td><td class="text-end"><?php echo number_format($r['roi']*100,1); ?>%</td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($top3Roi)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="fw-semibold mb-2">Bottom 3</div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Campaign</th><th class="text-end">Leads</th><th class="text-end">Revenue</th><th class="text-end">ROI</th></tr></thead>
                        <tbody>
                            <?php foreach ($bottom3Roi as $r): ?>
                                <tr><td class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></td><td class="text-end"><?php echo number_format($r['leads']); ?></td><td class="text-end"><?php echo number_format($r['revenue'],2); ?></td><td class="text-end"><?php echo number_format($r['roi']*100,1); ?>%</td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($bottom3Roi)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-bar-chart-line me-1"></i>Sales Department</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-primary">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Open</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['open']); ?></div>
                            </div>
                            <div class="text-primary fs-2"><i class="bi bi-folder2-open"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-warning">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Overdue</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['overdue']); ?></div>
                            </div>
                            <div class="text-warning fs-2"><i class="bi bi-alarm"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-success">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Closed Won</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['won']); ?></div>
                            </div>
                            <div class="text-success fs-2"><i class="bi bi-check2-all"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 kpi-tile kpi-danger">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small">Closed Lost</div>
                                <div class="h4 mb-0"><?php echo number_format($sales['lost']); ?></div>
                            </div>
                            <div class="text-danger fs-2"><i class="bi bi-x-octagon"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-clock-history me-1"></i>Recent Leads</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Company</th>
                            <th>Email</th>
                            <th>Campaign</th>
                            <th>Agent</th>
                            <th>Status</th>
                            <th class="text-end">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLeads as $l): ?>
                            <?php
                                $qa = (string)($l['qa_status'] ?? '');
                                $del = (string)($l['client_delivery_status'] ?? '');
                                $status = $del === 'Delivered' ? 'Delivered' : ($qa !== '' ? $qa : 'Pending');
                                $badge = 'secondary';
                                if ($status === 'Qualified') $badge = 'success';
                                elseif ($status === 'Disqualified') $badge = 'danger';
                                elseif ($status === 'Delivered') $badge = 'primary';
                            ?>
                            <tr>
                                <td class="text-muted"><?php echo (int)($l['id'] ?? 0); ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($l['company_name'] ?? '')); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars((string)($l['email'] ?? '')); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars((string)($l['campaign_name'] ?? '')); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars((string)($l['agent_name'] ?? '')); ?></td>
                                <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                <td class="text-end text-muted small"><?php echo htmlspecialchars((string)($l['created_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentLeads)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No recent leads.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
