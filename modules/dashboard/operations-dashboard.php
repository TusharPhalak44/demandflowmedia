<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['agent','operations_agent','operations_manager','operations_director']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$isTeamView = in_array($role, ['operations_manager', 'operations_director'], true);

try {
  $now = new DateTime();
  $todayStart = (clone $now)->setTime(0, 0, 0)->format('Y-m-d H:i:s');
  $todayEnd = (clone $now)->setTime(23, 59, 59)->format('Y-m-d H:i:s');
  $monthStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
  $monthEnd = (clone $now)->modify('last day of this month')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
} catch (Throwable $e) {
  $todayStart = date('Y-m-d 00:00:00');
  $todayEnd = date('Y-m-d 23:59:59');
  $monthStart = date('Y-m-01 00:00:00');
  $monthEnd = date('Y-m-t 23:59:59');
}

$conn = getDbConnection();
$agentIds = [$userId];
if ($isTeamView) {
  $agentIds = [];
  $teamIds = getUserTeamIds($userId);
  if (!empty($teamIds)) {
    $in = implode(',', array_fill(0, count($teamIds), '?'));
    $typesT = str_repeat('i', count($teamIds));
    $stmtT = $conn->prepare("
      SELECT DISTINCT tm.user_id
      FROM team_members tm
      JOIN users u ON u.id = tm.user_id
      WHERE tm.team_id IN ($in) AND u.is_active = 1 AND u.role IN ('agent','operations_agent','operations_manager','operations_director')
    ");
    if ($stmtT) {
      $stmtT->bind_param($typesT, ...$teamIds);
      $stmtT->execute();
      $rsT = $stmtT->get_result();
      while ($r = $rsT->fetch_assoc()) $agentIds[] = (int)($r['user_id'] ?? 0);
      $stmtT->close();
    }
  }
  $agentIds = array_values(array_filter(array_unique($agentIds), fn($v) => $v > 0));
  if (empty($agentIds)) $agentIds = [$userId];
}

$placeholders = implode(',', array_fill(0, count($agentIds), '?'));
$types = str_repeat('i', count($agentIds));

$stats = [
  'total_leads' => 0,
  'pending_leads' => 0,
  'qualified_leads' => 0,
  'disqualified_leads' => 0,
  'today_leads' => 0,
  'today_qualified' => 0,
  'today_disqualified' => 0,
  'today_pending' => 0,
  'month_leads' => 0,
  'month_qualified' => 0,
  'month_disqualified' => 0,
  'month_pending' => 0,
];
$sql = "
  SELECT
    COUNT(*) AS total_leads,
    SUM(CASE WHEN qa_status = 'Pending' THEN 1 ELSE 0 END) AS pending_leads,
    SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified_leads,
    SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified_leads,
    SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS today_leads,
    SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS today_qualified,
    SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS today_disqualified,
    SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Pending' THEN 1 ELSE 0 END) AS today_pending,
    SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS month_leads,
    SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS month_qualified,
    SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS month_disqualified,
    SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Pending' THEN 1 ELSE 0 END) AS month_pending
  FROM leads
  WHERE agent_id IN ($placeholders)
";
$stmt = $conn->prepare($sql);
$bindTypes = str_repeat('s', 16) . $types;
$bindParams = array_merge([
  $todayStart, $todayEnd,
  $todayStart, $todayEnd,
  $todayStart, $todayEnd,
  $todayStart, $todayEnd,
  $monthStart, $monthEnd,
  $monthStart, $monthEnd,
  $monthStart, $monthEnd,
  $monthStart, $monthEnd,
], $agentIds);
if ($stmt) {
  $stmt->bind_param($bindTypes, ...$bindParams);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
} else {
  $row = null;
}
if ($row) {
  foreach ($stats as $k => $_) {
    $stats[$k] = (int)($row[$k] ?? 0);
  }
}

$recent = [];
$sql = "
  SELECT l.id, l.lead_id, l.company_name, l.email, l.qa_status, l.created_at,
         c.name AS campaign_name,
         u.full_name AS agent_name, u.role AS agent_role
  FROM leads l
  LEFT JOIN campaigns c ON l.campaign_id = c.id
  LEFT JOIN users u ON l.agent_id = u.id
  WHERE l.agent_id IN ($placeholders)
  ORDER BY l.created_at DESC
  LIMIT 10
";
$stmt = $conn->prepare($sql);
if ($stmt) {
  $stmt->bind_param($types, ...$agentIds);
  $stmt->execute();
  $recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();
}

$y = (int)date('Y');
$m = (int)date('m');
$peerUsers = [];
if (!empty($agentIds)) {
  $in = implode(',', array_fill(0, count($agentIds), '?'));
  $typesU = str_repeat('i', count($agentIds));
  $stmtU = $conn->prepare("SELECT id, full_name, role FROM users WHERE id IN ($in) ORDER BY full_name");
  if ($stmtU) {
    $stmtU->bind_param($typesU, ...$agentIds);
    $stmtU->execute();
    $peerUsers = $stmtU->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtU->close();
  }
}

$peerPerf = [];
foreach ($peerUsers as $pu) {
  $aid = (int)($pu['id'] ?? 0);
  if ($aid <= 0) continue;
  $ms = getAgentMonthlyStats($aid, $y, $m) ?: [];
  $inc = is_array($ms['incentives'] ?? null) ? $ms['incentives'] : [];
  $tgt = is_array($ms['target'] ?? null) ? $ms['target'] : [];
  $st = is_array($ms['stats'] ?? null) ? $ms['stats'] : [];
  $dailyTarget = (int)($tgt['daily_target'] ?? 0);
  $overallPct = (float)($st['overall_percent'] ?? 0);
  $peerPerf[] = [
    'id' => $aid,
    'name' => (string)($pu['full_name'] ?? ('Agent #' . $aid)),
    'role' => (string)($pu['role'] ?? ''),
    'daily_total' => (int)($inc['daily_total'] ?? 0),
    'monthly_bonus' => (int)($inc['monthly_bonus'] ?? 0),
    'total_incentive' => (int)($inc['total'] ?? 0),
    'overall_percent' => $overallPct,
    'has_target' => $dailyTarget > 0,
  ];
}

$myMonth = getAgentMonthlyStats($userId, $y, $m) ?: [];
$myInc = is_array($myMonth['incentives'] ?? null) ? $myMonth['incentives'] : [];
$myStats = is_array($myMonth['stats'] ?? null) ? $myMonth['stats'] : [];
$myTarget = is_array($myMonth['target'] ?? null) ? $myMonth['target'] : [];
$myDailyTarget = (int)($myTarget['daily_target'] ?? 0);

$leaderboard = array_values(array_filter($peerPerf, fn($r) => !empty($r['has_target'])));
usort($leaderboard, fn($a,$b) => ($b['overall_percent'] <=> $a['overall_percent']));
$top3 = array_slice($leaderboard, 0, 3);
usort($leaderboard, fn($a,$b) => ($a['overall_percent'] <=> $b['overall_percent']));
$bottom3 = array_slice($leaderboard, 0, 3);

$teamTotals = ['daily_total' => 0, 'monthly_bonus' => 0, 'total' => 0, 'avg_pct' => 0.0, 'count' => 0];
foreach ($leaderboard as $r) {
  $teamTotals['daily_total'] += (int)($r['daily_total'] ?? 0);
  $teamTotals['monthly_bonus'] += (int)($r['monthly_bonus'] ?? 0);
  $teamTotals['total'] += (int)($r['total_incentive'] ?? 0);
  $teamTotals['avg_pct'] += (float)($r['overall_percent'] ?? 0);
  $teamTotals['count'] += 1;
}
if ($teamTotals['count'] > 0) $teamTotals['avg_pct'] = $teamTotals['avg_pct'] / (float)$teamTotals['count'];
?>
<?php $pageTitle = 'Operations Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Operations Dashboard</h3>
      <div class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['job_title'] ?? ''))); ?></div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
              <i class="bi bi-collection fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Total Leads</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($stats['total_leads']); ?></h3>
          <div class="text-muted small mt-1">Qualified: <?php echo number_format($stats['qualified_leads']); ?> · Disq: <?php echo number_format($stats['disqualified_leads']); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
              <i class="bi bi-calendar-day fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Today</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($stats['today_leads']); ?></h3>
          <div class="text-muted small mt-1">Qualified: <?php echo number_format($stats['today_qualified']); ?> · Disq: <?php echo number_format($stats['today_disqualified']); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-secondary-subtle text-secondary rounded-3 p-2 me-3">
              <i class="bi bi-calendar3 fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">This Month</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($stats['month_leads']); ?></h3>
          <div class="text-muted small mt-1">Qualified: <?php echo number_format($stats['month_qualified']); ?> · Disq: <?php echo number_format($stats['month_disqualified']); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-clock-history fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Pending QA</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format($stats['pending_leads']); ?></h3>
          <div class="text-muted small mt-1">Today pending: <?php echo number_format($stats['today_pending']); ?> · Month pending: <?php echo number_format($stats['month_pending']); ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
              <i class="bi bi-check-circle fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Qualified</h6>
          </div>
          <h3 class="card-title mb-0 text-success"><?php echo number_format($stats['qualified_leads']); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-danger-subtle text-danger rounded-3 p-2 me-3">
              <i class="bi bi-x-circle fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Disqualified</h6>
          </div>
          <h3 class="card-title mb-0 text-danger"><?php echo number_format($stats['disqualified_leads']); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100 border-0 shadow-sm">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Quick Action</div>
            <div class="fw-semibold">Submit a new lead</div>
            <div class="text-muted small">Capture delivery lead details for QA review.</div>
          </div>
          <a class="btn btn-primary btn-sm" href="../leads/agent.php"><i class="bi bi-plus-circle me-1"></i>Submit Lead</a>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
      <span>Earnings Overview</span>
      <span class="text-muted small"><?php echo htmlspecialchars(date('M Y')); ?></span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="row g-3">
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Daily Incentives' : 'My Daily Incentives'; ?></div>
                    <div class="h4 mb-0"><?php echo number_format($isTeamView ? (int)$teamTotals['daily_total'] : (int)($myInc['daily_total'] ?? 0)); ?></div>
                    <div class="text-muted small"><?php echo $isTeamView ? ('Avg performance: ' . number_format((float)$teamTotals['avg_pct'], 1) . '%') : ('Performance: ' . number_format((float)($myStats['overall_percent'] ?? 0), 1) . '%'); ?></div>
                  </div>
                  <div class="text-success fs-2"><i class="bi bi-cash-coin"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Monthly Bonus' : 'My Monthly Bonus'; ?></div>
                    <div class="h4 mb-0"><?php echo number_format($isTeamView ? (int)$teamTotals['monthly_bonus'] : (int)($myInc['monthly_bonus'] ?? 0)); ?></div>
                    <div class="text-muted small"><?php echo ($isTeamView || $myDailyTarget > 0) ? 'If monthly target achieved' : 'No target set'; ?></div>
                  </div>
                  <div class="text-primary fs-2"><i class="bi bi-award"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Total Incentives' : 'My Total Incentives'; ?></div>
                    <div class="h4 mb-0"><?php echo number_format($isTeamView ? (int)$teamTotals['total'] : (int)($myInc['total'] ?? 0)); ?></div>
                    <div class="text-muted small">Daily + Monthly</div>
                  </div>
                  <div class="text-warning fs-2"><i class="bi bi-trophy"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Members' : 'My Daily Target'; ?></div>
                    <div class="h4 mb-0"><?php echo $isTeamView ? number_format((int)$teamTotals['count']) : number_format((int)$myDailyTarget); ?></div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'With targets' : 'Productivity target'; ?></div>
                  </div>
                  <div class="text-info fs-2"><i class="bi bi-bullseye"></i></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="row g-3">
            <div class="col-12 col-xl-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Top 3 Performers</div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead class="table-light">
                        <tr><th>Agent</th><th class="text-end">Perf%</th><th class="text-end">Incentive</th></tr>
                      </thead>
                      <tbody>
                        <?php foreach ($top3 as $r): ?>
                          <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                            <td class="text-end"><?php echo number_format((float)($r['overall_percent'] ?? 0), 1); ?>%</td>
                            <td class="text-end"><?php echo number_format((int)($r['total_incentive'] ?? 0)); ?></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($top3)): ?><tr><td colspan="3" class="text-center text-muted py-3">No target data</td></tr><?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="card-footer bg-white">
                  <div class="fst-italic text-muted small">Consistent high performance.</div>
                </div>
              </div>
            </div>
            <div class="col-12 col-xl-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Bottom 3 Performers</div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead class="table-light">
                        <tr><th>Agent</th><th class="text-end">Perf%</th><th class="text-end">Incentive</th></tr>
                      </thead>
                      <tbody>
                        <?php foreach ($bottom3 as $r): ?>
                          <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                            <td class="text-end"><?php echo number_format((float)($r['overall_percent'] ?? 0), 1); ?>%</td>
                            <td class="text-end"><?php echo number_format((int)($r['total_incentive'] ?? 0)); ?></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($bottom3)): ?><tr><td colspan="3" class="text-center text-muted py-3">No target data</td></tr><?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="card-footer bg-white">
                  <div class="fst-italic text-muted small">Below target. Risk of PIP if no improvement.</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Recent Leads</div>
      <div class="text-muted small"><?php echo $isTeamView ? 'Team view' : 'My leads'; ?></div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Lead</th>
            <th>Company</th>
            <th>Status</th>
            <th>Campaign</th>
            <th>Agent</th>
            <th class="text-muted">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No leads found.</td></tr>
          <?php else: ?>
            <?php foreach ($recent as $l): ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars($l['lead_id'] ?? (string)($l['id'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($l['company_name'] ?? ''); ?></td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($l['qa_status'] ?? ''); ?></span></td>
                <td class="text-muted small"><?php echo htmlspecialchars($l['campaign_name'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($l['agent_name'] ?? ''), ($l['agent_role'] ?? ''))); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$l['created_at']))); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
