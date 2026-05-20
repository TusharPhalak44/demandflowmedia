<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];
$start = sprintf('%04d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));
$startDt = $start . ' 00:00:00';
$endDt = $end . ' 23:59:59';

$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$usdInr = getUsdInrRate($end) ?? getUsdInrRate(date('Y-m-d'));

$payrollByUser = [];
$stmt = $conn->prepare("SELECT p.user_id, u.full_name, p.salary_data FROM hr_payslips p JOIN users u ON u.id = p.user_id WHERE p.year = ? AND p.month = ?");
if ($stmt) {
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $data = json_decode((string)($r['salary_data'] ?? ''), true);
        if (!is_array($data)) $data = [];
        $earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
        $net = (float)($data['net_salary'] ?? 0);
        $incent = (float)($earn['incentives'] ?? 0);
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid > 0) {
            $payrollByUser[$uid] = [
                'user_id' => $uid,
                'full_name' => (string)($r['full_name'] ?? ''),
                'net' => $net,
                'incentives' => $incent,
                'total' => $net + $incent,
            ];
        }
    }
}

$agentList = [];
$stmt = $conn->prepare("
    SELECT l.agent_id, COALESCE(l.agent_name, '') AS agent_name,
           COALESCE(d.cpl_currency, 'USD') AS currency,
           COUNT(l.id) AS delivered,
           SUM(COALESCE(d.cpl, 0)) AS revenue
    FROM leads l
    LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
    WHERE l.client_delivery_status = 'Delivered' AND l.created_at BETWEEN ? AND ?
    GROUP BY l.agent_id, agent_name, currency
    ORDER BY revenue DESC
");
if ($stmt) {
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $agentList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$agg = [];
foreach ($agentList as $r) {
    $aid = (int)($r['agent_id'] ?? 0);
    if ($aid <= 0) continue;
    if ($agentId > 0 && $aid !== $agentId) continue;
    if (!isset($agg[$aid])) {
        $agg[$aid] = [
            'agent_id' => $aid,
            'agent_name' => (string)($r['agent_name'] ?? ''),
            'delivered' => 0,
            'usd' => 0.0,
            'inr' => 0.0,
            'inr_from_usd' => 0.0,
            'revenue_inr' => 0.0,
            'cost_inr' => (float)($payrollByUser[$aid]['total'] ?? 0),
            'net_inr' => (float)($payrollByUser[$aid]['net'] ?? 0),
            'incentives_inr' => (float)($payrollByUser[$aid]['incentives'] ?? 0),
        ];
    }
    $agg[$aid]['delivered'] += (int)($r['delivered'] ?? 0);
    $cur = strtoupper(trim((string)($r['currency'] ?? 'USD')));
    if ($cur === '') $cur = 'USD';
    $rev = (float)($r['revenue'] ?? 0);
    if ($cur === 'USD') {
        $agg[$aid]['usd'] += $rev;
        if ($usdInr !== null) $agg[$aid]['inr_from_usd'] += ($rev * (float)$usdInr);
    } elseif ($cur === 'INR') {
        $agg[$aid]['inr'] += $rev;
    }
}
foreach ($agg as $aid => $r) {
    $agg[$aid]['revenue_inr'] = (float)$r['inr'] + (float)$r['inr_from_usd'];
}
$list = array_values($agg);
usort($list, function($a, $b) {
    $av = (float)($a['revenue_inr'] ?? 0);
    $bv = (float)($b['revenue_inr'] ?? 0);
    if ($av === $bv) return 0;
    return $av > $bv ? -1 : 1;
});

$pageTitle = 'Agent ROI';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Agent ROI</div>
            <div class="text-muted small">Month: <?php echo htmlspecialchars($monthStr); ?> · FX USD→INR: <?php echo $usdInr !== null ? number_format((float)$usdInr, 2) : '—'; ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Month</label>
                    <input class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($monthStr); ?>" placeholder="YYYY-MM">
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1">Agent</label>
                    <select class="form-select form-select-sm" name="agent_id">
                        <option value="">All</option>
                        <?php foreach ($list as $r): ?>
                            <option value="<?php echo (int)$r['agent_id']; ?>" <?php echo $agentId === (int)$r['agent_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($r['agent_name'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">ROI Details</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Agent</th>
                        <th class="text-end">Delivered</th>
                        <th class="text-end">Revenue (INR)</th>
                        <th class="text-end">Net (INR)</th>
                        <th class="text-end">Incentives (INR)</th>
                        <th class="text-end">Cost (INR)</th>
                        <th class="text-end">ROI</th>
                        <th class="text-end">Links</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No delivered leads in this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $r): ?>
                            <?php
                                $revInr = (float)($r['revenue_inr'] ?? 0);
                                $costInr = (float)($r['cost_inr'] ?? 0);
                                $roiX = $costInr > 0 ? ($revInr / $costInr) : null;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['agent_name'] ?? '')); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                <td class="text-end fw-semibold">₹ <?php echo number_format($revInr, 2); ?></td>
                                <td class="text-end">₹ <?php echo number_format((float)($r['net_inr'] ?? 0), 2); ?></td>
                                <td class="text-end">₹ <?php echo number_format((float)($r['incentives_inr'] ?? 0), 2); ?></td>
                                <td class="text-end">₹ <?php echo number_format($costInr, 2); ?></td>
                                <td class="text-end"><?php echo $roiX !== null ? number_format((float)$roiX, 2) . 'x' : '<span class="text-muted">—</span>'; ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-light border" href="../hr/payslip-view.php?month=<?php echo urlencode($monthStr); ?>&user_id=<?php echo (int)$r['agent_id']; ?>" title="Payslip"><i class="bi bi-receipt"></i></a>
                                    <a class="btn btn-sm btn-light border" href="../productivity/productivity-admin.php" title="Productivity"><i class="bi bi-bar-chart"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

