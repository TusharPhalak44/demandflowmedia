<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

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

$visible = getOpsVisibleCampaignIdsForUser($userId, $role);
$campaignIds = $visible === null ? null : array_keys($visible);
$stats = getFormFillerDashboardStats($todayStart, $todayEnd, $monthStart, $monthEnd, $campaignIds);

$conn = getDbConnection();
$recentPending = [];
$emailQueue = ['pending' => 0, 'sent' => 0, 'delivered' => 0, 'bounced' => 0];
if ($campaignIds === null || !empty($campaignIds)) {
  $where = ["l.qa_status = 'Qualified'"];
  $params = [];
  $types = '';
  if (is_array($campaignIds)) {
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $where[] = "l.campaign_id IN ($in)";
    $params = array_merge($params, $campaignIds);
    $types .= str_repeat('i', count($campaignIds));
  }
  $whereSql = implode(' AND ', $where);

  $sqlQ = "SELECT
      SUM(CASE WHEN l.email_status IS NULL OR l.email_status = '' OR l.email_status = 'Pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN l.email_status = 'Sent' THEN 1 ELSE 0 END) AS sent,
      SUM(CASE WHEN l.email_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
      SUM(CASE WHEN l.email_status = 'Bounced' THEN 1 ELSE 0 END) AS bounced
    FROM leads l
    WHERE $whereSql";
  $stmtQ = $conn->prepare($sqlQ);
  if ($stmtQ) {
    if ($types) $stmtQ->bind_param($types, ...$params);
    $stmtQ->execute();
    $emailQueue = $stmtQ->get_result()->fetch_assoc() ?: $emailQueue;
    $stmtQ->close();
    foreach ($emailQueue as $k => $v) $emailQueue[$k] = (int)$v;
  }

  $where2 = ["l.form_done = 'No'", "l.qa_status = 'Qualified'"];
  $params2 = [];
  $types2 = '';
  if (is_array($campaignIds)) {
    $in2 = implode(',', array_fill(0, count($campaignIds), '?'));
    $where2[] = "l.campaign_id IN ($in2)";
    $params2 = array_merge($params2, $campaignIds);
    $types2 .= str_repeat('i', count($campaignIds));
  }
  $whereSql2 = implode(' AND ', $where2);
  $sqlR = "SELECT l.*, c.name AS campaign_name, u.full_name AS agent_name, u.role AS agent_role
      FROM leads l
      LEFT JOIN campaigns c ON l.campaign_id = c.id
      LEFT JOIN users u ON l.agent_id = u.id
      WHERE $whereSql2
      ORDER BY l.created_at DESC
      LIMIT 10";
  $stmtR = $conn->prepare($sqlR);
  if ($stmtR) {
    if ($types2) $stmtR->bind_param($types2, ...$params2);
    $stmtR->execute();
    $rs = $stmtR->get_result();
    while ($lead = $rs->fetch_assoc()) {
      $recentPending[] = enrichLeadRow($lead);
    }
    $stmtR->close();
  }
}
?>
<?php $pageTitle = 'Email Marketing Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Email Marketing Dashboard</h3>
      <div class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['role'] ?? ''))); ?></div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-hourglass-split fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Pending Forms</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format((int)($stats['pending_form_filling'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
              <i class="bi bi-check-all fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Forms Filled</h6>
          </div>
          <h3 class="card-title mb-0 text-success"><?php echo number_format((int)($stats['filled_forms'] ?? 0)); ?></h3>
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
            <h6 class="card-subtitle text-muted mb-0">Filled Today</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['today_filled'] ?? 0)); ?></h3>
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
            <h6 class="card-subtitle text-muted mb-0">Filled This Month</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['month_filled'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
              <i class="bi bi-file-earmark-text fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Total Leads</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format((int)($stats['total_leads'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
              <i class="bi bi-shield-check fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Qualified Leads</h6>
          </div>
          <h3 class="card-title mb-0 text-success"><?php echo number_format((int)($stats['qualified_leads'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-clock-history fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">QA Pending</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format((int)($stats['qa_pending'] ?? 0)); ?></h3>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Email Queue</div>
      <a class="small text-decoration-none" href="../leads/email">Open Email Leads</a>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="text-muted small">Pending</div>
          <div class="h4 mb-0 text-warning"><?php echo number_format((int)($emailQueue['pending'] ?? 0)); ?></div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Sent</div>
          <div class="h4 mb-0"><?php echo number_format((int)($emailQueue['sent'] ?? 0)); ?></div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Delivered</div>
          <div class="h4 mb-0 text-success"><?php echo number_format((int)($emailQueue['delivered'] ?? 0)); ?></div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Bounced</div>
          <div class="h4 mb-0 text-danger"><?php echo number_format((int)($emailQueue['bounced'] ?? 0)); ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Assigned Leads – Fill Remaining Fields</div>
      <a class="small text-decoration-none" href="../leads/email">Open Email Leads</a>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Lead</th>
            <th>Company</th>
            <th>Campaign</th>
            <th>Agent</th>
            <th class="text-muted">Created</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentPending)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No pending items found.</td></tr>
          <?php else: ?>
            <?php foreach ($recentPending as $l): ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars($l['lead_id'] ?? (string)($l['id'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($l['company_name'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($l['campaign_name'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($l['agent_name'] ?? ''), ($l['agent_role'] ?? ''))); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$l['created_at']))); ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-light border" href="../leads/get_lead_details?id=<?php echo (int)($l['id'] ?? 0); ?>&edit=1&post_to=email-leads" target="_blank">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/email">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">Email Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-envelope-at"></i></div>
            </div>
            <div class="text-muted small mt-2">Update email status and fill missing lead fields.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../leads/my">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">My Leads</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-list-check"></i></div>
            </div>
            <div class="text-muted small mt-2">Your recent leads and form progress.</div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none" href="../chat/chat.php">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Module</div>
                <div class="fw-semibold">Chat</div>
              </div>
              <div class="text-primary fs-3"><i class="bi bi-chat-dots"></i></div>
            </div>
            <div class="text-muted small mt-2">Coordinate with Operations and QA.</div>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
