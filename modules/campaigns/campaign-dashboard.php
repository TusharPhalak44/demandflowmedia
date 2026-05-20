<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','director','manager_director','sales_director','sales_manager','operations_director','operations_manager','qa_director','qa_manager','email_marketing_director','email_marketing_manager']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$tabCounts = getCampaignTabCounts($dateFrom ?: null, $dateTo ?: null);
$overviewRows = getCampaignOverviewByStatus(null, $dateFrom ?: null, $dateTo ?: null);

if (isSDR()) {
    $assigned = getAssignedCampaignIdsForUser($userId);
    $overviewRows = array_values(array_filter($overviewRows, fn($r) => isset($assigned[(int)($r['id'] ?? 0)])));
}
if (isQA() && !isAdmin()) {
    $visible = getQaVisibleCampaignIdsForUser($userId, $role);
    if ($visible !== null) {
        $overviewRows = array_values(array_filter($overviewRows, fn($r) => isset($visible[(int)($r['id'] ?? 0)])));
        $tabCounts = array_fill_keys(['Draft','Active','Pause','Complete','Live'], 0);
        foreach ($overviewRows as $r) {
            $st = (string)($r['status'] ?? '');
            if (array_key_exists($st, $tabCounts)) $tabCounts[$st] += 1;
        }
    }
}

$conn = getDbConnection();
$pendingQa = 0;
if (isQA() && !isAdmin()) {
    $visible = getQaVisibleCampaignIdsForUser($userId, $role);
    if ($visible !== null && !empty($visible)) {
        $ids = array_keys($visible);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT SUM(CASE WHEN qa_status IS NULL OR qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) AS pending FROM leads WHERE campaign_id IN ($in)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $pendingQa = (int)($stmt->get_result()->fetch_assoc()['pending'] ?? 0);
        $stmt->close();
    }
} else {
    $rs = $conn->query("SELECT SUM(CASE WHEN qa_status IS NULL OR qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) AS pending FROM leads");
    $pendingQa = $rs ? (int)(($rs->fetch_assoc()['pending'] ?? 0)) : 0;
}

$campaignIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $overviewRows), fn($v) => $v > 0));
$deliveredByCampaign = [];
$pendingQaByCampaign = [];
$lastLeadAtByCampaign = [];
if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $where = ["campaign_id IN ($in)"];
    $params = $campaignIds;
    $types = str_repeat('i', count($campaignIds));
    if ($dateFrom !== '') { $where[] = "created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; $types .= 's'; }
    if ($dateTo !== '') { $where[] = "created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; $types .= 's'; }
    $whereSql = implode(' AND ', $where);

    $stmt = $conn->prepare("
        SELECT
          campaign_id,
          SUM(CASE WHEN client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
          SUM(CASE WHEN qa_status IN ('Pending','Reopened') OR qa_status IS NULL THEN 1 ELSE 0 END) AS pending_qa,
          MAX(created_at) AS last_lead_at
        FROM leads
        WHERE $whereSql
        GROUP BY campaign_id
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
$leadTotals = [
    'total' => 0,
    'pending' => 0,
    'qualified' => 0,
    'disqualified' => 0,
    'rework' => 0,
    'duplicate' => 0,
];

$budgetTotals = [
    'allocation_leads' => 0,
    'delivered_leads' => 0,
    'pending_allocation_leads' => 0,
    'allocation_usd' => 0.0,
    'delivered_usd' => 0.0,
    'pending_usd' => 0.0,
];

foreach ($overviewRows as $r) {
    $cid = (int)($r['id'] ?? 0);
    $allocation = (int)($r['total_leads'] ?? 0);
    $delivered = $cid > 0 ? (int)($deliveredByCampaign[$cid] ?? 0) : (int)($r['delivered'] ?? 0);
    $cpl = (float)($r['cpl'] ?? 0);
    $budgetTotals['allocation_leads'] += $allocation;
    $budgetTotals['delivered_leads'] += $delivered;
    $budgetTotals['pending_allocation_leads'] += max(0, $allocation - $delivered);
    if ($allocation > 0 && $cpl > 0) $budgetTotals['allocation_usd'] += ($allocation * $cpl);
    if ($delivered > 0 && $cpl > 0) $budgetTotals['delivered_usd'] += ($delivered * $cpl);
}
$budgetTotals['pending_usd'] = max(0.0, $budgetTotals['allocation_usd'] - $budgetTotals['delivered_usd']);

if (!empty($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $where = [];
    $params = $campaignIds;
    $types = str_repeat('i', count($campaignIds));
    $where[] = "campaign_id IN ($in)";
    if ($dateFrom !== '') {
        $where[] = "created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = "created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $conn->prepare("
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN qa_status IN ('Pending','Reopened') OR qa_status IS NULL THEN 1 ELSE 0 END) AS pending,
          SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          SUM(CASE WHEN qa_status = 'Rework Needed' THEN 1 ELSE 0 END) AS rework,
          SUM(CASE WHEN qa_status = 'Duplicate' THEN 1 ELSE 0 END) AS duplicate
        FROM leads
        WHERE $whereSql
    ");
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $leadTotals = $stmt->get_result()->fetch_assoc() ?: $leadTotals;
        $stmt->close();
        foreach ($leadTotals as $k => $v) $leadTotals[$k] = (int)$v;
    }
}

$statusChart = [
    'labels' => ['Live','Active','Paused','Completed','Draft'],
    'values' => [
        (int)($tabCounts['Live'] ?? 0),
        (int)($tabCounts['Active'] ?? 0),
        (int)($tabCounts['Pause'] ?? 0),
        (int)($tabCounts['Complete'] ?? 0),
        (int)($tabCounts['Draft'] ?? 0),
    ],
];
$qaChart = [
    'labels' => ['Qualified','Disqualified','Pending QA','Rework Needed','Duplicate'],
    'values' => [
        (int)($leadTotals['qualified'] ?? 0),
        (int)($leadTotals['disqualified'] ?? 0),
        (int)($leadTotals['pending'] ?? 0),
        (int)($leadTotals['rework'] ?? 0),
        (int)($leadTotals['duplicate'] ?? 0),
    ],
];

$leadsByCampaignRows = array_values(array_filter($overviewRows, fn($r) => (int)($r['total_leads'] ?? 0) > 0));
usort($leadsByCampaignRows, fn($a,$b) => ((int)($b['total_leads'] ?? 0)) <=> ((int)($a['total_leads'] ?? 0)));
$leadsByCampaignRows = array_slice($leadsByCampaignRows, 0, 12);
$leadsByCampaignChart = ['labels' => [], 'allocation' => [], 'delivered' => [], 'pending' => []];
foreach ($leadsByCampaignRows as $r) {
    $cid = (int)($r['id'] ?? 0);
    $alloc = (int)($r['total_leads'] ?? 0);
    $del = $cid > 0 ? (int)($deliveredByCampaign[$cid] ?? 0) : 0;
    $code = trim((string)($r['code'] ?? ''));
    $name = trim((string)($r['name'] ?? ''));
    $label = $code !== '' ? $code : $name;
    $leadsByCampaignChart['labels'][] = $label;
    $leadsByCampaignChart['allocation'][] = $alloc;
    $leadsByCampaignChart['delivered'][] = $del;
    $leadsByCampaignChart['pending'][] = max(0, $alloc - $del);
}

$riskCampaigns = [];
$today = date('Y-m-d');
$todayTs = strtotime($today . ' 00:00:00');
$soonTs = strtotime($today . ' 23:59:59 +3 days');
foreach ($overviewRows as $r) {
    if (((string)($r['status'] ?? '')) !== 'Live') continue;
    $end = trim((string)($r['end_date'] ?? ''));
    if ($end === '') continue;
    $endTs = strtotime($end . ' 23:59:59');
    if ($endTs === false) continue;
    if ($endTs < $todayTs || $endTs > $soonTs) continue;

    $cid = (int)($r['id'] ?? 0);
    $alloc = (int)($r['total_leads'] ?? 0);
    if ($alloc <= 0) continue;
    $del = $cid > 0 ? (int)($deliveredByCampaign[$cid] ?? 0) : 0;
    $pct = $alloc > 0 ? round(($del / $alloc) * 100) : 0;
    if ($pct >= 80) continue;

    $daysLeft = (int)ceil(($endTs - time()) / 86400);
    $riskCampaigns[] = [
        'id' => $cid,
        'code' => (string)($r['code'] ?? ''),
        'end_date' => $end,
        'days_left' => max(0, $daysLeft),
        'allocation' => $alloc,
        'delivered' => $del,
        'pacing_pct' => $pct,
        'pending_qa' => $cid > 0 ? (int)($pendingQaByCampaign[$cid] ?? 0) : 0,
    ];
}
usort($riskCampaigns, function($a,$b){
    $d = ($a['days_left'] ?? 0) <=> ($b['days_left'] ?? 0);
    if ($d !== 0) return $d;
    return ($a['pacing_pct'] ?? 0) <=> ($b['pacing_pct'] ?? 0);
});
$riskCampaigns = array_slice($riskCampaigns, 0, 20);

$liveRowsAll = array_values(array_filter($overviewRows, fn($r) => ((string)($r['status'] ?? '')) === 'Live'));
usort($liveRowsAll, fn($a,$b) => ((int)($b['total_leads'] ?? 0)) <=> ((int)($a['total_leads'] ?? 0)));
$top10Live = array_slice($liveRowsAll, 0, 10);
$top10Chart = ['labels' => [], 'allocation' => [], 'delivered' => [], 'pending' => []];
foreach ($top10Live as $r) {
    $cid = (int)($r['id'] ?? 0);
    $alloc = (int)($r['total_leads'] ?? 0);
    $del = $cid > 0 ? (int)($deliveredByCampaign[$cid] ?? 0) : (int)($r['delivered'] ?? 0);
    $code = trim((string)($r['code'] ?? ''));
    $name = trim((string)($r['name'] ?? ''));
    $label = $code !== '' ? $code : $name;
    $top10Chart['labels'][] = $label;
    $top10Chart['allocation'][] = $alloc;
    $top10Chart['delivered'][] = $del;
    $top10Chart['pending'][] = max(0, $alloc - $del);
}

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
$clientRollupRows = array_slice($clientRollupRows, 0, 25);

$agentScoreRows = [];
$liveCampaignIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $liveRowsAll), fn($v) => $v > 0));
if (!empty($liveCampaignIds)) {
    $in = implode(',', array_fill(0, count($liveCampaignIds), '?'));
    $params = $liveCampaignIds;
    $types = str_repeat('i', count($liveCampaignIds));
    $where = ["l.campaign_id IN ($in)"];
    if ($dateFrom !== '') { $where[] = "l.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; $types .= 's'; }
    if ($dateTo !== '') { $where[] = "l.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; $types .= 's'; }
    $w = implode(' AND ', $where);
    $sql = "
        SELECT
          l.agent_id,
          u.full_name,
          u.role,
          COUNT(*) AS total,
          SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
          SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) AS disqualified,
          SUM(CASE WHEN l.qa_status IN ('Pending','Reopened') OR l.qa_status IS NULL THEN 1 ELSE 0 END) AS pending,
          SUM(CASE WHEN l.qa_status='Rework Needed' THEN 1 ELSE 0 END) AS rework,
          SUM(CASE WHEN l.qa_status='Duplicate' THEN 1 ELSE 0 END) AS duplicate,
          (
            SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) * 2
            - SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END)
            - SUM(CASE WHEN l.qa_status='Duplicate' THEN 1 ELSE 0 END)
            - SUM(CASE WHEN l.qa_status='Rework Needed' THEN 1 ELSE 0 END)
          ) AS score
        FROM leads l
        LEFT JOIN users u ON u.id = l.agent_id
        WHERE $w
        GROUP BY l.agent_id
        ORDER BY score DESC, qualified DESC
        LIMIT 20
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $agentScoreRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$pageTitle = 'Campaign Dashboard';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Campaign Dashboard</h3>
      <div class="text-muted small">Overview of campaign statuses and workload.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary btn-sm" href="list"><i class="bi bi-list-ul me-1"></i>Manage Campaigns</a>
      <?php if (isAdmin() || isSalesDirector() || isSalesManager() || hasRole(['director','manager_director','operations_director'])): ?>
        <a class="btn btn-primary btn-sm" href="create"><i class="bi bi-plus-circle me-1"></i>Create Campaign</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-4">
          <label class="form-label">From</label>
          <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">To</label>
          <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="col-md-4 d-flex gap-2">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
          <a class="btn btn-light btn-sm" href="dashboard">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <?php
      $riskCount = count($riskCampaigns);
      $budgetPace = ((int)($budgetTotals['allocation_leads'] ?? 0) > 0)
        ? (int)round(((int)($budgetTotals['delivered_leads'] ?? 0) / max(1, (int)($budgetTotals['allocation_leads'] ?? 0))) * 100)
        : 0;
      $cards = [
        ['Live', (int)($tabCounts['Live'] ?? 0), 'success', 'bi-broadcast'],
        ['Active', (int)($tabCounts['Active'] ?? 0), 'primary', 'bi-lightning-charge'],
        ['Paused', (int)($tabCounts['Pause'] ?? 0), 'warning', 'bi-pause-circle'],
        ['Completed', (int)($tabCounts['Complete'] ?? 0), 'secondary', 'bi-check2-circle'],
      ];
    ?>
    <?php foreach ($cards as $c): ?>
      <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small"><?php echo htmlspecialchars($c[0]); ?></div>
              <div class="h4 mb-0"><?php echo (int)$c[1]; ?></div>
              <?php if ($c[0] === 'Live'): ?>
                <div class="text-muted small mt-1">Risk: <?php echo number_format($riskCount); ?> · Pace: <?php echo number_format($budgetPace); ?>%</div>
              <?php elseif ($c[0] === 'Active'): ?>
                <div class="text-muted small mt-1">Pending QA: <?php echo number_format((int)($leadTotals['pending'] ?? 0)); ?> · Rework: <?php echo number_format((int)($leadTotals['rework'] ?? 0)); ?></div>
              <?php elseif ($c[0] === 'Paused'): ?>
                <div class="text-muted small mt-1">Pending allocation: <?php echo number_format((int)($budgetTotals['pending_allocation_leads'] ?? 0)); ?></div>
              <?php elseif ($c[0] === 'Completed'): ?>
                <div class="text-muted small mt-1">Delivered: <?php echo number_format((int)($budgetTotals['delivered_leads'] ?? 0)); ?> · Allocation: <?php echo number_format((int)($budgetTotals['allocation_leads'] ?? 0)); ?></div>
              <?php endif; ?>
            </div>
            <div class="text-<?php echo htmlspecialchars($c[2]); ?> fs-2"><i class="bi <?php echo htmlspecialchars($c[3]); ?>"></i></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

  <div class="row g-3 mb-3">
    <?php
      $leadCards = [
        ['Total Leads', (int)($leadTotals['total'] ?? 0), 'dark', 'bi-collection'],
        ['Qualified', (int)($leadTotals['qualified'] ?? 0), 'success', 'bi-check2-circle'],
        ['Pending QA', (int)($leadTotals['pending'] ?? 0), 'warning', 'bi-hourglass-split'],
        ['Disqualified', (int)($leadTotals['disqualified'] ?? 0), 'danger', 'bi-x-circle'],
        ['Rework', (int)($leadTotals['rework'] ?? 0), 'info', 'bi-arrow-repeat'],
        ['Duplicate', (int)($leadTotals['duplicate'] ?? 0), 'secondary', 'bi-files'],
      ];
    ?>
    <?php foreach ($leadCards as $c): ?>
      <div class="col-md-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted small"><?php echo htmlspecialchars($c[0]); ?></div>
              <div class="h5 mb-0 font-monospace"><?php echo number_format((int)$c[1]); ?></div>
              <?php if ($c[0] === 'Total Leads'): ?>
                <div class="text-muted small mt-1">Allocation: <?php echo number_format((int)($budgetTotals['allocation_leads'] ?? 0)); ?> · Delivered: <?php echo number_format((int)($budgetTotals['delivered_leads'] ?? 0)); ?></div>
              <?php elseif ($c[0] === 'Qualified'): ?>
                <div class="text-muted small mt-1">Pace: <?php echo number_format($budgetPace); ?>% · Pending: <?php echo number_format((int)($budgetTotals['pending_allocation_leads'] ?? 0)); ?></div>
              <?php elseif ($c[0] === 'Pending QA'): ?>
                <div class="text-muted small mt-1">Rework: <?php echo number_format((int)($leadTotals['rework'] ?? 0)); ?> · Dup: <?php echo number_format((int)($leadTotals['duplicate'] ?? 0)); ?></div>
              <?php endif; ?>
            </div>
            <div class="text-<?php echo htmlspecialchars($c[2]); ?> fs-4"><i class="bi <?php echo htmlspecialchars($c[3]); ?>"></i></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-bar-chart-line me-1"></i>Leads per Campaign</span>
          <span class="text-muted small">Top 12 by allocation</span>
        </div>
        <div class="card-body">
          <canvas id="leadsByCampaignChart" height="220"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-alarm me-1"></i>Ending Soon (Risk)</span>
          <span class="text-muted small">≤ 3 days left &lt; 80% pacing</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Code</th>
                <th class="text-end">Days</th>
                <th class="text-end">Alloc</th>
                <th class="text-end">Del</th>
                <th class="text-end">Pacing</th>
                <th class="text-end">PQA</th>
                <th class="text-end">View</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($riskCampaigns)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No risk campaigns in current view.</td></tr>
              <?php else: ?>
                <?php foreach ($riskCampaigns as $rc): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars((string)($rc['code'] ?? '')); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($rc['days_left'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($rc['allocation'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($rc['delivered'] ?? 0)); ?></td>
                    <td class="text-end font-monospace text-danger"><?php echo number_format((int)($rc['pacing_pct'] ?? 0)); ?>%</td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($rc['pending_qa'] ?? 0)); ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-light border" href="view?id=<?php echo (int)($rc['id'] ?? 0); ?>"><i class="bi bi-eye"></i></a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-cash-stack me-1"></i>Allocation vs Delivered</div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-6">
              <div class="text-muted small">Allocation Leads</div>
              <div class="h5 mb-0"><?php echo (int)$budgetTotals['allocation_leads']; ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Delivered Leads</div>
              <div class="h5 mb-0"><?php echo (int)$budgetTotals['delivered_leads']; ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Allocation (USD)</div>
              <div class="h6 mb-0">$<?php echo htmlspecialchars(number_format((float)$budgetTotals['allocation_usd'], 2)); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Delivered (USD)</div>
              <div class="h6 mb-0">$<?php echo htmlspecialchars(number_format((float)$budgetTotals['delivered_usd'], 2)); ?></div>
            </div>
            <div class="col-12">
              <?php
                $pctAlloc = $budgetTotals['allocation_leads'] > 0 ? min(100, round(($budgetTotals['delivered_leads'] / max(1, $budgetTotals['allocation_leads'])) * 100)) : 0;
              ?>
              <div class="text-muted small mb-2">Progress</div>
              <div class="progress" style="height: 14px;">
                <div class="progress-bar bg-success" style="width: <?php echo (int)$pctAlloc; ?>%"></div>
              </div>
              <div class="d-flex justify-content-between text-muted small mt-2">
                <span><?php echo (int)$pctAlloc; ?>%</span>
                <span>Pending USD: $<?php echo htmlspecialchars(number_format((float)$budgetTotals['pending_usd'], 2)); ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-bar-chart me-1"></i>Top 10 Live Campaigns</span>
          <span class="text-muted small">Allocation vs delivered vs pending</span>
        </div>
        <div class="card-body">
          <canvas id="top10Chart" height="220"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-buildings me-1"></i>Client Rollup</span>
          <span class="text-muted small">Top 25 by pending leads</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Client</th>
                <th class="text-end">Campaigns</th>
                <th class="text-end">Live</th>
                <th class="text-end">Alloc</th>
                <th class="text-end">Del</th>
                <th class="text-end">Pend</th>
                <th class="text-end">Pacing</th>
                <th class="text-end">PQA</th>
                <th class="text-end">Last Lead</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($clientRollupRows)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No client data in current view.</td></tr>
              <?php else: ?>
                <?php foreach ($clientRollupRows as $cr): ?>
                  <?php
                    $clientCode = trim((string)($cr['client_code'] ?? ''));
                    if ($clientCode === '') $clientCode = 'No Client';
                    $q = [
                      'status' => 'Live',
                      'search' => trim((string)($cr['client_code'] ?? '')),
                      'date_from' => $dateFrom,
                      'date_to' => $dateTo,
                    ];
                    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
                    $clientHref = '../campaigns/list?' . http_build_query($q);
                  ?>
                  <tr>
                    <td class="fw-semibold">
                      <?php if (trim((string)($cr['client_code'] ?? '')) !== ''): ?>
                        <a class="text-decoration-none" href="<?php echo htmlspecialchars($clientHref); ?>"><?php echo htmlspecialchars($clientCode); ?></a>
                      <?php else: ?>
                        <?php echo htmlspecialchars($clientCode); ?>
                      <?php endif; ?>
                    </td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['campaigns'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['live'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['allocation_leads'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['delivered_leads'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['pending_leads'] ?? 0)); ?></td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['pacing_pct'] ?? 0)); ?>%</td>
                    <td class="text-end font-monospace"><?php echo number_format((int)($cr['pending_qa'] ?? 0)); ?></td>
                    <td class="text-end text-muted small">
                      <?php echo !empty($cr['last_lead_at']) ? htmlspecialchars(date('d M, H:i', strtotime((string)$cr['last_lead_at']))) : '—'; ?>
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

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-person-badge me-1"></i>Agent Lead Scoring (Live Campaigns)</span>
      <span class="text-muted small">Score = (Qualified×2) − Disqualified − Duplicate − Rework</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Agent</th>
            <th class="text-end">Total</th>
            <th class="text-end">Qualified</th>
            <th class="text-end">Pending</th>
            <th class="text-end">Disqualified</th>
            <th class="text-end">Rework</th>
            <th class="text-end">Duplicate</th>
            <th class="text-end">Score</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($agentScoreRows)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No agent data in current view.</td></tr>
          <?php else: ?>
            <?php foreach ($agentScoreRows as $a): ?>
              <?php
                $nm = trim((string)($a['full_name'] ?? ''));
                $rl = trim((string)($a['role'] ?? ''));
                $label = $nm !== '' ? formatUserNameWithRole($nm, $rl) : ('Agent #' . (int)($a['agent_id'] ?? 0));
                $agentHref = '../leads/leads-edit.php?' . http_build_query(array_filter([
                  'agent_id' => (int)($a['agent_id'] ?? 0),
                  'date_from' => $dateFrom,
                  'date_to' => $dateTo,
                ], fn($v) => $v !== '' && $v !== null && $v !== 0));
              ?>
              <tr>
                <td class="fw-semibold">
                  <a class="text-decoration-none" href="<?php echo htmlspecialchars($agentHref); ?>"><?php echo htmlspecialchars($label); ?></a>
                </td>
                <td class="text-end font-monospace"><?php echo number_format((int)($a['total'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-success"><?php echo number_format((int)($a['qualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($a['pending'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-danger"><?php echo number_format((int)($a['disqualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($a['rework'] ?? 0)); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($a['duplicate'] ?? 0)); ?></td>
                <td class="text-end font-monospace fw-semibold"><?php echo number_format((int)($a['score'] ?? 0)); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-broadcast me-1"></i>Live Campaign Progress</span>
      <span class="text-muted small">Allocation, delivered, pacing</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Campaign</th>
            <th>Client</th>
            <th class="text-end">Allocation</th>
            <th class="text-end">Delivered</th>
            <th class="text-end">Pending</th>
            <th style="width: 220px;">Pacing</th>
            <th class="text-end">Health</th>
            <th class="text-end">PQA</th>
            <th class="text-end">Last Lead</th>
            <th class="text-end">End</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $liveRows = array_slice($liveRowsAll, 0, 25);
          ?>
          <?php if (empty($liveRows)): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No live campaigns in current view.</td></tr>
          <?php else: ?>
            <?php foreach ($liveRows as $r): ?>
              <?php
                $alloc = (int)($r['total_leads'] ?? 0);
                $cid2 = (int)($r['id'] ?? 0);
                $del = $cid2 > 0 ? (int)($deliveredByCampaign[$cid2] ?? 0) : (int)($r['delivered'] ?? 0);
                $pend = max(0, $alloc - $del);
                $pct2 = $alloc > 0 ? min(100, round(($del / $alloc) * 100)) : 0;
                $pqa = $cid2 > 0 ? (int)($pendingQaByCampaign[$cid2] ?? 0) : 0;
                $ll = $cid2 > 0 ? ($lastLeadAtByCampaign[$cid2] ?? null) : null;
                $end = trim((string)($r['end_date'] ?? ''));

                $daysLeft = null;
                if ($end !== '') {
                    $endTs = strtotime($end . ' 23:59:59');
                    if ($endTs !== false) $daysLeft = (int)max(0, ceil(($endTs - time()) / 86400));
                }
                $lastAgeHours = null;
                if ($ll) {
                    $llTs = strtotime((string)$ll);
                    if ($llTs !== false) $lastAgeHours = (time() - $llTs) / 3600;
                }
                $pqaRatio = $alloc > 0 ? ($pqa / $alloc) : 0;

                $healthPoints = 0;
                if ($pct2 < 60) $healthPoints += 3;
                elseif ($pct2 < 80) $healthPoints += 2;
                elseif ($pct2 < 90) $healthPoints += 1;

                if ($daysLeft !== null) {
                    if ($daysLeft <= 1) $healthPoints += 2;
                    elseif ($daysLeft <= 3) $healthPoints += 1;
                }

                if ($lastAgeHours === null) $healthPoints += 1;
                else {
                    if ($lastAgeHours > 48) $healthPoints += 2;
                    elseif ($lastAgeHours > 24) $healthPoints += 1;
                }

                if ($alloc > 0) {
                    if ($pqa >= 30 || $pqaRatio > 0.30) $healthPoints += 2;
                    elseif ($pqa >= 15 || $pqaRatio > 0.15) $healthPoints += 1;
                }

                $healthLabel = 'Healthy';
                $healthClass = 'bg-success-subtle text-success';
                if ($healthPoints >= 6) { $healthLabel = 'Critical'; $healthClass = 'bg-danger-subtle text-danger'; }
                elseif ($healthPoints >= 3) { $healthLabel = 'Watch'; $healthClass = 'bg-warning-subtle text-warning'; }

                $ageText = '—';
                if ($lastAgeHours !== null) {
                    if ($lastAgeHours < 1) $ageText = 'Just now';
                    elseif ($lastAgeHours < 24) $ageText = round($lastAgeHours) . 'h ago';
                    else $ageText = round($lastAgeHours / 24) . 'd ago';
                }
                $tip = 'Pacing: ' . $pct2 . '%'
                    . ' | Days left: ' . ($daysLeft === null ? '—' : (string)$daysLeft)
                    . ' | PQA: ' . $pqa
                    . ' | Last lead: ' . $ageText;
              ?>
              <tr>
                <td class="fw-semibold">
                  <?php echo htmlspecialchars((string)($r['name'] ?? '')); ?>
                  <div class="text-muted small"><?php echo htmlspecialchars((string)($r['code'] ?? '')); ?></div>
                </td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['client_name'] ?? '')); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)$alloc); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)$del); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)$pend); ?></td>
                <td>
                  <div class="progress" style="height: 12px;">
                    <div class="progress-bar bg-success" style="width: <?php echo (int)$pct2; ?>%"></div>
                  </div>
                  <div class="d-flex justify-content-between text-muted small mt-1">
                    <span class="font-monospace"><?php echo (int)$pct2; ?>%</span>
                    <span class="font-monospace"><?php echo number_format((int)$del); ?>/<?php echo number_format((int)$alloc); ?></span>
                  </div>
                </td>
                <td class="text-end">
                  <span class="badge border <?php echo htmlspecialchars($healthClass); ?>" title="<?php echo htmlspecialchars($tip); ?>">
                    <?php echo htmlspecialchars($healthLabel); ?>
                  </span>
                </td>
                <td class="text-end font-monospace"><?php echo number_format((int)$pqa); ?></td>
                <td class="text-end text-muted small"><?php echo $ll ? htmlspecialchars(date('d M, H:i', strtotime((string)$ll))) : '—'; ?></td>
                <td class="text-end text-muted small"><?php echo $end !== '' ? htmlspecialchars($end) : '—'; ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-light border" href="view?id=<?php echo (int)($r['id'] ?? 0); ?>"><i class="bi bi-eye"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light fw-semibold">Recent Campaigns</div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Code</th>
            <th>Status</th>
            <th>Start</th>
            <th>End</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($overviewRows)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No campaigns available.</td></tr>
          <?php else: ?>
            <?php foreach (array_slice($overviewRows, 0, 15) as $r): ?>
              <?php
                $st = (string)($r['status'] ?? '');
                $map = ['Live'=>'success','Active'=>'primary','Pause'=>'warning','Complete'=>'secondary','Draft'=>'dark'];
                $cls = $map[$st] ?? 'secondary';
              ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['code'] ?? '')); ?></td>
                <td><span class="badge bg-<?php echo htmlspecialchars($cls); ?>"><?php echo htmlspecialchars($st); ?></span></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['start_date'] ?? '')); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['end_date'] ?? '')); ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-light border" href="view?id=<?php echo (int)($r['id'] ?? 0); ?>">
                    <i class="bi bi-eye"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
  if (!window.Chart) return;
  const leadsBy = <?php echo json_encode($leadsByCampaignChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const top10 = <?php echo json_encode($top10Chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  const leadsEl = document.getElementById('leadsByCampaignChart');
  if (leadsEl && leadsBy && Array.isArray(leadsBy.labels)) {
    new Chart(leadsEl, {
      type: 'bar',
      data: {
        labels: leadsBy.labels,
        datasets: [
          { label: 'Allocation', data: leadsBy.allocation || [], backgroundColor: 'rgba(59, 130, 246, 0.55)' },
          { label: 'Delivered', data: leadsBy.delivered || [], backgroundColor: 'rgba(34, 197, 94, 0.75)' },
          { label: 'Pending', data: leadsBy.pending || [], backgroundColor: 'rgba(245, 158, 11, 0.75)' }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { mode: 'index', intersect: false }
        },
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { ticks: { maxRotation: 0, autoSkip: true } },
          y: { beginAtZero: true, ticks: { precision: 0 } }
        }
      }
    });
  }

  const top10El = document.getElementById('top10Chart');
  if (top10El && top10 && Array.isArray(top10.labels)) {
    new Chart(top10El, {
      type: 'bar',
      data: {
        labels: top10.labels,
        datasets: [
          { label: 'Allocation', data: top10.allocation || [], backgroundColor: 'rgba(59, 130, 246, 0.65)' },
          { label: 'Delivered', data: top10.delivered || [], backgroundColor: 'rgba(34, 197, 94, 0.75)' },
          { label: 'Pending', data: top10.pending || [], backgroundColor: 'rgba(245, 158, 11, 0.75)' }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { mode: 'index', intersect: false }
        },
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: {
            ticks: { maxRotation: 0, autoSkip: true }
          },
          y: {
            beginAtZero: true,
            ticks: { precision: 0 }
          }
        }
      }
    });
  }
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
