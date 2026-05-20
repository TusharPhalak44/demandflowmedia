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

$statuses = ['New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];
$counts = array_fill_keys($statuses, 0);
$total = 0;
$stmt = $conn->prepare("SELECT status, COUNT(*) AS c FROM sales_leads WHERE created_at BETWEEN ? AND ? GROUP BY status");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $r) {
        $st = (string)($r['status'] ?? '');
        if ($st === '') continue;
        $counts[$st] = (int)($r['c'] ?? 0);
        $total += (int)($r['c'] ?? 0);
    }
}

$overdue = 0;
$rs = $conn->query("SELECT COUNT(*) AS c FROM sales_leads WHERE next_follow_up_at IS NOT NULL AND next_follow_up_at < NOW() AND status NOT IN ('Closed Won','Closed Lost')");
if ($rs) $overdue = (int)(($rs->fetch_assoc() ?: [])['c'] ?? 0);

$owners = [];
$stmt = $conn->prepare("
    SELECT u.full_name, u.role,
           COUNT(sl.id) AS total,
           SUM(sl.status = 'Closed Won') AS won,
           SUM(sl.status = 'Closed Lost') AS lost,
           SUM(sl.status IN ('Proposal Sent','Negotiation')) AS opportunities
    FROM sales_leads sl
    LEFT JOIN users u ON u.id = sl.owner_id
    WHERE sl.created_at BETWEEN ? AND ?
    GROUP BY sl.owner_id
    ORDER BY won DESC, opportunities DESC, total DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $owners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$pageTitle = 'Sales Pipeline';
include __DIR__ . '/../../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Sales Pipeline</div>
            <div class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../../dashboard/admin-dashboard.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('../../sales/leads.php')); ?>"><i class="bi bi-funnel me-1"></i>Prospects</a>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3" method="get">
        <div class="col-md-4">
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
        <div class="col-md-2 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Total Prospects</div><div class="h4 mb-0"><?php echo number_format($total); ?></div><div class="small text-muted">Overdue: <?php echo number_format($overdue); ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Opportunities</div><div class="h4 mb-0"><?php echo number_format((int)($counts['Proposal Sent'] ?? 0) + (int)($counts['Negotiation'] ?? 0)); ?></div><div class="small text-muted">Proposal + Negotiation</div></div></div>
        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Closed Won</div><div class="h4 mb-0"><?php echo number_format((int)($counts['Closed Won'] ?? 0)); ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Closed Lost</div><div class="h4 mb-0"><?php echo number_format((int)($counts['Closed Lost'] ?? 0)); ?></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Funnel</div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($statuses as $st): ?>
                    <div class="col-6 col-lg-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small"><?php echo htmlspecialchars($st); ?></div>
                            <div class="h4 mb-0"><?php echo number_format((int)($counts[$st] ?? 0)); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-person-lines-fill me-1"></i>Owner Productivity</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Owner</th>
                            <th>Role</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Won</th>
                            <th class="text-end">Lost</th>
                            <th class="text-end">Opportunities</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($owners as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? 'Unassigned')); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['role'] ?? '')); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['total'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['won'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['lost'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((int)($r['opportunities'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($owners)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/layout/app_end.php'; ?>

