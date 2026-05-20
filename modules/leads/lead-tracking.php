<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','qa','qa_agent','qa_manager','qa_director','operations_agent','operations_manager','operations_director','form_filler']);

$user = getCurrentUser();
$conn = getDbConnection();

$leadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$leadCode = isset($_GET['lead_id']) ? trim((string)$_GET['lead_id']) : '';

$lead = null;
if ($leadId > 0) {
    $lead = getLeadById($leadId);
} elseif ($leadCode !== '') {
    $lead = getLeadByCode($leadCode);
}

if (($user['role'] ?? '') === 'agent' && $lead && isset($lead['agent_id']) && (int)$lead['agent_id'] !== (int)($user['id'] ?? 0)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$getColumns = function(string $table) use ($conn): array {
    $esc = $conn->real_escape_string($table);
    $rs = $conn->query("
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$esc'
        ORDER BY ORDINAL_POSITION
    ");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
};

$activity = [];
$submissions = [];
$files = [];
$createdByName = '';
$updatedByName = '';

if ($lead) {
    $lead = enrichLeadRow($lead);
    $lid = (int)($lead['id'] ?? 0);

    $createdBy = isset($lead['created_by']) ? (int)$lead['created_by'] : 0;
    $updatedBy = isset($lead['updated_by']) ? (int)$lead['updated_by'] : 0;

    if ($createdBy > 0) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $createdBy);
        $stmt->execute();
        $createdByName = (string)($stmt->get_result()->fetch_row()[0] ?? '');
        $stmt->close();
    }
    if ($updatedBy > 0) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $updatedBy);
        $stmt->execute();
        $updatedByName = (string)($stmt->get_result()->fetch_row()[0] ?? '');
        $stmt->close();
    }

    $stmt = $conn->prepare("
        SELECT a.id, a.action, a.meta_json, a.created_at, u.full_name AS actor_name, u.role AS actor_role
        FROM lead_activity a
        LEFT JOIN users u ON u.id = a.actor_id
        WHERE a.lead_id = ?
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT 200
    ");
    $stmt->bind_param('i', $lid);
    $stmt->execute();
    $activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT s.id, s.form_id, f.name AS form_name, s.submitted_by, u.full_name AS submitted_by_name, s.submitted_at, s.data_json
        FROM form_submissions s
        LEFT JOIN forms f ON f.id = s.form_id
        LEFT JOIN users u ON u.id = s.submitted_by
        WHERE s.lead_id = ?
        ORDER BY s.submitted_at DESC, s.id DESC
        LIMIT 50
    ");
    $stmt->bind_param('i', $lid);
    $stmt->execute();
    $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, field_id, file_path, uploaded_at
        FROM lead_files
        WHERE lead_id = ?
        ORDER BY uploaded_at DESC, id DESC
        LIMIT 100
    ");
    $stmt->bind_param('i', $lid);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$tables = [
    'leads' => $getColumns('leads'),
    'form_submissions' => $getColumns('form_submissions'),
    'lead_files' => $getColumns('lead_files'),
    'lead_activity' => $getColumns('lead_activity'),
];

?>
<?php $pageTitle = 'Lead Tracking'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <div class="h3 mb-1">Lead Tracking</div>
            <div class="text-muted small">Database + timeline view for one lead</div>
        </div>
        <a class="btn btn-light border" href="javascript:history.back()">Back</a>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Lead DB ID</label>
                    <input class="form-control form-control-sm" name="id" value="<?php echo htmlspecialchars($leadId > 0 ? (string)$leadId : ''); ?>" placeholder="e.g. 123">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Lead Code</label>
                    <input class="form-control form-control-sm" name="lead_id" value="<?php echo htmlspecialchars($leadCode); ?>" placeholder="e.g. TGS-XXXX">
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary btn-sm" type="submit">Load</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$lead): ?>
        <div class="alert alert-info border-0 shadow-sm">Enter a Lead DB ID or Lead Code to view tracking details.</div>
    <?php else: ?>
        <?php
            $submitted = (($lead['form_done'] ?? 'No') === 'Yes') ? 'Submitted' : 'Not Submitted';
            $submittedClass = (($lead['form_done'] ?? 'No') === 'Yes') ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
            $qa = (string)($lead['qa_status'] ?? 'Pending');
            $qaClass = 'bg-warning-subtle text-warning';
            if ($qa === 'Qualified' || $qa === 'Rectified') $qaClass = 'bg-success-subtle text-success';
            if ($qa === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
            if ($qa === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
        ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-light fw-semibold">Current State</div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="badge bg-secondary-subtle text-secondary border">Campaign: <?php echo htmlspecialchars($lead['campaign_name'] ?? ''); ?></span>
                            <span class="badge bg-secondary-subtle text-secondary border">Agent: <?php echo htmlspecialchars($lead['agent_name'] ?? ''); ?></span>
                            <span class="badge <?php echo $submittedClass; ?> border"><?php echo htmlspecialchars($submitted); ?></span>
                            <span class="badge <?php echo $qaClass; ?> border">QA: <?php echo htmlspecialchars($qa); ?></span>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <div class="text-muted small">Lead Code</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($lead['lead_id'] ?? (string)($lead['id'] ?? '')); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">DB ID</div>
                                <div class="fw-semibold"><?php echo (int)($lead['id'] ?? 0); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Created At</div>
                                <div class="fw-semibold"><?php echo !empty($lead['created_at']) ? htmlspecialchars((string)$lead['created_at']) : '—'; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Updated At</div>
                                <div class="fw-semibold"><?php echo !empty($lead['updated_at']) ? htmlspecialchars((string)$lead['updated_at']) : '—'; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Created By</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($createdByName !== '' ? $createdByName : '—'); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Last Updated By</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($updatedByName !== '' ? $updatedByName : '—'); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Submitted At</div>
                                <div class="fw-semibold"><?php echo !empty($lead['form_filled_time']) ? htmlspecialchars((string)$lead['form_filled_time']) : '—'; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">QA Updated At</div>
                                <div class="fw-semibold"><?php echo !empty($lead['qa_updated_at']) ? htmlspecialchars((string)$lead['qa_updated_at']) : '—'; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-light fw-semibold">Storage (Tables)</div>
                    <div class="card-body">
                        <div class="text-muted small mb-2">This is what the backend stores for tracking</div>
                        <div class="accordion" id="storageAccordion">
                            <?php $idx = 0; foreach ($tables as $tName => $cols): $idx++; ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="h_<?php echo $idx; ?>">
                                        <button class="accordion-button <?php echo $idx === 1 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#c_<?php echo $idx; ?>" aria-expanded="<?php echo $idx === 1 ? 'true' : 'false'; ?>" aria-controls="c_<?php echo $idx; ?>">
                                            <?php echo htmlspecialchars($tName); ?>
                                        </button>
                                    </h2>
                                    <div id="c_<?php echo $idx; ?>" class="accordion-collapse collapse <?php echo $idx === 1 ? 'show' : ''; ?>" aria-labelledby="h_<?php echo $idx; ?>" data-bs-parent="#storageAccordion">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-sm mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Column</th>
                                                            <th>Type</th>
                                                            <th>Null</th>
                                                            <th>Key</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($cols as $c): ?>
                                                            <tr>
                                                                <td class="fw-semibold"><?php echo htmlspecialchars($c['COLUMN_NAME'] ?? ''); ?></td>
                                                                <td class="text-muted small"><?php echo htmlspecialchars($c['COLUMN_TYPE'] ?? ''); ?></td>
                                                                <td class="text-muted small"><?php echo htmlspecialchars($c['IS_NULLABLE'] ?? ''); ?></td>
                                                                <td class="text-muted small"><?php echo htmlspecialchars($c['COLUMN_KEY'] ?? ''); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light fw-semibold">Timeline</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="fw-semibold mb-2">Lead Activity</div>
                                <?php if (empty($activity)): ?>
                                    <div class="text-muted">No lead_activity records yet.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Action</th>
                                                    <th>User</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($activity as $a): ?>
                                                    <tr>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($a['created_at'] ?? '')); ?></td>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($a['action'] ?? '')); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($a['actor_name'] ?? '')); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-lg-6">
                                <div class="fw-semibold mb-2">Form Submissions (History)</div>
                                <?php if (empty($submissions)): ?>
                                    <div class="text-muted">No form_submissions found for this lead.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Form</th>
                                                    <th>User</th>
                                                    <th class="text-end">Fields</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($submissions as $s): ?>
                                                    <?php
                                                        $data = json_decode((string)($s['data_json'] ?? ''), true);
                                                        $cnt = is_array($data) ? count($data) : 0;
                                                    ?>
                                                    <tr>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($s['submitted_at'] ?? '')); ?></td>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($s['form_name'] ?? ('#'.(string)($s['form_id'] ?? '')))); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($s['submitted_by_name'] ?? '')); ?></td>
                                                        <td class="text-end text-muted small"><?php echo (int)$cnt; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="fw-semibold mb-2">Uploaded Files (Custom Fields)</div>
                            <?php if (empty($files)): ?>
                                <div class="text-muted">No lead_files found for this lead.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Time</th>
                                                <th>Field</th>
                                                <th>File</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($files as $f): ?>
                                                <tr>
                                                    <td class="text-muted small"><?php echo htmlspecialchars((string)($f['uploaded_at'] ?? '')); ?></td>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars((string)($f['field_id'] ?? '')); ?></td>
                                                    <td><a class="small" href="<?php echo htmlspecialchars((string)($f['file_path'] ?? '')); ?>" target="_blank">Open</a></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light fw-semibold">Missing Points / Next Improvements</div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <li>No full “field-by-field diff” history for lead edits (only action + changed keys).</li>
                            <li>No recording version history (only latest recording_path is stored).</li>
                            <li>Leads table duplicates campaign_name and agent_name (can go stale vs master tables).</li>
                            <li>form_done stores Yes/No but submission truth should ideally come from form_submissions presence.</li>
                            <li>Foreign keys are not enforced between leads ↔ campaigns/users/forms (risk of orphan rows).</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

