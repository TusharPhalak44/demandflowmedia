<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$conn = getDbConnection();
$user = getCurrentUser();
$error = '';
$success = '';

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];
$start = sprintf('%04d-%02d-01', $year, $month) . ' 00:00:00';
$end = date('Y-m-t', strtotime(substr($start, 0, 10))) . ' 23:59:59';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_revenue') {
                $campaignId = (int)($_POST['campaign_id'] ?? 0);
                $revenue = trim((string)($_POST['revenue'] ?? ''));
                $currency = trim((string)($_POST['currency'] ?? 'USD'));
                if ($campaignId <= 0) throw new RuntimeException('Invalid campaign.');
                $revVal = $revenue !== '' ? (float)$revenue : null;
                $updatedBy = (int)($user['id'] ?? 0);
                $stmt = $conn->prepare("INSERT INTO campaign_revenue (campaign_id, revenue, currency, updated_by) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE revenue = VALUES(revenue), currency = VALUES(currency), updated_by = VALUES(updated_by)");
                $stmt->bind_param('idsi', $campaignId, $revVal, $currency, $updatedBy);
                if (!$stmt->execute()) throw new RuntimeException('Failed to save revenue.');
                $stmt->close();
                $success = 'Revenue saved.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [$start, $end, $start, $end];
$types = 'ssss';
if ($q !== '') {
    $where = "WHERE c.name LIKE ? OR d.code LIKE ? OR d.client_code LIKE ?";
    $like = '%'.$q.'%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$sql = "SELECT c.id, c.name, c.active, d.code, d.client_code, d.status, d.cpl, d.cpl_currency,
        r.revenue, r.currency AS revenue_currency, r.updated_at,
        SUM(CASE WHEN l.client_delivery_status = 'Accepted' AND l.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS delivered_month,
         SUM(CASE WHEN l.client_delivery_status = 'Accepted' AND l.created_at BETWEEN ? AND ? THEN COALESCE(d.cpl, 0) ELSE 0 END) AS generated_month
        FROM campaigns c
        LEFT JOIN campaign_details d ON d.campaign_id = c.id
        LEFT JOIN campaign_revenue r ON r.campaign_id = c.id
        LEFT JOIN leads l ON l.campaign_id = c.id
        $where
        GROUP BY c.id, c.name, c.active, d.code, d.client_code, d.status, d.cpl, d.cpl_currency, r.revenue, r.currency, r.updated_at
        ORDER BY c.id DESC";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$totalDelivered = 0;
$totalsGenerated = [];
$totalsAllocatedAuto = [];
$totalsAllocatedManual = [];
foreach ($rows as $r) {
    $totalDelivered += (int)($r['delivered_month'] ?? 0);
    $gCur = strtoupper(trim((string)($r['cpl_currency'] ?? 'USD')));
    if ($gCur === '') $gCur = 'USD';
    $gVal = (float)($r['generated_month'] ?? 0);  
    if (!isset($totalsGenerated[$gCur])) $totalsGenerated[$gCur] = 0.0;
    $totalsGenerated[$gCur] += $gVal;
    if (!isset($totalsAllocatedAuto[$gCur])) $totalsAllocatedAuto[$gCur] = 0.0;
    $totalsAllocatedAuto[$gCur] += $gVal;

    $mCur = strtoupper(trim((string)($r['revenue_currency'] ?? $gCur)));
    if ($mCur === '') $mCur = $gCur;
    $mVal = (float)($r['revenue'] ?? 0);
    if ($mVal > 0) {
        if (!isset($totalsAllocatedManual[$mCur])) $totalsAllocatedManual[$mCur] = 0.0;
        $totalsAllocatedManual[$mCur] += $mVal;
    }
}
?>

<?php $pageTitle = 'Revenue'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Revenue</h3>
      <div class="text-muted small">Month: <?php echo htmlspecialchars($monthStr); ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="revenue-dashboard?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-columns-gap me-1"></i>Dashboard</a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card border-0 shadow-sm p-3 h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Accepted (Billable) Leads</div>
          <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-check2-circle"></i></span>
        </div>
        <div class="h4 mb-0 mt-1"><?php echo number_format($totalDelivered); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm p-3 h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Allocated (Accepted × CPL)</div>
          <span class="badge bg-success-subtle text-success border"><i class="bi bi-calculator"></i></span>
        </div>
        <div class="h4 mb-0 mt-1">
          <?php
            $gTopCur = 'USD'; $gTopVal = 0.0;
            foreach ($totalsAllocatedAuto as $c => $v) { if ($v > $gTopVal) { $gTopVal = (float)$v; $gTopCur = (string)$c; } }
            echo htmlspecialchars($gTopCur . ' ' . number_format($gTopVal, 2));
          ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm p-3 h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Manual Allocated</div>
          <span class="badge bg-warning-subtle text-warning border"><i class="bi bi-pencil-square"></i></span>
        </div>
        <div class="h4 mb-0 mt-1">
          <?php
            $aTopCur = 'USD'; $aTopVal = 0.0;
            foreach ($totalsAllocatedManual as $c => $v) { if ($v > $aTopVal) { $aTopVal = (float)$v; $aTopCur = (string)$c; } }
            echo htmlspecialchars($aTopCur . ' ' . number_format($aTopVal, 2));
          ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm p-3 h-100">
        <div class="d-flex justify-content-between align-items-center">
          <div class="text-muted small">Month</div>
          <span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-calendar3"></i></span>
        </div>
        <div class="h4 mb-0 mt-1"><?php echo htmlspecialchars($monthStr); ?></div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-6">
          <label class="form-label small text-muted mb-1">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Campaign / Code / Client Code">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">Month</label>
          <input class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($monthStr); ?>" placeholder="YYYY-MM">
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Search</button>
        </div>
      </form>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Campaign</th>
            <th>Client</th>
            <th class="text-end">Accepted</th>
            <th class="text-end">Allocated (Auto)</th>
            <th class="text-end">Manual Allocated</th>
            <th class="text-end">Variance</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No campaigns found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $del = (int)($r['delivered_month'] ?? 0);
                $gCur = strtoupper(trim((string)($r['cpl_currency'] ?? 'USD')));
                if ($gCur === '') $gCur = 'USD';
                $auto = (float)($r['generated_month'] ?? 0);
                $mCur = strtoupper(trim((string)($r['revenue_currency'] ?? $gCur)));
                if ($mCur === '') $mCur = $gCur;
                $manual = (float)($r['revenue'] ?? 0);
                $variance = $manual > 0 ? ($manual - $auto) : 0.0;
                $status = (string)($r['status'] ?? '');
                $badge = $status === 'Active' ? 'bg-success-subtle text-success border border-success' : 'bg-secondary-subtle text-secondary border';
              ?>
              <tr>
                <td class="fw-semibold">
                  <?php echo htmlspecialchars((string)($r['name'] ?? '')); ?>
                  <?php if (!empty($r['code'])): ?>
                    <div class="text-muted small"><?php echo htmlspecialchars((string)$r['code']); ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['client_code'] ?? '')); ?></div>
                  <?php if ($status !== ''): ?>
                    <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?php echo number_format($del); ?></td>
                <td class="text-end fw-semibold"><?php echo $del > 0 ? htmlspecialchars($gCur . ' ' . number_format($auto, 2)) : '<span class="text-muted">—</span>'; ?></td>
                <td class="text-end">
                  <?php if ($manual > 0): ?>
                    <?php echo htmlspecialchars($mCur . ' ' . number_format($manual, 2)); ?>
                    <div class="text-muted small">Override</div>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                    <div class="text-muted small">Auto used</div>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?php if ($manual > 0): ?>
                    <?php echo htmlspecialchars($mCur . ' ' . number_format($variance, 2)); ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <form method="post" class="d-inline-block">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="save_revenue">
                    <input type="hidden" name="campaign_id" value="<?php echo (int)$r['id']; ?>">
                    <div class="d-flex gap-1 justify-content-end flex-wrap">
                      <input class="form-control form-control-sm" style="width:120px" name="revenue" value="<?php echo htmlspecialchars((string)($r['revenue'] ?? '')); ?>" placeholder="Manual">
                      <input class="form-control form-control-sm" style="width:80px" name="currency" value="<?php echo htmlspecialchars((string)($r['revenue_currency'] ?? $gCur)); ?>" placeholder="USD">
                      <button class="btn btn-sm btn-primary" type="submit" title="Save"><i class="bi bi-check2"></i></button>
                      <a class="btn btn-sm btn-outline-secondary" href="invoices?month=<?php echo urlencode($monthStr); ?>&campaign_id=<?php echo (int)$r['id']; ?>" title="Invoice"><i class="bi bi-receipt"></i></a>
                    </div>
                  </form>
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
