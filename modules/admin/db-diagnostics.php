<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();

$conn = getDbConnection();

$version = null;
$rs = $conn->query("SELECT VERSION() AS v");
if ($rs) { $version = (string)(($rs->fetch_assoc()['v'] ?? '') ?: ''); }

$sqlMode = null;
$rs = $conn->query("SELECT @@sql_mode AS m");
if ($rs) { $sqlMode = (string)(($rs->fetch_assoc()['m'] ?? '') ?: ''); }

$checks = [];
$checks['can_select_leads'] = (bool)$conn->query("SELECT 1 FROM leads LIMIT 1");
$checks['leads_error'] = $checks['can_select_leads'] ? '' : (string)($conn->error ?? '');

$columns = [];
$rs = $conn->query("SHOW COLUMNS FROM leads");
if ($rs) {
    while ($r = $rs->fetch_assoc()) $columns[] = $r;
}

$agentInsertSql = "
    INSERT INTO leads (
        lead_id, campaign_id, campaign_name, client_id, agent_id, agent_name,
        first_name, last_name, job_title, email, company_domain,
        contact_phone, industry, company_name, company_size, country,
        lead_comment, recording_path, ip_address,
        created_by, updated_by, vendor_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
$agentInsertPrepareOk = false;
$agentInsertPrepareErr = '';
$stmt = $conn->prepare($agentInsertSql);
if ($stmt) {
    $agentInsertPrepareOk = true;
    $stmt->close();
} else {
    $agentInsertPrepareErr = (string)($conn->error ?? 'unknown');
}

$campaignTables = [];
$rs = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'leads\\_%' ORDER BY TABLE_NAME");
if ($rs) {
    while ($r = $rs->fetch_assoc()) {
        $tn = (string)($r['TABLE_NAME'] ?? '');
        if ($tn !== '') $campaignTables[] = $tn;
    }
}

?>
<?php $pageTitle = 'DB Diagnostics'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">DB Diagnostics</div>
            <div class="text-muted small">Admin-only SQL prepare troubleshooting</div>
        </div>
        <a class="btn btn-light border" href="javascript:history.back()"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Server</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between"><span class="text-muted">DB Version</span><span class="fw-semibold"><?php echo htmlspecialchars($version ?: '—'); ?></span></div>
                    <div class="mt-2">
                        <div class="text-muted small">SQL Mode</div>
                        <div class="small"><?php echo htmlspecialchars($sqlMode ?: '—'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Prepare Checks</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Agent lead insert prepare</span>
                        <?php if ($agentInsertPrepareOk): ?>
                            <span class="badge bg-success-subtle text-success border">OK</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border">FAILED</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$agentInsertPrepareOk): ?>
                        <div class="alert alert-danger border-0 mb-0">
                            <div class="fw-semibold mb-1">MySQL Error</div>
                            <div class="small"><?php echo htmlspecialchars($agentInsertPrepareErr); ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$checks['can_select_leads']): ?>
                        <div class="alert alert-warning border-0 mt-2 mb-0">
                            <div class="fw-semibold mb-1">Leads table select failed</div>
                            <div class="small"><?php echo htmlspecialchars($checks['leads_error']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Leads Columns</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Field</th>
                                    <th>Type</th>
                                    <th>Null</th>
                                    <th>Key</th>
                                    <th>Default</th>
                                    <th class="pe-3">Extra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($columns as $c): ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($c['Field'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Type'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Null'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Key'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Default'] ?? '')); ?></td>
                                        <td class="text-muted small pe-3"><?php echo htmlspecialchars((string)($c['Extra'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($columns)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">Unable to read leads columns.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Campaign Lead Tables</div>
                <div class="card-body">
                    <?php if (empty($campaignTables)): ?>
                        <div class="text-muted">No leads_* tables found.</div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($campaignTables as $t): ?>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($t); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
