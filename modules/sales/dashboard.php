<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr','admin','director','manager_director']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts($userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$canTasks = function_exists('userHasPermission')
  ? (userHasPermission('tasks.access') || userHasPermission('tasks.create') || userHasPermission('tasks.assign') || userHasPermission('tasks.manage') || userHasPermission('tasks.override') || userHasPermission('tasks.reports'))
  : true;
$isSdr = isSDR();
$isManager = isSalesManager();
$isDirector = isSalesDirector();

$statuses = ['New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];

$where = [];
$params = [];
$types = '';

if ($isSdr) {
    $where[] = "sl.owner_id = ?";
    $params[] = $userId;
    $types .= 'i';
} elseif ($isManager && !$isDirector) {
    $where[] = "(sl.owner_id = ? OR sl.owner_id IN (SELECT sdr_user_id FROM sales_manager_sdr_map WHERE manager_user_id = ?))";
    $params[] = $userId;
    $params[] = $userId;
    $types .= 'ii';
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "SELECT sl.status, COUNT(*) AS cnt FROM sales_leads sl $whereSql GROUP BY sl.status";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$countsByStatus = array_fill_keys($statuses, 0);
$totalProspects = 0;
foreach ($rows as $r) {
    $st = (string)($r['status'] ?? '');
    $cnt = (int)($r['cnt'] ?? 0);
    $totalProspects += $cnt;
    if (isset($countsByStatus[$st])) $countsByStatus[$st] = $cnt;
}

$recentSql = "SELECT a.id, a.sales_lead_id, a.status, a.comment, a.created_at, u.full_name AS created_by_name, u.role AS created_by_role,
    sl.company_name
    FROM sales_lead_activities a
    LEFT JOIN users u ON u.id = a.created_by
    LEFT JOIN sales_leads sl ON sl.id = a.sales_lead_id
    $whereSql
    ORDER BY a.created_at DESC
    LIMIT 12";
$stmt = $conn->prepare($recentSql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$teamSql = "SELECT
    sl.owner_id,
    u.full_name,
    COUNT(DISTINCT sl.id) AS prospects,
    COUNT(a.id) AS activities,
    SUM(sl.status = 'Closed Won') AS won,
    SUM(sl.status = 'Closed Lost') AS lost
    FROM sales_leads sl
    LEFT JOIN users u ON u.id = sl.owner_id
    LEFT JOIN sales_lead_activities a ON a.sales_lead_id = sl.id
    $whereSql
    GROUP BY sl.owner_id
    ORDER BY prospects DESC
    LIMIT 10";
$stmt = $conn->prepare($teamSql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$team = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$opportunities = (int)($countsByStatus['Proposal Sent'] ?? 0) + (int)($countsByStatus['Negotiation'] ?? 0);
$conversionRate = $totalProspects > 0 ? round(((int)($countsByStatus['Closed Won'] ?? 0) / $totalProspects) * 100, 1) : 0.0;

$now = new DateTime();
$viewYear = (int)$now->format('Y');
$viewMonth = (int)$now->format('m');
$targetRow = getSalesMonthlyTarget($userId, $viewYear, $viewMonth) ?: ['target_new_accounts' => 0, 'target_revenue_usd' => 0];
$targetNewAccounts = (int)($targetRow['target_new_accounts'] ?? 0);
$targetRevenueUsd = (float)($targetRow['target_revenue_usd'] ?? 0);
$achievedAccounts = (int)(getSalesNewAccountsProgress($user, $viewYear, $viewMonth)['achieved'] ?? 0);
$rev = getSalesRevenueProgress($user);
$usdInr = getUsdInrRate($now->format('Y-m-d'));

$overdueCount = 0;
$overdueLeads = [];
$overdueWhereSql = $whereSql ? ($whereSql . " AND sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at < NOW() AND sl.status NOT IN ('Closed Won','Closed Lost')") : "WHERE sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at < NOW() AND sl.status NOT IN ('Closed Won','Closed Lost')";
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM sales_leads sl $overdueWhereSql");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$overdueCount = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT sl.id, sl.company_name, sl.status, sl.priority, sl.next_follow_up_at, u.full_name AS owner_name, u.role AS owner_role
    FROM sales_leads sl
    LEFT JOIN users u ON u.id = sl.owner_id
    $overdueWhereSql
    ORDER BY sl.next_follow_up_at ASC
    LIMIT 10");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$overdueLeads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$search = trim((string)($_GET['q'] ?? ''));
$searchResults = [];
if ($search !== '') {
    $sWhere = $where ? $where : [];
    $sParams = $params;
    $sTypes = $types;
    $like = '%'.$search.'%';
    $sWhere[] = "(sl.company_name LIKE ? OR sl.website LIKE ? OR sl.contact_email LIKE ? OR sl.contact_name LIKE ? OR sl.linkedin_url LIKE ?)";
    array_push($sParams, $like, $like, $like, $like, $like);
    $sTypes .= 'sssss';
    $sWhereSql = 'WHERE '.implode(' AND ', $sWhere);
    $stmt = $conn->prepare("SELECT sl.id, sl.company_name, sl.status, sl.priority, sl.country, sl.industry, sl.updated_at
        FROM sales_leads sl $sWhereSql ORDER BY sl.updated_at DESC, sl.created_at DESC LIMIT 15");
    if ($sTypes) $stmt->bind_param($sTypes, ...$sParams);
    $stmt->execute();
    $searchResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}
?>

<?php $pageTitle = 'Sales Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Sales Dashboard</h3>
      <div class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['role'] ?? ''))); ?></div>
    </div>
    <div class="d-flex gap-2">
      <a href="lead-create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Prospect</a>
      <a href="leads.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1"></i>Pipeline</a>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2" method="get">
        <div class="col-lg-10">
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search prospect: company, website, contact, email, LinkedIn">
        </div>
        <div class="col-lg-2 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
        </div>
      </form>

      <?php if ($search !== ''): ?>
        <div class="mt-3 table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Company</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Country</th>
                <th>Industry</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($searchResults)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No matching prospects found.</td></tr>
              <?php else: ?>
                <?php foreach ($searchResults as $r): ?>
                  <tr>
                    <td class="fw-semibold">
                      <a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['company_name'] ?? ''); ?></a>
                    </td>
                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['status'] ?? ''); ?></span></td>
                    <td><?php echo htmlspecialchars($r['priority'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['country'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['industry'] ?? ''); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4 col-lg-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Total Prospects</div>
          <div class="fs-3 fw-semibold"><?php echo (int)$totalProspects; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Opportunities</div>
          <div class="fs-3 fw-semibold"><?php echo (int)$opportunities; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Conversion Rate</div>
          <div class="fs-3 fw-semibold"><?php echo htmlspecialchars((string)$conversionRate); ?>%</div>
        </div>
      </div>
    </div>
    <?php if ($canTasks): ?>
      <div class="col-md-4 col-lg-3">
        <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>">
          <div class="card h-100 border-primary-subtle">
            <div class="card-body">
              <div class="text-muted small">My Tasks</div>
              <div class="fs-5 fw-semibold">
                <span class="me-2">Pending: <?php echo (int)($taskWidget['pending'] ?? 0); ?></span>
                <span class="me-2">Due: <?php echo (int)($taskWidget['due_today'] ?? 0); ?></span>
                <span class="text-danger">Overdue: <?php echo (int)($taskWidget['overdue'] ?? 0); ?></span>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>
    <div class="col-md-4 col-lg-3">
      <a class="text-decoration-none" href="leads.php?<?php echo htmlspecialchars(http_build_query(['due' => 'Overdue'])); ?>">
        <div class="card h-100 border-danger-subtle">
          <div class="card-body">
            <div class="text-muted small">Overdue Follow-ups</div>
            <div class="fs-3 fw-semibold text-danger"><?php echo (int)$overdueCount; ?></div>
          </div>
        </div>
      </a>
    </div>
    <?php foreach (['New','Contacted','Meeting Scheduled','Proposal Sent','Closed Won','Closed Lost'] as $st): ?>
      <div class="col-md-4 col-lg-3">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-muted small"><?php echo htmlspecialchars($st); ?></div>
            <div class="fs-3 fw-semibold"><?php echo (int)($countsByStatus[$st] ?? 0); ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">My Targets (<?php echo htmlspecialchars(date('F Y', mktime(0,0,0,$viewMonth,1,$viewYear))); ?>)</div>
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">USD→INR: <?php echo $usdInr !== null ? htmlspecialchars(number_format((float)$usdInr, 2)) : '—'; ?></span>
            <?php if (isAdmin() || isSalesDirector()): ?>
              <a class="btn btn-outline-primary btn-sm" href="targets.php"><i class="bi bi-bullseye me-1"></i>Set Targets</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-lg-4">
              <div class="border rounded p-3 h-100">
                <div class="text-muted small">New Accounts</div>
                <div class="d-flex align-items-end justify-content-between">
                  <div class="fs-4 fw-semibold"><?php echo (int)$achievedAccounts; ?><span class="text-muted fs-6 fw-normal"> / <?php echo (int)$targetNewAccounts; ?></span></div>
                  <div class="text-muted small">Achieved / Target</div>
                </div>
                <?php
                  $pctA = $targetNewAccounts > 0 ? min(100, round(($achievedAccounts / $targetNewAccounts) * 100)) : 0;
                ?>
                <div class="progress mt-2" style="height: 10px;">
                  <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (int)$pctA; ?>%"></div>
                </div>
                <div class="text-muted small mt-2">
                  Pipeline this month: Won <?php echo (int)($countsByStatus['Closed Won'] ?? 0); ?> · Proposal <?php echo (int)($countsByStatus['Proposal Sent'] ?? 0); ?> · Meeting <?php echo (int)($countsByStatus['Meeting Scheduled'] ?? 0); ?>
                </div>
              </div>
            </div>
            <div class="col-lg-8">
              <div class="border rounded p-3 h-100">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small">Revenue (Client Campaign Budgets)</div>
                    <div class="fs-4 fw-semibold">
                      $<?php echo htmlspecialchars(number_format((float)($rev['generated_usd'] ?? 0), 2)); ?>
                      <span class="text-muted fs-6 fw-normal"> / $<?php echo htmlspecialchars(number_format((float)$targetRevenueUsd, 2)); ?></span>
                    </div>
                  </div>
                  <div class="text-muted small text-end">
                    Clients: <?php echo (int)($rev['clients'] ?? 0); ?><br>
                    Campaigns: <?php echo (int)($rev['campaigns'] ?? 0); ?>
                  </div>
                </div>
                <?php
                  $pctR = $targetRevenueUsd > 0 ? min(100, round((((float)($rev['generated_usd'] ?? 0)) / $targetRevenueUsd) * 100)) : 0;
                ?>
                <div class="progress mt-2" style="height: 10px;">
                  <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (int)$pctR; ?>%"></div>
                </div>
                <div class="row g-2 mt-3">
                  <div class="col-md-4">
                    <div class="text-muted small">Allocated</div>
                    <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($rev['allocated_usd'] ?? 0), 2)); ?></div>
                    <div class="text-muted small"><?php echo $usdInr !== null ? ('₹' . htmlspecialchars(number_format(((float)($rev['allocated_usd'] ?? 0)) * (float)$usdInr, 2))) : '—'; ?></div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small">Generated</div>
                    <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($rev['generated_usd'] ?? 0), 2)); ?></div>
                    <div class="text-muted small"><?php echo $usdInr !== null ? ('₹' . htmlspecialchars(number_format(((float)($rev['generated_usd'] ?? 0)) * (float)$usdInr, 2))) : '—'; ?></div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small">Pending</div>
                    <div class="fw-semibold">$<?php echo htmlspecialchars(number_format((float)($rev['pending_usd'] ?? 0), 2)); ?></div>
                    <div class="text-muted small"><?php echo $usdInr !== null ? ('₹' . htmlspecialchars(number_format(((float)($rev['pending_usd'] ?? 0)) * (float)$usdInr, 2))) : '—'; ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Recent Activity</div>
          <a class="small text-decoration-none" href="leads.php">View pipeline</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>When</th>
                <th>Prospect</th>
                <th>Status</th>
                <th>Comment</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No activity yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recent as $a): ?>
                  <tr>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($a['created_at']))); ?></td>
                    <td class="fw-semibold">
                      <a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$a['sales_lead_id']; ?>"><?php echo htmlspecialchars($a['company_name'] ?? ''); ?></a>
                    </td>
                    <td><?php echo $a['status'] ? '<span class="badge bg-secondary">'.htmlspecialchars($a['status']).'</span>' : '<span class="text-muted">—</span>'; ?></td>
                    <td class="small"><?php echo htmlspecialchars(mb_strimwidth((string)($a['comment'] ?? ''), 0, 80, '…')); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($a['created_by_name'] ?? ''), ($a['created_by_role'] ?? ''))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Overdue Follow-ups</div>
          <a class="small text-decoration-none" href="leads.php?<?php echo htmlspecialchars(http_build_query(['due' => 'Overdue'])); ?>">Open list</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Prospect</th>
                <th>Due</th>
                <th>Owner</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($overdueLeads)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No overdue follow-ups.</td></tr>
              <?php else: ?>
                <?php foreach ($overdueLeads as $o): ?>
                  <tr>
                    <td class="fw-semibold">
                      <a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars($o['company_name'] ?? ''); ?></a>
                      <div class="text-muted small"><?php echo htmlspecialchars($o['status'] ?? ''); ?> · <?php echo htmlspecialchars($o['priority'] ?? ''); ?></div>
                    </td>
                    <td class="text-danger small"><?php echo !empty($o['next_follow_up_at']) ? htmlspecialchars(date('d M, H:i', strtotime((string)$o['next_follow_up_at']))) : '—'; ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($o['owner_name'] ?? ''), ($o['owner_role'] ?? ''))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-header fw-semibold">Team Metrics</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Owner</th>
                <th class="text-end">Prospects</th>
                <th class="text-end">Activities</th>
                <th class="text-end">Won</th>
                <th class="text-end">Lost</th>
                <th class="text-end">Conv.</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($team)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No data yet.</td></tr>
              <?php else: ?>
                <?php foreach ($team as $t): ?>
                  <?php
                    $p = (int)($t['prospects'] ?? 0);
                    $w = (int)($t['won'] ?? 0);
                    $conv = $p > 0 ? round(($w / $p) * 100, 1) : 0.0;
                  ?>
                  <tr>
                    <td><?php echo htmlspecialchars($t['full_name'] ?? ''); ?></td>
                    <td class="text-end fw-semibold"><?php echo $p; ?></td>
                    <td class="text-end"><?php echo (int)($t['activities'] ?? 0); ?></td>
                    <td class="text-end"><?php echo (int)($t['won'] ?? 0); ?></td>
                    <td class="text-end"><?php echo (int)($t['lost'] ?? 0); ?></td>
                    <td class="text-end"><?php echo htmlspecialchars((string)$conv); ?>%</td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header fw-semibold">Pipeline View</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Status</th>
                <th class="text-end">Count</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($countsByStatus as $st => $cnt): ?>
                <tr>
                  <td><?php echo htmlspecialchars($st); ?></td>
                  <td class="text-end fw-semibold"><?php echo (int)$cnt; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
