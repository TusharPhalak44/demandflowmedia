<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','director','manager_director','operations_director','operations_manager','operations_agent','qa','qa_director','qa_manager','qa_agent','agent','form_filler']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$visible = getScopedVisibleCampaignIdsForUser($userId, $role);
$campaigns = $visible === null ? getAllCampaignsBasic() : getCampaignsBasicByIds(array_keys($visible), false);
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10, (int)$_GET['per_page']) : 25;
$perPage = min(500, $perPage);
$offset = ($page - 1) * $perPage;

$rows = [];
$total = 0;
$campaignName = '';
$campaignCode = '';

if ($visible !== null && $campaignId > 0 && !isset($visible[$campaignId])) {
    $campaignId = 0;
}

if ($campaignId > 0) {
    $campRow = getCampaignById($campaignId) ?: null;
    $campaignName = $campRow ? (string)($campRow['name'] ?? '') : '';
    $campaignCode = getCampaignCode($campaignId) ?? '';
    $filters = ['campaign_id' => $campaignId];
    if ($visible !== null) $filters['campaign_ids'] = array_keys($visible);
    if ($q !== '') $filters['search'] = $q;
    $data = getLeads($filters, $perPage, $page);
    $rows = $data['leads'] ?? [];
    $total = (int)($data['total'] ?? 0);
}

$totalPages = $perPage > 0 ? max(1, (int)ceil($total / $perPage)) : 1;
?>
<?php $pageTitle = 'Campaign Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Campaign Leads</div>
            <div class="text-muted small"><?php echo htmlspecialchars($campaignName !== '' ? ($campaignName . ($campaignCode !== '' ? " ($campaignCode)" : '')) : 'Select a campaign'); ?></div>
        </div>
        <a class="btn btn-light border" href="list"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small text-muted">Campaign</label>
                    <select class="form-select form-select-sm" name="campaign_id" required>
                        <option value="">Select Campaign</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$campaignId === (int)$c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Search</label>
                    <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name, email, company, lead code">
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">Rows</label>
                    <select class="form-select form-select-sm" name="per_page">
                        <?php foreach ([25,50,100,500] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($campaignId <= 0): ?>
        <div class="alert alert-info border-0 shadow-sm">Select a campaign to view its leads.</div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <div class="fw-semibold">Leads</div>
                <div class="text-muted small">Total: <?php echo (int)$total; ?></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">SR No.</th>
                                <th>Date</th>
                                <th>Lead</th>
                                <th>Email</th>
                                <th>Company</th>
                                <th>QA</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No leads found.</td></tr>
                            <?php else: ?>
                                <?php $serialStart = (($page - 1) * $perPage) + 1; $i = 0; ?>
                                <?php foreach ($rows as $r): $i++; ?>
                                    <?php
                                        $qa = (string)($r['qa_status'] ?? 'Pending');
                                        $qaClass = 'bg-warning-subtle text-warning';
                                        if ($qa === 'Qualified' || $qa === 'Rectified') $qaClass = 'bg-success-subtle text-success';
                                        if ($qa === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
                                        if ($qa === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
                                        $nm = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="ps-3 text-muted small"><?php echo $serialStart + $i - 1; ?></td>
                                        <td class="text-muted small"><?php echo !empty($r['created_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$r['created_at']))) : '—'; ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($nm !== '' ? $nm : ((string)($r['lead_id'] ?? (string)($r['id'] ?? '')))); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['email'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['company_name'] ?? '')); ?></td>
                                        <td><span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars($qa); ?></span></td>
                                        <td class="text-end pe-3">
                                            <a class="btn btn-sm btn-outline-primary" href="../leads/lead-details.php?id=<?php echo (int)($r['id'] ?? 0); ?>"><i class="bi bi-eye"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                                $qs = $_GET;
                                $mk = function(int $p) use ($qs) {
                                    $qs['page'] = $p;
                                    return 'leads?' . http_build_query($qs);
                                };
                            ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($mk(max(1, $page-1))); ?>">Prev</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($mk(min($totalPages, $page+1))); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
