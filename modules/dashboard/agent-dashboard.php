<?php
/**
 * Agent Dashboard
 * 
 * Provides a focused analytics dashboard for agents with their lead statistics and performance metrics
 */

// Include authentication system
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Ensure user is logged in and has appropriate role
requireRole(['agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);

// Get current user
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Get statistics for this agent
$agentStats = getAgentDashboardStats($userId);

// Get recent leads for this agent
$conn = getDbConnection();
$recentLeadsQuery = $conn->query("
    SELECT l.*, c.name as campaign_name
    FROM leads l
    LEFT JOIN campaigns c ON l.campaign_id = c.id
    WHERE l.agent_id = $userId
    ORDER BY l.created_at DESC
    LIMIT 10
");

$recentLeads = [];
if ($recentLeadsQuery) {
    while ($lead = $recentLeadsQuery->fetch_assoc()) {
        $recentLeads[] = enrichLeadRow($lead);
    }
}

// Earnings calculation
try {
    $now = new DateTime();
    $curYear = (int)$now->format('Y');
    $curMonth = (int)$now->format('m');
    $prev = (clone $now)->modify('first day of last month');
    $prevYear = (int)$prev->format('Y');
    $prevMonth = (int)$prev->format('m');

    $curStats = getAgentMonthlyStats($userId, $curYear, $curMonth);
    $prevStats = getAgentMonthlyStats($userId, $prevYear, $prevMonth);

    $curDaily = $curStats['incentives']['daily_total'] ?? 0;
    $curMonthly = $curStats['incentives']['monthly_bonus'] ?? 0;
    $curTotal = $curStats['incentives']['total'] ?? ($curDaily + $curMonthly);
    $prevTotal = $prevStats['incentives']['total'] ?? (($prevStats['incentives']['daily_total'] ?? 0) + ($prevStats['incentives']['monthly_bonus'] ?? 0));
} catch (Throwable $e) {
    $curDaily = $curMonthly = $curTotal = $prevTotal = 0;
}

?>
<?php $pageTitle = 'Agent Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

    <div class="container-fluid px-0">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0">Agent Dashboard</h2>
            <div class="text-muted">Welcome back, <?php echo htmlspecialchars(formatUserNameWithRole(($currentUser['full_name'] ?? 'User'), ($currentUser['role'] ?? ''))); ?></div>
        </div>
        
        <!-- Stats Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
                                <i class="bi bi-people fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Total Leads</h6>
                        </div>
                        <h3 class="card-title mb-0"><?php echo number_format($agentStats['total_leads']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
                                <i class="bi bi-check-circle fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Qualified</h6>
                        </div>
                        <h3 class="card-title mb-0 text-success"><?php echo number_format($agentStats['qualified_leads']); ?></h3>
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
                        <h3 class="card-title mb-0 text-danger"><?php echo number_format($agentStats['disqualified_leads']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
                                <i class="bi bi-clock fs-4"></i>
                            </div>
                            <h6 class="card-subtitle text-muted mb-0">Pending QA</h6>
                        </div>
                        <h3 class="card-title mb-0 text-warning"><?php echo number_format($agentStats['pending_leads']); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Today's Performance -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Today's Performance</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <h4 class="mb-1"><?php echo number_format($agentStats['today_leads']); ?></h4>
                                <small class="text-muted">Total</small>
                            </div>
                            <div class="col-4">
                                <h4 class="mb-1 text-success"><?php echo number_format($agentStats['today_qualified']); ?></h4>
                                <small class="text-muted">Qualified</small>
                            </div>
                            <div class="col-4">
                                <h4 class="mb-1 text-danger"><?php echo number_format($agentStats['today_disqualified']); ?></h4>
                                <small class="text-muted">Disqualified</small>
                            </div>
                        </div>
                        <hr class="my-4">
                        <div class="d-grid">
                            <a href="../leads/agent.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>Generate New Lead
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Earnings Overview -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Earnings Overview</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Current Month Total</div>
                                    <div class="h4 mb-0 text-primary">Rs. <?php echo number_format($curTotal); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Previous Month</div>
                                    <div class="h4 mb-0">Rs. <?php echo number_format($prevTotal); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Today's Earnings</div>
                                    <div class="h5 mb-0">Rs. <?php echo number_format($curDaily); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3">
                                    <div class="text-muted small mb-1">Monthly Bonus</div>
                                    <div class="h5 mb-0 text-success">Rs. <?php echo number_format($curMonthly); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Leads -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Leads</h5>
                <a href="../leads/my-leads.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Date</th>
                                <th>Name</th>
                                <th>Campaign</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLeads)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No leads found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentLeads as $lead): ?>
                                    <tr>
                                        <td class="ps-4 small text-muted">
                                            <?php echo date('M d, H:i', strtotime($lead['created_at'])); ?>
                                        </td>
                                        <td class="fw-semibold">
                                            <?php echo htmlspecialchars($lead['first_name'] . ' ' . $lead['last_name']); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-secondary border">
                                                <?php echo htmlspecialchars($lead['campaign_name'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusClass = 'bg-warning-subtle text-warning';
                                            if ($lead['qa_status'] === 'Qualified') $statusClass = 'bg-success-subtle text-success';
                                            if ($lead['qa_status'] === 'Disqualified') $statusClass = 'bg-danger-subtle text-danger';
                                            ?>
                                            <span class="badge <?php echo $statusClass; ?> border">
                                                <?php echo htmlspecialchars($lead['qa_status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="../leads/lead-details.php?id=<?php echo $lead['id']; ?>" class="btn btn-sm btn-light border">
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
    </div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
