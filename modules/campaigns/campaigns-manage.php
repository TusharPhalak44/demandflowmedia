<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
if (isClient()) {
    header('Location: ../clients/client-campaigns');
    exit;
}
requireRole(['admin','director','manager_director','operations_director','operations_manager','operations_agent','agent','qa','qa_director','qa_manager','qa_agent','email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler','sales_director','sales_manager','sdr','vendor_admin','vendor_user']);
ensureCsrfToken();

$currentUser = getCurrentUser();
$canOpsActions = isAdmin() || hasRole(['operations_director','operations_manager']);
$canManageCampaigns = isAdmin() || isSalesDirector() || isSalesManager() || isClientAdmin() || hasRole(['director','manager_director','operations_director','operations_manager']);
$canAssignVendors = isAdmin() || isSalesDirector() || isSalesManager() || hasRole(['director','manager_director','operations_director']);
$canViewFinancials = isAdmin() || isSalesDirector() || isSalesManager();
ensureCampaignDetailsColumns();
$isSdr = isSDR();
$isVendor = isVendor();
$isClient = isClient();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh_counts') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit;
    }
    if (!$canManageCampaigns) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
    $df = $_POST['date_from'] ?? '';
    $dt = $_POST['date_to'] ?? '';
    // Trigger aggregation (computed on render); here we just validate inputs
    getCampaignTabCounts($df ?: null, $dt ?: null);
    echo json_encode(['ok'=>true]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit; }
    if (!$canManageCampaigns) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
    $cid = (int)($_POST['campaign_id'] ?? 0);
    $newStatus = (string)($_POST['status'] ?? '');
    if ($cid <= 0 || $newStatus === '') { echo json_encode(['ok'=>false,'error'=>'Invalid request']); exit; }
    try {
        $ok = setCampaignStatus($cid, $newStatus, (int)($currentUser['id'] ?? 0));
        echo json_encode(['ok'=>$ok]); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['list_vendor_assignments','assign_vendor','remove_vendor_assignment'], true)) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit; }
    if (!$canAssignVendors) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
    $action = (string)$_POST['action'];
    $conn = getDbConnection();
    if ($action === 'list_vendor_assignments') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        if ($cid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        $stmt = $conn->prepare("SELECT v.id AS vendor_id, v.vendor_code, v.name, m.vendor_cpl, m.vendor_cpl_currency, m.uploads_enabled, m.assigned_at FROM vendor_campaign_map m JOIN vendors v ON v.id = m.vendor_id WHERE m.campaign_id = ? ORDER BY v.name");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
    } elseif ($action === 'assign_vendor') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $vid = (int)($_POST['vendor_id'] ?? 0);
        $cpl = $_POST['vendor_cpl'] !== '' ? (float)$_POST['vendor_cpl'] : null;
        $cur = $_POST['vendor_cpl_currency'] ?? null;
        $uploads = isset($_POST['uploads_enabled']) && $_POST['uploads_enabled'] === '1' ? 1 : 0;
        if ($cid <= 0 || $vid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        $stmt = $conn->prepare("INSERT INTO vendor_campaign_map (vendor_id, campaign_id, vendor_cpl, vendor_cpl_currency, uploads_enabled, assigned_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vendor_cpl=VALUES(vendor_cpl), vendor_cpl_currency=VALUES(vendor_cpl_currency), uploads_enabled=VALUES(uploads_enabled), assigned_by=VALUES(assigned_by), assigned_at=NOW()");
        $uid = (int)($currentUser['id'] ?? 0);
        $stmt->bind_param('iissii', $vid, $cid, $cpl, $cur, $uploads, $uid);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok]); exit;
    } elseif ($action === 'remove_vendor_assignment') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $vid = (int)($_POST['vendor_id'] ?? 0);
        if ($cid <= 0 || $vid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        $stmt = $conn->prepare("DELETE FROM vendor_campaign_map WHERE campaign_id = ? AND vendor_id = ?");
        $stmt->bind_param('ii', $cid, $vid);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok]); exit;
    }
}
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'Live';
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10,(int)$_GET['per_page']) : 25;
$filters = [];
if (!empty($dateFrom)) { $filters['date_from'] = $dateFrom; }
if (!empty($dateTo)) { $filters['date_to'] = $dateTo; }
if (!empty($search)) { $filters['search'] = $search; }
if ($status !== '') { $filters['status'] = $status; }
$statusOptions = ['Draft','Active','Pause','Complete','Live'];
$legacyToNew = ['Paused' => 'Pause', 'Completed' => 'Complete', 'Quotes' => 'Draft'];
if (isset($legacyToNew[$status])) $status = $legacyToNew[$status];
if (!$canManageCampaigns && !$isSdr && !isQA()) {
    $status = 'Live';
    $filters['status'] = $status;
}
$statusLabel = $status === '' ? 'All' : ($status === 'Live' ? 'Active' : $status);
$scopeCampaignIds = null;
if (!$canManageCampaigns && !$isVendor && !$isClient) {
    $visibleMap = getScopedVisibleCampaignIdsForUser((int)($currentUser['id'] ?? 0), (string)($currentUser['role'] ?? ''));
    $scopeCampaignIds = $visibleMap === null ? null : array_keys($visibleMap);
}

$overviewRows = getCampaignOverviewByStatus($status ?: null, $dateFrom ?: null, $dateTo ?: null, $scopeCampaignIds);

if ($isVendor) {
    $vendorId = (int)($currentUser['vendor_id'] ?? 0);
    $conn = getDbConnection();
    $assigned = [];
    $stmt = $conn->prepare("SELECT campaign_id FROM vendor_campaign_map WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) $assigned[(int)$r['campaign_id']] = true;
    $stmt->close();

    if ($currentUser['role'] === 'vendor_user') {
        $userAssigned = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $currentUser['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $userAssigned[(int)$r['campaign_id']] = true;
        $stmt->close();
        $overviewRows = array_values(array_filter($overviewRows, function($r) use ($assigned, $userAssigned) {
            $id = (int)($r['id'] ?? 0);
            return isset($assigned[$id]) && isset($userAssigned[$id]);
        }));
    } else {
        $overviewRows = array_values(array_filter($overviewRows, function($r) use ($assigned) {
            $id = (int)($r['id'] ?? 0);
            return isset($assigned[$id]);
        }));
    }
}
if ($isClient) {
    $clientId = (int)($currentUser['client_id'] ?? 0);
    $overviewRows = array_values(array_filter($overviewRows, function($r) use ($clientId) {
        return (int)($r['client_id'] ?? 0) === $clientId;
    }));
    if ($currentUser['role'] === 'client_sdr') {
        $userAssigned = [];
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $currentUser['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $userAssigned[(int)$r['campaign_id']] = true;
        $stmt->close();
        $overviewRows = array_values(array_filter($overviewRows, function($r) use ($userAssigned) {
            $id = (int)($r['id'] ?? 0);
            return isset($userAssigned[$id]);
        }));
    }
}
$tabCounts = getCampaignTabCounts($dateFrom ?: null, $dateTo ?: null, $scopeCampaignIds);
if ($search !== '') {
    $q = mb_strtolower($search);
    $overviewRows = array_values(array_filter($overviewRows, function($r) use ($q){
        return (strpos(mb_strtolower($r['name'] ?? ''), $q) !== false)
            || (strpos(mb_strtolower($r['code'] ?? ''), $q) !== false)
            || (strpos(mb_strtolower($r['client_code'] ?? ''), $q) !== false)
            || (strpos(mb_strtolower($r['client_name'] ?? ''), $q) !== false);
    }));
}

// Fetch status update author names
if (!empty($overviewRows)) {
    $uids = array_values(array_filter(array_unique(array_map(fn($r) => (int)($r['status_updated_by'] ?? 0), $overviewRows))));
    $authorMap = [];
    if (!empty($uids)) {
        $conn = getDbConnection();
        $in = implode(',', array_fill(0, count($uids), '?'));
        $stmt = $conn->prepare("SELECT id, full_name, username FROM users WHERE id IN ($in)");
        $stmt->bind_param(str_repeat('i', count($uids)), ...$uids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($u = $res->fetch_assoc()) {
            $authorMap[(int)$u['id']] = $u['full_name'] ?: $u['username'];
        }
        $stmt->close();
    }
    foreach ($overviewRows as &$row) {
        $sid = (int)($row['status_updated_by'] ?? 0);
        $row['status_updated_by_name'] = $authorMap[$sid] ?? '';
    }
    unset($row);
}

$campaignIdsForStats = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $overviewRows), fn($v) => $v > 0));
$campaignStatsById = !empty($campaignIdsForStats) ? getCampaignLeadStatsBulk($campaignIdsForStats) : [];
// SR numbers
$srStart = 1;
?>
<?php $pageTitle = 'Campaigns'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
#campaignTable th,
#campaignTable td {
  vertical-align: middle;
}
.pacing-circle {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  position: relative;
  background: conic-gradient(#198754 0%, #e9ecef 0%);
}
.pacing-circle::before {
  content: '';
  position: absolute;
  inset: 4px;
  border-radius: 50%;
  background: #fff;
}
.pacing-circle span {
  position: relative;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1;
  color: #198754;
}
</style>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Campaigns</div>
            <div class="text-muted small">Filter, track pacing, and manage campaign setup.</div>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <?php if ($canOpsActions): ?>
                <a href="create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Create Campaign</a>
            <?php endif; ?>
            <div class="dropdown">
                <button class="btn btn-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="export?date_from=<?php echo htmlspecialchars($dateFrom); ?>&date_to=<?php echo htmlspecialchars($dateTo); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&format=csv">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>CSV
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="export?date_from=<?php echo htmlspecialchars($dateFrom); ?>&date_to=<?php echo htmlspecialchars($dateTo); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&format=xls">
                            <i class="bi bi-file-earmark-excel me-2"></i>XLS
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-funnel me-1"></i>Filters</span>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#campaignFilters" aria-expanded="false" aria-controls="campaignFilters" id="campaignFiltersToggle">Show Filters</button>
        </div>
        <div id="campaignFilters" class="collapse">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label small text-muted" for="date_from">Date From</label>
                            <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label small text-muted" for="date_to">Date To</label>
                            <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label class="form-label small text-muted" for="search">Keyword</label>
                            <input type="text" class="form-control form-control-sm" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or code">
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-4">
                            <label class="form-label small text-muted" for="status">Status</label>
                            <select class="form-select form-select-sm" id="status" name="status">
                                <option value="">All</option>
                                <?php foreach ($statusOptions as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo ($status===$st)?'selected':''; ?>><?php echo $st; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-4 ms-auto d-flex justify-content-end">
                            <button class="btn btn-primary btn-sm w-100" type="submit">
                                <i class="bi bi-funnel me-1"></i>Apply
                            </button>
                        </div>
                </form>
            </div>
        </div>
    </div>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light">
            <div class="d-flex align-items-center justify-content-between">
                <ul class="nav nav-tabs card-header-tabs">
                    <?php $tabs=[
                        ['label'=>'Draft','status'=>'Draft','count'=>$tabCounts['Draft']??0],
                        ['label'=>'Active','status'=>'Active','count'=>$tabCounts['Active']??0],
                        ['label'=>'Live','status'=>'Live','count'=>$tabCounts['Live']??0],
                        ['label'=>'Pause','status'=>'Pause','count'=>$tabCounts['Pause']??0],
                        ['label'=>'Complete','status'=>'Complete','count'=>$tabCounts['Complete']??0],
                        ['label'=>'All Campaigns','status'=>'','count'=>$tabCounts['All']??0],
                    ];
                    foreach($tabs as $t):
                        $isActive = (($status===$t['status']) || ($t['status']==='' && $status===''));
                        $qs = $_GET; $qs['status'] = $t['status']; $url='list?' . http_build_query($qs);
                    ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $isActive?'active':''; ?>" href="<?php echo $url; ?>">
                            <?php echo htmlspecialchars($t['label']); ?>
                            <span class="badge bg-secondary ms-1"><?php echo (int)$t['count']; ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($canManageCampaigns): ?>
                    <button id="refreshCounts" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Update Counts</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="campaignTable" class="table table-striped table-hover align-middle table-sm" style="font-size:0.9rem;">
                    <thead>
                        <tr>
                            <th class="text-start">Campaign Name</th>
                            <th class="text-start">Campaign Code</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th data-bs-toggle="tooltip" title="Total Allocation">A</th>
                            <th data-bs-toggle="tooltip" title="Total Generated">G</th>                            
                            <th data-bs-toggle="tooltip" title="Pending QA">PQA</th>
                            <th data-bs-toggle="tooltip" title="Disqualified Leads">DQ</th>
                            <th data-bs-toggle="tooltip" title="Qualified Leads">Q</th>
                            <th data-bs-toggle="tooltip" title="Total Delivered">D</th>
                            <th data-bs-toggle="tooltip" title="Pending Allocation">P</th>
                            <th data-bs-toggle="tooltip" title="Delivery Progress (Delivered / Allocation)">Pacing</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($overviewRows)): ?>
                            <tr><td colspan="14" class="text-center text-muted">No campaigns found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($overviewRows as $r): ?>
                                <tr>
                                    <td class="text-start"><?php echo htmlspecialchars($r['name'] ?? ''); ?></td>
                                    <td class="text-start text-muted"><?php echo htmlspecialchars($r['code'] ?? ''); ?></td>
                                    <?php
                                        $statusVal = (string)($r['status'] ?? '');
                                        $statusMap = ['Live'=>'success','Active'=>'primary','Pause'=>'warning','Draft'=>'secondary','Complete'=>'dark'];
                                        $statusCls = $statusMap[$statusVal] ?? 'secondary';
                                        $statusTextCls = ($statusVal === 'Pause') ? 'text-dark' : '';
                                    ?>
                                    <td>
                                        <span class="badge bg-<?php echo $statusCls; ?> <?php echo $statusTextCls; ?>"><?php echo htmlspecialchars($statusVal); ?></span>
                                        <?php if ($statusVal === 'Pause' && !empty($r['status_updated_by_name'])): ?>
                                            <div class="x-small text-muted mt-1" style="font-size: 0.7rem;">by <?php echo htmlspecialchars($r['status_updated_by_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($r['start_date'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($r['end_date'] ?? ''); ?></td>
                                    <?php 
                                        $A = (int)($r['total_leads'] ?? 0);
                                        $cid = (int)($r['id'] ?? 0);
                                        $st = $cid > 0 ? (array)($campaignStatsById[$cid] ?? getCampaignLeadTableStats($cid)) : ['total'=>0,'pending_qa'=>0,'approved'=>0,'rejected'=>0,'client_delivered'=>0,'last_submitted_at'=>null];
                                        $G = (int)($st['total'] ?? 0);
                                        $pendingQa = (int)($st['pending_qa'] ?? 0);
                                        $Q = (int)($st['approved'] ?? 0);
                                        $DQ = (int)($st['rejected'] ?? 0);
                                        $D = (int)($st['client_delivered'] ?? 0);
                                        $P = max(0, $A - $D);
                                        $pct = $A > 0 ? round((min($D, $A) / $A) * 100) : 0;
                                    ?>
                                    <td><?php echo $A; ?></td>
                                    <td><?php echo $G; ?></td>
                                    
                                    <td><?php echo $pendingQa; ?></td>
                                    <td><?php echo $DQ; ?></td>
                                    <td><?php echo $Q; ?></td>
                                    <td><?php echo $D; ?></td>
                                    <td><?php echo $P; ?></td>
                                    <td class="text-nowrap">
                                      <div class="pacing-circle" style="background: conic-gradient(#198754 <?php echo $pct; ?>%, #e9ecef 0%);">
                                        <span><?php echo $pct; ?>%</span>
                                      </div>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a class="btn btn-light border" href="view?id=<?php echo (int)$r['id']; ?>" title="View Campaign">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($canOpsActions): ?>
                                                <a class="btn btn-light border" href="leads?campaign_id=<?php echo (int)$r['id']; ?>" title="Leads">
                                                    <i class="bi bi-list-ul"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($canOpsActions): ?>
                                                <a class="btn btn-light border" href="edit?id=<?php echo (int)$r['id']; ?>" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a class="btn btn-light border" href="../forms/forms-manage.php?campaign_id=<?php echo (int)$r['id']; ?>" title="Forms">
                                                    <i class="bi bi-ui-checks"></i>
                                                </a>
                                                <a class="btn btn-light border" href="allocation?campaign_id=<?php echo (int)$r['id']; ?>" title="Assign / Allocate">
                                                    <i class="bi bi-bullseye"></i>
                                                </a>
                                                <a class="btn btn-light border" href="files?campaign_id=<?php echo (int)$r['id']; ?>" title="Files">
                                                    <i class="bi bi-box-arrow-in-down"></i>
                                                </a>
                                                <?php if ($canAssignVendors): ?>
                                                <button class="btn btn-light border" type="button" title="Assign Vendors" data-action="open-vendor-assign" data-campaign-id="<?php echo (int)$r['id']; ?>">
                                                    <i class="bi bi-truck"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php
                                                    $curStatus = (string)($r['status'] ?? '');
                                                    $hasForm = (int)($r['assigned_form_id'] ?? 0) > 0;
                                                ?>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-light border dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Status">
                                                        <i class="bi bi-sliders"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <?php if ($curStatus === 'Draft'): ?>
                                                            <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Active">Activate</a></li>
                                                        <?php elseif ($curStatus === 'Active'): ?>
                                                            <li><a class="dropdown-item <?php echo $hasForm ? '' : 'disabled'; ?>" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Live">Go Live</a></li>
                                                        <?php elseif ($curStatus === 'Pause'): ?>
                                                            <li><a class="dropdown-item <?php echo $hasForm ? '' : 'disabled'; ?>" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Live">Resume Live</a></li>
                                                        <?php elseif ($curStatus === 'Live'): ?>
                                                            <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Pause">Pause</a></li>
                                                            <li><a class="dropdown-item" href="#" data-action="status" data-cid="<?php echo (int)$r['id']; ?>" data-next="Complete">Complete</a></li>
                                                        <?php endif; ?>
                                                        <?php if (!$hasForm && ($curStatus === 'Active' || $curStatus === 'Pause')): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="../forms/forms-manage.php?campaign_id=<?php echo (int)$r['id']; ?>">Assign Form</a></li>
                                                        <?php endif; ?>
                                                        <?php if ($canManageCampaigns): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="#" data-action="delete" data-cid="<?php echo (int)$r['id']; ?>">Delete</a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
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

<?php
$vendorsList = [];
if ($canAssignVendors) {
    $rs = getDbConnection()->query("SELECT id, vendor_code, name FROM vendors WHERE is_active=1 ORDER BY name");
    if ($rs) { $vendorsList = $rs->fetch_all(MYSQLI_ASSOC) ?: []; }
}
?>
<?php if ($canAssignVendors): ?>
<div class="modal fade" id="vendorAssignModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Assign Vendors</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form class="row g-2 align-items-end" id="vendorAssignForm">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="assign_vendor">
          <input type="hidden" name="campaign_id" id="assign_campaign_id" value="0">
          <div class="col-md-6">
            <label class="form-label small text-muted">Vendor</label>
            <select class="form-select form-select-sm" name="vendor_id" id="assign_vendor_id">
              <option value="">Select Vendor</option>
              <?php foreach ($vendorsList as $v): ?>
                <option value="<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars(($v['name'] ?? '').' ['.($v['vendor_code'] ?? '').']'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small text-muted">Vendor CPL</label>
            <input type="number" step="0.01" class="form-control form-control-sm" name="vendor_cpl" id="assign_vendor_cpl" placeholder="0.00">
          </div>
          <div class="col-md-2">
            <label class="form-label small text-muted">Currency</label>
            <select class="form-select form-select-sm" name="vendor_cpl_currency" id="assign_vendor_cpl_currency">
              <option value="">—</option>
              <option value="USD">USD</option>
              <option value="EUR">EUR</option>
              <option value="GBP">GBP</option>
              <option value="INR">INR</option>
            </select>
          </div>
          <div class="col-md-1 d-flex align-items-end">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="assign_uploads_enabled" name="uploads_enabled" value="1" checked>
              <label class="form-check-label small" for="assign_uploads_enabled">Upload</label>
            </div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add/Update</button>
          </div>
        </form>
        <hr>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Vendor</th>
                <th class="text-end">CPL</th>
                <th class="text-center">Uploads</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody id="vendorAssignBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
  </div>

<script>
const token = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
const canManageCampaigns = <?php echo $canManageCampaigns ? 'true' : 'false'; ?>;
const isAdmin = <?php echo isAdmin() ? 'true' : 'false'; ?>;
function initTooltips() {
  if (!window.bootstrap || !bootstrap.Tooltip) return;
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });
}
document.addEventListener('DOMContentLoaded', initTooltips);
window.addEventListener('load', initTooltips);
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action="delete"]');
  if (!btn) return;
  const id = btn.getAttribute('data-cid');
  if(confirm('Delete this campaign?')){
    const fd = new FormData();
    fd.append('csrf_token', token);
    fd.append('id', id);
    fetch('delete',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json()).then(d=>{
      if(d&&d.ok){ location.reload(); } else { alert(d.error||'Failed'); }
    });
  }
});
<?php if ($canManageCampaigns): ?>
document.getElementById('refreshCounts')?.addEventListener('click', function(){
  const fd = new FormData();
  fd.append('csrf_token', token);
  fd.append('action', 'refresh_counts');
  fd.append('date_from', document.getElementById('date_from').value||'');
  fd.append('date_to', document.getElementById('date_to').value||'');
  fetch('list', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd}).then(r=>r.json()).then(d=>{
    if(d&&d.ok){ location.reload(); } else { alert(d.error||'Failed to update counts'); }
  });
});
<?php endif; ?>
document.addEventListener('click', (e) => {
  const el = e.target.closest('[data-action="status"]');
  if (!el) return;
  e.preventDefault();
  if (el.classList.contains('disabled')) return;
  const cid = el.getAttribute('data-cid');
  const st = el.getAttribute('data-next');
  if(!cid || !st) return;
  const fd = new FormData();
  fd.append('csrf_token', token);
  fd.append('action', 'update_status');
  fd.append('campaign_id', cid);
  fd.append('status', st);
  fetch('list', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd}).then(r=>r.json()).then(d=>{
    if(d && d.ok){ location.reload(); }
    else { alert(d?.error || 'Failed to update status'); }
  });
});
const filtersEl = document.getElementById('campaignFilters');
const filtersBtn = document.getElementById('campaignFiltersToggle');
if (filtersEl && filtersBtn) {
  const setLabel = () => { filtersBtn.textContent = filtersEl.classList.contains('show') ? 'Hide Filters' : 'Show Filters'; };
  filtersEl.addEventListener('shown.bs.collapse', setLabel);
  filtersEl.addEventListener('hidden.bs.collapse', setLabel);
  setLabel();
}
</script>
<script>
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action="open-vendor-assign"]');
  if (!btn) return;
  const cid = btn.getAttribute('data-campaign-id');
  const mEl = document.getElementById('vendorAssignModal');
  if (!cid || !mEl) return;
  document.getElementById('assign_campaign_id').value = cid;
  const body = document.getElementById('vendorAssignBody');
  body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>';
  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
  fd.append('action', 'list_vendor_assignments');
  fd.append('campaign_id', cid);
  fetch('list', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(r=>r.json()).then(d=>{
      if (d && d.ok) {
        if (!Array.isArray(d.rows) || d.rows.length===0) {
          body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No vendors assigned.</td></tr>';
        } else {
          body.innerHTML = '';
          d.rows.forEach(row=>{
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>'+eh(row.name||'')+' <span class="text-muted small">['+eh(row.vendor_code||'')+']</span></td>'
              + '<td class="text-end">'+(row.vendor_cpl? String(row.vendor_cpl) : '-')+' '+eh(row.vendor_cpl_currency||'')+'</td>'
              + '<td class="text-center">'+(parseInt(row.uploads_enabled)===1?'<span class="badge bg-success-subtle text-success border">Yes</span>':'<span class="badge bg-secondary-subtle text-secondary border">No</span>')+'</td>'
              + '<td class="text-end"><button class="btn btn-sm btn-outline-danger" data-action="remove-vendor-assignment" data-vendor-id="'+String(row.vendor_id)+'"><i class="bi bi-trash"></i></button></td>';
            body.appendChild(tr);
          });
        }
        if (window.bootstrap && bootstrap.Modal) {
          new bootstrap.Modal(mEl).show();
        }
      } else {
        body.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">'+eh(d?.error||'Failed to load')+'</td></tr>';
      }
    }).catch(()=>{ body.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">Failed to load</td></tr>'; });
});
document.getElementById('vendorAssignForm')?.addEventListener('submit', function(ev){
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  fetch('list', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(r=>r.json()).then(d=>{
      if (d && d.ok) {
        const cid = document.getElementById('assign_campaign_id').value;
        const btn = document.querySelector('[data-action="open-vendor-assign"][data-campaign-id="'+CSS.escape(String(cid))+'"]');
        if (btn) btn.click();
      } else {
        alert(d?.error || 'Failed');
      }
    }).catch(()=>{});
});
document.addEventListener('click', (e)=>{
  const rm = e.target.closest('[data-action="remove-vendor-assignment"]');
  if (!rm) return;
  const vid = rm.getAttribute('data-vendor-id');
  const cid = document.getElementById('assign_campaign_id').value;
  if (!vid || !cid) return;
  if (!confirm('Remove this vendor from campaign?')) return;
  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
  fd.append('action', 'remove_vendor_assignment');
  fd.append('campaign_id', cid);
  fd.append('vendor_id', vid);
  fetch('list', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(r=>r.json()).then(d=>{
      if (d && d.ok) {
        const btn = document.querySelector('[data-action="open-vendor-assign"][data-campaign-id="'+CSS.escape(String(cid))+'"]');
        if (btn) btn.click();
      } else {
        alert(d?.error || 'Failed');
      }
    }).catch(()=>{});
});
function eh(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/\\'/g,'&#39;');}
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
