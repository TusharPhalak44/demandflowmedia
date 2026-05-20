<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

requireRole(['admin','director','manager_director']);
ensureDatabaseSchema();
ensureCsrfToken();

$conn = getDbConnection();
$now = new DateTime();

$preset = isset($_GET['range_preset']) ? (string)$_GET['range_preset'] : 'current_month';
$startInput = isset($_GET['start_date']) ? (string)$_GET['start_date'] : '';
$endInput = isset($_GET['end_date']) ? (string)$_GET['end_date'] : '';
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;

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

$campaigns = getAllCampaignsBasic();

$rows = [];
$stmt = $conn->prepare("
    SELECT
        c.id,
        c.name,
        COALESCE(d.status,'') AS campaign_status,
        COALESCE(d.client_id,0) AS client_id,
        COALESCE(cl.client_code,'') AS client_code,
        COALESCE(cl.name,'') AS client_name,
        COALESCE(d.cpl,0) AS set_cpl,
        COALESCE(d.cpl_currency,'USD') AS cpl_currency,
        COUNT(l.id) AS generated,
        SUM(CASE WHEN (l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS pending_qa,
        SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN l.form_done = 'Yes' THEN 1 ELSE 0 END) AS forms_submitted,
        SUM(CASE WHEN l.qa_status = 'Qualified' AND l.form_done = 'No' THEN 1 ELSE 0 END) AS forms_pending
    FROM campaigns c
    LEFT JOIN campaign_details d ON d.campaign_id = c.id
    LEFT JOIN clients cl ON cl.id = d.client_id
    LEFT JOIN leads l ON l.campaign_id = c.id AND l.created_at BETWEEN ? AND ?
    WHERE c.active = 1
    GROUP BY c.id
    ORDER BY generated DESC, c.name ASC
");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$revByCamp = [];
$stmt = $conn->prepare("SELECT campaign_id, COALESCE(SUM(total),0) AS rev, COALESCE(SUM(CASE WHEN status='Paid' THEN total ELSE 0 END),0) AS paid FROM revenue_invoices WHERE issue_date BETWEEN ? AND ? GROUP BY campaign_id");
if ($stmt) {
    $sd = substr($rangeStart, 0, 10);
    $ed = substr($rangeEnd, 0, 10);
    $stmt->bind_param('ss', $sd, $ed);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) {
        $cid = (int)($r['campaign_id'] ?? 0);
        $revByCamp[$cid] = ['rev' => (float)($r['rev'] ?? 0), 'paid' => (float)($r['paid'] ?? 0)];
    }
}

$expByCamp = [];
$stmt = $conn->prepare("SELECT campaign_id, COALESCE(SUM(amount),0) AS exp FROM revenue_manual_expenses WHERE expense_date BETWEEN ? AND ? GROUP BY campaign_id");
if ($stmt) {
    $sd = substr($rangeStart, 0, 10);
    $ed = substr($rangeEnd, 0, 10);
    $stmt->bind_param('ss', $sd, $ed);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($tmp as $r) $expByCamp[(int)($r['campaign_id'] ?? 0)] = (float)($r['exp'] ?? 0);
}

$summary = ['generated' => 0, 'pending_qa' => 0, 'qualified' => 0, 'disqualified' => 0, 'delivered' => 0, 'forms_submitted' => 0, 'forms_pending' => 0, 'revenue' => 0.0, 'expense' => 0.0];
foreach ($rows as &$r) {
    $cid = (int)($r['id'] ?? 0);
    $rev = (float)(($revByCamp[$cid]['rev'] ?? 0) ?: 0);
    $paid = (float)(($revByCamp[$cid]['paid'] ?? 0) ?: 0);
    $exp = (float)($expByCamp[$cid] ?? 0);
    $del = (int)($r['delivered'] ?? 0);
    $roi = $exp > 0 ? (($rev - $exp) / $exp) : ($rev > 0 ? 1.0 : 0.0);
    $effCpl = $del > 0 ? ($rev / $del) : 0.0;
    $r['revenue'] = $rev;
    $r['paid'] = $paid;
    $r['expense'] = $exp;
    $r['roi'] = $roi;
    $r['effective_cpl'] = $effCpl;
    $r['delivery_pending'] = max(0, (int)($r['generated'] ?? 0) - (int)($r['delivered'] ?? 0));
    $r['delivery_pct'] = ((int)($r['generated'] ?? 0) > 0) ? round(((int)($r['delivered'] ?? 0) / max(1, (int)($r['generated'] ?? 0))) * 100, 1) : 0.0;

    $summary['generated'] += (int)($r['generated'] ?? 0);
    $summary['pending_qa'] += (int)($r['pending_qa'] ?? 0);
    $summary['qualified'] += (int)($r['qualified'] ?? 0);
    $summary['disqualified'] += (int)($r['disqualified'] ?? 0);
    $summary['delivered'] += (int)($r['delivered'] ?? 0);
    $summary['forms_submitted'] += (int)($r['forms_submitted'] ?? 0);
    $summary['forms_pending'] += (int)($r['forms_pending'] ?? 0);
    $summary['revenue'] += $rev;
    $summary['expense'] += $exp;
}
unset($r);

$clientRows = [];
$stmt = $conn->prepare("
    SELECT
        cl.id,
        COALESCE(cl.client_code,'') AS client_code,
        COALESCE(cl.name,'') AS client_name,
        COUNT(DISTINCT c.id) AS campaigns,
        COUNT(l.id) AS generated,
        SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered
    FROM clients cl
    JOIN campaign_details d ON d.client_id = cl.id
    JOIN campaigns c ON c.id = d.campaign_id AND c.active = 1
    LEFT JOIN leads l ON l.campaign_id = c.id AND l.created_at BETWEEN ? AND ?
    GROUP BY cl.id
    ORDER BY delivered DESC, generated DESC, client_name ASC
");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $clientRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($clientRows as &$cr) {
        $cr['delivery_pending'] = max(0, (int)($cr['generated'] ?? 0) - (int)($cr['delivered'] ?? 0));
        $cr['delivery_pct'] = ((int)($cr['generated'] ?? 0) > 0) ? round(((int)($cr['delivered'] ?? 0) / max(1, (int)($cr['generated'] ?? 0))) * 100, 1) : 0.0;
    }
    unset($cr);
}

$trendRows = [];
if ($campaignId > 0) {
    $stmt = $conn->prepare("
        SELECT DATE(l.created_at) AS d,
               COUNT(*) AS generated,
               SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
               SUM(CASE WHEN l.client_delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered
        FROM leads l
        WHERE l.campaign_id = ? AND l.created_at BETWEEN ? AND ?
        GROUP BY DATE(l.created_at)
        ORDER BY d ASC
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $campaignId, $rangeStart, $rangeEnd);
        $stmt->execute();
        $trendRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

$pageTitle = 'Campaign Analytics';
include __DIR__ . '/../../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0 admin-dashboard">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Campaign Analytics</div>
            <div class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../../dashboard/admin-dashboard.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../settings.php')); ?>"><i class="bi bi-sliders me-1"></i>Settings</a>
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
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars(substr($rangeStart,0,10)); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">End</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars(substr($rangeEnd,0,10)); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Trend Campaign</label>
            <select class="form-select form-select-sm" name="campaign_id" onchange="this.form.submit();">
                <option value="">None</option>
                <?php foreach ($campaigns as $c): ?>
                    <?php $cid = (int)($c['id'] ?? 0); ?>
                    <option value="<?php echo $cid; ?>" <?php echo $cid===$campaignId?'selected':''; ?>><?php echo htmlspecialchars((string)($c['name'] ?? '')); ?></option>
                <?php endforeach; ?>
            </select>
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
                        <div class="h4 mb-0"><?php echo number_format($summary['generated']); ?></div>
                        <div class="small text-muted">Forms: <?php echo number_format($summary['forms_submitted']); ?> • Pending: <?php echo number_format($summary['forms_pending']); ?></div>
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
                        <div class="h4 mb-0"><?php echo number_format($summary['pending_qa']); ?></div>
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
                        <div class="h4 mb-0"><?php echo number_format($summary['qualified']); ?></div>
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
                        <div class="h4 mb-0"><?php echo number_format($summary['disqualified']); ?></div>
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
                        <div class="h4 mb-0"><?php echo number_format($summary['delivered']); ?></div>
                    </div>
                    <div class="fs-3" style="color:#a78bfa"><i class="bi bi-send-check"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 kpi-tile kpi-info">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Revenue / Expense</div>
                        <div class="h5 mb-0"><?php echo number_format($summary['revenue'],2); ?></div>
                        <div class="small text-muted">Expense: <?php echo number_format($summary['expense'],2); ?></div>
                    </div>
                    <div class="text-info fs-3"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-building me-1"></i>Client Delivery Summary</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Client</th>
                            <th class="text-end">Campaigns</th>
                            <th class="text-end">Generated</th>
                            <th class="text-end">Delivered</th>
                            <th class="text-end">Pending</th>
                            <th class="text-end">Delivery%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientRows as $r): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?php echo htmlspecialchars(trim((string)($r['client_code'] ?? '')) !== '' ? (string)$r['client_code'] : (string)($r['client_name'] ?? '')); ?>
                                    <?php if (trim((string)($r['client_code'] ?? '')) !== '' && trim((string)($r['client_name'] ?? '')) !== ''): ?>
                                        <div class="text-muted small"><?php echo htmlspecialchars((string)($r['client_name'] ?? '')); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo number_format((int)($r['campaigns'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['generated'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                <td class="text-end"><span class="badge bg-secondary"><?php echo number_format((int)($r['delivery_pending'] ?? 0)); ?></span></td>
                                <td class="text-end"><span class="badge bg-primary"><?php echo number_format((float)($r['delivery_pct'] ?? 0), 1); ?>%</span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientRows)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-table me-1"></i>Campaign Summary</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Campaign</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th class="text-end">Generated</th>
                            <th class="text-end">Pending QA</th>
                            <th class="text-end">Qualified</th>
                            <th class="text-end">Disq</th>
                            <th class="text-end">Delivered</th>
                            <th class="text-end">Pending</th>
                            <th class="text-end">Delivery%</th>
                            <th class="text-end">Forms</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Expense</th>
                            <th class="text-end">ROI</th>
                            <th class="text-end">Eff. CPL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                                <td class="text-muted small">
                                    <?php
                                        $cc = trim((string)($r['client_code'] ?? ''));
                                        $cn = trim((string)($r['client_name'] ?? ''));
                                        echo htmlspecialchars($cc !== '' ? $cc : $cn);
                                    ?>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['campaign_status'] ?? '')); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['generated'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['pending_qa'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['qualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['disqualified'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['delivered'] ?? 0)); ?></td>
                                <td class="text-end"><span class="badge bg-secondary"><?php echo number_format((int)($r['delivery_pending'] ?? 0)); ?></span></td>
                                <td class="text-end"><span class="badge bg-primary"><?php echo number_format((float)($r['delivery_pct'] ?? 0), 1); ?>%</span></td>
                                <td class="text-end">
                                    <?php echo number_format((int)($r['forms_submitted'] ?? 0)); ?>
                                    <span class="text-muted small">/ <?php echo number_format((int)($r['forms_pending'] ?? 0)); ?></span>
                                </td>
                                <td class="text-end"><?php echo number_format((float)($r['revenue'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo number_format((float)($r['expense'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo number_format(((float)($r['roi'] ?? 0))*100, 1); ?>%</td>
                                <td class="text-end"><?php echo number_format((float)($r['effective_cpl'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="15" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($campaignId > 0): ?>
        <div class="card border-0 shadow-sm">
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
                                <tr><td colspan="4" class="text-center text-muted py-4">No data for selected campaign.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../../includes/layout/app_end.php'; ?>
