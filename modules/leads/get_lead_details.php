<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$allowedRoles = ['admin', 'director', 'manager_director', 'operations_director', 'operations_manager', 'operations_agent', 'qa', 'qa_agent', 'qa_manager', 'qa_director', 'agent', 'form_filler', 'email_marketing_executive', 'email_marketing_agent', 'email_marketing_manager', 'email_marketing_director'];
requireRole($allowedRoles);
ensureDatabaseSchema();

$currentUser = getCurrentUser();

$leadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$leadCode = isset($_GET['lead_id']) ? trim($_GET['lead_id']) : '';
if ($leadId <= 0 && $leadCode === '') {
    http_response_code(400);
    echo 'Missing lead identifier';
    exit;
}

$lead = $leadId > 0 ? getLeadById($leadId) : getLeadByCode($leadCode);
if (!$lead) {
    http_response_code(404);
    echo 'Lead not found';
    exit;
}

$conn = getDbConnection();
$leadId = (int)($lead['id'] ?? $leadId);

if (($currentUser['role'] ?? '') === 'agent' && isset($lead['agent_id']) && (int)$lead['agent_id'] !== (int)$currentUser['id']) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$currentRole = (string)($currentUser['role'] ?? '');
if (hasRole(['form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director'])) {
    $leadCampaignId = (int)($lead['campaign_id'] ?? 0);
    $visible = getOpsVisibleCampaignIdsForUser((int)($currentUser['id'] ?? 0), $currentRole);
    if ($visible !== null && $leadCampaignId > 0 && !isset($visible[$leadCampaignId])) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}

$lead = enrichLeadRow($lead);

$activity = [];
$stmt = $conn->prepare("
    SELECT la.*, u.full_name AS user_name, u.role AS user_role
    FROM lead_activity la
    LEFT JOIN users u ON la.actor_id = u.id
    WHERE la.lead_id = ?
    ORDER BY la.created_at DESC
    LIMIT 50
");
if ($stmt) {
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$campaignId = (int)($lead['campaign_id'] ?? 0);

$format = $_GET['format'] ?? '';
$edit = !empty($_GET['edit']);
if ($edit) {
    $format = 'html';
}
if ($format === '') {
    $format = 'json';
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');

    $qaStatus = $lead['qa_status'] ?? '';
    $qualityStatus = 'pending';
    switch ($qaStatus) {
        case 'Qualified': $qualityStatus = 'approved'; break;
        case 'Disqualified': $qualityStatus = 'rejected'; break;
        case 'Rectified': $qualityStatus = 'approved'; break;
        case 'Duplicate': $qualityStatus = 'rejected'; break;
        default: $qualityStatus = 'pending';
    }

    echo json_encode([
        'id' => (int)($lead['id'] ?? 0),
        'lead_id' => $lead['lead_id'] ?? '',
        'campaign_id' => isset($lead['campaign_id']) ? (int)$lead['campaign_id'] : null,
        'agent_id' => isset($lead['agent_id']) ? (int)$lead['agent_id'] : null,
        'first_name' => $lead['first_name'] ?? '',
        'last_name' => $lead['last_name'] ?? '',
        'agent_name' => $lead['agent_name'] ?? '',
        'campaign_name' => $lead['campaign_name'] ?? '',
        'job_title' => $lead['job_title'] ?? '',
        'phone' => $lead['contact_phone'] ?? ($lead['phone'] ?? ''),
        'contact_phone' => $lead['contact_phone'] ?? ($lead['phone'] ?? ''),
        'email' => $lead['email'] ?? '',
        'linkedin_link' => $lead['linkedin_link'] ?? '',
        'qa_status' => $lead['qa_status'] ?? 'Pending',
        'client_delivery_status' => $lead['client_delivery_status'] ?? 'Pending',
        'quality_status' => $qualityStatus,
        'quality_comments' => $lead['qa_comment'] ?? '',
        'quality_client_comments' => $lead['qa_client_comment'] ?? '',
        'qa_reviewed_by' => isset($lead['qa_reviewed_by']) ? (int)$lead['qa_reviewed_by'] : null,
        'reviewer_name' => $lead['reviewer_name'] ?? '',
        'created_at' => $lead['created_at'] ?? null,
        'updated_at' => $lead['updated_at'] ?? null,
        'qa_updated_at' => $lead['qa_updated_at'] ?? null,
        'form_filled_time' => $lead['form_filled_time'] ?? null,
        'form_done' => $lead['form_done'] ?? '',
        'recording_path' => $lead['recording_path'] ?? '',
        'industry' => $lead['industry'] ?? '',
        'company_name' => $lead['company_name'] ?? '',
        'company_linkedin' => $lead['company_linkedin'] ?? '',
        'company_size' => $lead['company_size'] ?? '',
        'country' => $lead['country'] ?? '',
        'software_implementation_timeline' => $lead['software_implementation_timeline'] ?? ($lead['implementation_timeline'] ?? ''),
        'lead_comment' => $lead['lead_comment'] ?? '',
    ]);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$fullName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
$comment = $lead['lead_comment'] ?? '';

if ($edit) {
    $postTo = $_GET['post_to'] ?? 'my-leads.php';
    $campaignId = (int)($lead['campaign_id'] ?? 0);
    $leadDbId = (int)($lead['id'] ?? 0);
    $companySizeOptions = ['Myself Only','2-10','11-50','51-200','201-500','501-1,000','1,001-5,000','5,001-10,000','10,001+'];
    $countryOptions = ['United States','United Kingdom','Canada','Australia','Germany','France','India','Other'];
    $timelineOptions = ['0-3 Months','3-6 Months','6-9 Months','9-12 Months'];
    $submission = ($campaignId > 0 && $leadDbId > 0) ? getLatestFormSubmissionForLead($leadDbId, $campaignId) : null;
    $form = null;
    $formData = [];
    $formName = '';
    $formId = 0;
    if ($submission && (int)($submission['form_id'] ?? 0) > 0) {
        $formId = (int)$submission['form_id'];
        $formData = is_array($submission['data'] ?? null) ? $submission['data'] : [];
        $form = getFormById($formId);
        $formName = (string)($form['name'] ?? '');
    } else {
        $form = ($campaignId > 0) ? getFormForCampaign($campaignId) : null;
        $formId = (int)($form['form_id'] ?? 0);
        $formName = (string)($form['name'] ?? '');
        $formData = [];
    }

    $norm = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        return trim($s, '_');
    };
    $skipNorms = array_fill_keys(array_map($norm, [
        'first_name','firstname','first','given_name',
        'last_name','lastname','last','surname','family_name',
        'full_name','name','contact_name',
        'job_title','jobtitle','title','designation',
        'email','email_address','emailaddress',
        'linkedin','linkedin_link','linkedin_url','linkedin_profile','linkedinprofile',
        'phone','contact_phone','phone_number','mobile','mobile_number','contact_number',
        'company','company_name','companyname','organization','organisation','account_name',
        'company_linkedin','company_linkedin_link','company_linkedin_url','companylinkedin','companylinkedinurl',
        'company_size','employee_size','employee_sizes','employees','headcount',
        'country','country_name','location',
        'industry',
        'implementation_timeline','software_implementation_timeline','timeline',
        'lead_comment','comment','notes',
    ]), true);
    $hideFilled = hasRole(['form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);
    $leadVal = function($v): string {
        if ($v === null) return '';
        if (is_array($v)) return '';
        return trim((string)$v);
    };
    $pick = function(array $aliases, string $fallback = '') use ($formData, $leadVal): string {
        foreach ($aliases as $k) {
            if (array_key_exists($k, $formData)) {
                $v = $leadVal($formData[$k]);
                if ($v !== '') return $v;
            }
        }
        $fb = trim((string)$fallback);
        return $fb;
    };
    $isFilled = function($v) use ($leadVal): bool {
        return $leadVal($v) !== '';
    };
    ?>
    <form method="post" action="<?php echo htmlspecialchars($postTo); ?>" enctype="multipart/form-data" class="p-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="edit_lead">
        <input type="hidden" name="lead_id" value="<?php echo (int)$lead['id']; ?>">
        <input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>">
        <input type="hidden" name="form_id" value="<?php echo $formId; ?>">

        <?php if ($hideFilled): ?>
        <style>
            .filled-field { display: none; }
        </style>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($fullName ?: 'Lead'); ?></div>
                        <div class="text-muted small">Lead ID: <?php echo htmlspecialchars($lead['lead_id'] ?? (string)($lead['id'] ?? '')); ?></div>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars($lead['campaign_name'] ?? ''); ?></span>
                </div>
            </div>

            <?php if ($hideFilled): ?>
            <div class="col-12">
                <div class="d-flex justify-content-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="toggleFilledFields">
                        <label class="form-check-label small text-muted" for="toggleFilledFields">Show already filled fields</label>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($hideFilled): ?>
            <div class="col-12">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">Email Status</div>
                        <div class="row g-3">
                            <?php
                                $emailStatuses = ['Pending','Sent','Delivered','Opened','Bounced','Unsubscribed','No Response'];
                                $emailStatusVal = (string)($lead['email_status'] ?? '');
                                if ($emailStatusVal === '') $emailStatusVal = 'Pending';
                            ?>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Email Status</label>
                                <select class="form-select form-select-sm" name="email_status">
                                    <?php foreach ($emailStatuses as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $emailStatusVal === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small text-muted">Email Status Comment</label>
                                <input class="form-control form-control-sm" name="email_status_comment" value="<?php echo htmlspecialchars((string)($lead['email_status_comment'] ?? '')); ?>" placeholder="e.g. bounced reason, opened note">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="col-12">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">Lead Details</div>
                        <div class="row g-3">
                            <?php
                                $first = $pick(['first_name','firstname','first','given_name'], (string)($lead['first_name'] ?? ''));
                                $last = $pick(['last_name','lastname','last','surname','family_name'], (string)($lead['last_name'] ?? ''));
                                $job = $pick(['job_title','jobtitle','title','designation'], (string)($lead['job_title'] ?? ''));
                                $email = $pick(['email','email_address','emailaddress','work_email'], (string)($lead['email'] ?? ''));
                                $li = $pick(['linkedin_link','linkedin_url','linkedin_profile','linkedinprofile'], (string)($lead['linkedin_link'] ?? ''));
                                $phone = $pick(['contact_phone','phone','phone_number','mobile','mobile_number','contact_number'], (string)($lead['contact_phone'] ?? ''));
                                $company = $pick(['company_name','company','companyname','organization','organisation','account_name'], (string)($lead['company_name'] ?? ''));
                                $companyLi = $pick(['company_linkedin','company_linkedin_url','companylinkedin','companylinkedinurl'], (string)($lead['company_linkedin'] ?? ''));
                                $industry = $pick(['industry'], (string)($lead['industry'] ?? ''));
                            ?>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($first)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">First Name</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($first); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($last)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Last Name</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($last); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($job)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Job Title</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($job); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($email)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Email</label>
                                <input class="form-control form-control-sm" type="email" value="<?php echo htmlspecialchars($email); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($li)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">LinkedIn Link</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($li); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($phone)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Contact Phone</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($phone); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($company)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Company Name</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($company); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($companyLi)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Company LinkedIn</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($companyLi); ?>" readonly>
                            </div>
                            <div class="col-md-6 <?php echo ($hideFilled && $isFilled($industry)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Industry</label>
                                <input class="form-control form-control-sm" value="<?php echo htmlspecialchars($industry); ?>" readonly>
                            </div>
                            <div class="col-12 <?php echo ($hideFilled && $isFilled($comment)) ? 'filled-field' : ''; ?>">
                                <label class="form-label small text-muted">Comment</label>
                                <textarea class="form-control form-control-sm" name="lead_comment" rows="3"><?php echo htmlspecialchars($comment); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">Call Recording</div>
                        <?php if (!empty($lead['recording_path'])): ?>
                            <?php
                                $rp = (string)($lead['recording_path'] ?? '');
                                $recUrl = $rp;
                                if ($rp !== '' && !preg_match('/^https?:\\/\\//i', $rp) && preg_match('/^uploads\\//i', $rp)) {
                                    $recUrl = rtrim(appBasePath(), '/') . '/' . $rp;
                                }
                            ?>
                            <audio controls class="w-100 mb-2" style="height: 34px;">
                                <source src="<?php echo htmlspecialchars($recUrl); ?>" type="audio/mpeg">
                            </audio>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="remove_recording" name="remove_recording" value="1">
                                <label class="form-check-label small" for="remove_recording">Remove existing recording</label>
                            </div>
                        <?php endif; ?>
                        <label class="form-label small text-muted">Replace Recording (optional)</label>
                        <input type="file" class="form-control form-control-sm" name="recording" accept="audio/*">
                    </div>
                </div>
            </div>

            <?php if ($form && !empty($form['schema']['fields']) && is_array($form['schema']['fields'])): ?>
                <?php
                    $customFields = [];
                    $seenNorms = [];
                    foreach ($form['schema']['fields'] as $ff) {
                        if (array_key_exists('visible', $ff) && empty($ff['visible'])) continue;
                        $k = (string)($ff['key'] ?? '');
                        if ($k === '') continue;
                        $lbl = (string)($ff['label'] ?? $k);
                        $kn = $norm($k);
                        $ln = $norm($lbl);
                        if (isset($skipNorms[$kn]) || isset($skipNorms[$ln])) continue;
                        if ($kn !== '' && isset($seenNorms[$kn])) continue;
                        if ($ln !== '' && isset($seenNorms[$ln])) continue;
                        if ($kn !== '') $seenNorms[$kn] = true;
                        if ($ln !== '') $seenNorms[$ln] = true;
                        $customFields[] = $ff;
                    }
                ?>
                <?php if (!empty($customFields)): ?>
                <div class="col-12">
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold">Campaign Form</div>
                                <div class="small text-muted"><?php echo htmlspecialchars($formName); ?></div>
                            </div>
                            <div class="row g-3">
                                <?php foreach ($customFields as $f): ?>
                                    <?php
                                        $key = (string)($f['key'] ?? '');
                                        if ($key === '') continue;
                                        $label = (string)($f['label'] ?? $key);
                                        $type = (string)($f['type'] ?? 'text');
                                        $required = !empty($f['required']);
                                        $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
                                        $val = $formData[$key] ?? null;
                                    ?>
                                    <?php
                                        $valStr = '';
                                        if (is_array($val)) $valStr = implode(',', array_filter(array_map('strval', $val)));
                                        else $valStr = trim((string)($val ?? ''));
                                        $filledClass = ($hideFilled && $valStr !== '') ? 'filled-field' : '';
                                    ?>
                                    <div class="col-md-6 <?php echo $filledClass; ?>">
                                        <label class="form-label small text-muted">
                                            <?php echo htmlspecialchars($label); ?>
                                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                        </label>
                                        <?php if ($type === 'textarea'): ?>
                                            <textarea class="form-control form-control-sm" name="cf[<?php echo htmlspecialchars($key); ?>]" rows="3" <?php echo $required ? 'required' : ''; ?>><?php echo htmlspecialchars((string)($val ?? '')); ?></textarea>
                                        <?php elseif ($type === 'select'): ?>
                                            <select class="form-select form-select-sm" name="cf[<?php echo htmlspecialchars($key); ?>]" <?php echo $required ? 'required' : ''; ?>>
                                                <option value="">Select</option>
                                                <?php foreach ($opts as $o): ?>
                                                    <?php $oStr = (string)$o; ?>
                                                    <option value="<?php echo htmlspecialchars($oStr); ?>" <?php echo ((string)($val ?? '') === $oStr) ? 'selected' : ''; ?>><?php echo htmlspecialchars($oStr); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($type === 'radio'): ?>
                                            <div class="d-flex flex-column gap-1">
                                                <?php foreach ($opts as $o): ?>
                                                    <?php $oStr = (string)$o; $id = 'cf_'.$key.'_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $oStr); ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" id="<?php echo htmlspecialchars($id); ?>" name="cf[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars($oStr); ?>" <?php echo ((string)($val ?? '') === $oStr) ? 'checked' : ''; ?> <?php echo $required ? 'required' : ''; ?>>
                                                        <label class="form-check-label small" for="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($oStr); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($type === 'checkbox'): ?>
                                            <?php $arrVal = is_array($val) ? $val : (is_string($val) ? array_filter(array_map('trim', explode(',', $val))) : []); ?>
                                            <div class="d-flex flex-column gap-1">
                                                <?php foreach ($opts as $o): ?>
                                                    <?php $oStr = (string)$o; $id = 'cf_'.$key.'_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $oStr); ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars($id); ?>" name="cf[<?php echo htmlspecialchars($key); ?>][]" value="<?php echo htmlspecialchars($oStr); ?>" <?php echo in_array($oStr, $arrVal, true) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label small" for="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($oStr); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($type === 'file_upload'): ?>
                                            <?php if (is_string($val) && $val !== ''): ?>
                                                <div class="mb-1">
                                                    <a href="<?php echo htmlspecialchars($val); ?>" target="_blank" class="small">View uploaded file</a>
                                                </div>
                                                <input type="hidden" name="existing_cf[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                            <?php endif; ?>
                                            <input type="file" class="form-control form-control-sm" name="cffile[<?php echo htmlspecialchars($key); ?>]" <?php echo $required ? 'required' : ''; ?>>
                                        <?php else: ?>
                                            <?php
                                                $inputType = $type;
                                                if (!in_array($inputType, ['text','email','tel','url','number','date'], true)) $inputType = 'text';
                                            ?>
                                            <input class="form-control form-control-sm" type="<?php echo htmlspecialchars($inputType); ?>" name="cf[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars((string)($val ?? '')); ?>" <?php echo $required ? 'required' : ''; ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-muted small mt-2">Saving will update the form submission for this lead.</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
    <?php if ($hideFilled): ?>
    <script>
        (function(){
            const t = document.getElementById('toggleFilledFields');
            if (!t) return;
            t.addEventListener('change', () => {
                document.querySelectorAll('.filled-field').forEach(el => {
                    el.style.display = t.checked ? '' : 'none';
                });
            });
        })();
    </script>
    <?php endif; ?>
    <?php
    exit;
}

?>
<?php
$qaStatus = (string)($lead['qa_status'] ?? 'Pending');
$qaClass = 'bg-warning-subtle text-warning';
if ($qaStatus === 'Qualified' || $qaStatus === 'Rectified') $qaClass = 'bg-success-subtle text-success';
if ($qaStatus === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
if ($qaStatus === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
$formDone = (string)($lead['form_done'] ?? 'No');
$formClass = ($formDone === 'Yes') ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
$createdAt = !empty($lead['created_at']) ? date('Y-m-d H:i', strtotime($lead['created_at'])) : '—';
$formFilledAt = !empty($lead['form_filled_time']) ? date('Y-m-d H:i', strtotime($lead['form_filled_time'])) : '—';
$qaUpdatedAt = !empty($lead['qa_updated_at']) ? date('Y-m-d H:i', strtotime($lead['qa_updated_at'])) : '—';
$companyWebsite = '';
$campaignId = (int)($lead['campaign_id'] ?? 0);
$leadDbId = (int)($lead['id'] ?? 0);
$submission = ($campaignId > 0 && $leadDbId > 0) ? getLatestFormSubmissionForLead($leadDbId, $campaignId) : null;
$formData = is_array($submission['data'] ?? null) ? $submission['data'] : [];
$linkedin = trim((string)(extractSubmissionValue($formData, ['linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_url','prospect_linkedin_profile']) ?? ''));
$companyLinkedin = trim((string)(extractSubmissionValue($formData, ['company_linkedin','company_linkedin_url','company_linkedin_link','companylinkedin','companylinkedinurl']) ?? ''));
$companyWebsite = trim((string)(extractSubmissionValue($formData, ['company_website','website','domain','company_domain']) ?? ''));
if ($companyWebsite === '') $companyWebsite = trim((string)($lead['company_domain'] ?? ''));
?>
<div class="p-3">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h5 mb-1"><?php echo htmlspecialchars($fullName ?: 'Lead'); ?></div>
            <div class="text-muted small">
                Lead ID: <?php echo htmlspecialchars($lead['lead_id'] ?? (string)($lead['id'] ?? '')); ?>
                <span class="mx-1">•</span>
                Created: <?php echo htmlspecialchars($createdAt); ?>
            </div>
        </div>
        <div class="text-end">
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars($lead['campaign_name'] ?? ''); ?></span>
                <span class="badge <?php echo $formClass; ?> border">Submitted: <?php echo htmlspecialchars(($formDone === 'Yes') ? 'Submitted' : 'Not Submitted'); ?></span>
                <span class="badge <?php echo $qaClass; ?> border">QA: <?php echo htmlspecialchars($qaStatus ?: 'Pending'); ?></span>
            </div>
            <?php if (!empty($lead['agent_name'])): ?>
                <div class="small text-muted mt-1">Agent: <?php echo htmlspecialchars($lead['agent_name']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Contact</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="text-muted small">Job Title</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['job_title'] ?? '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Email</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['email'] ?? '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Phone</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['contact_phone'] ?? '—'); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">LinkedIn</div>
                            <?php if ($linkedin !== ''): ?>
                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($linkedin); ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-linkedin me-1"></i>Open Profile
                                </a>
                            <?php else: ?>
                                <div class="fw-semibold">—</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Company</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="text-muted small">Company Name</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['company_name'] ?? '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Company Size</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['company_size'] ?? '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Country</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['country'] ?? '—'); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Industry</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['industry'] ?? '—'); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Company LinkedIn</div>
                            <?php if ($companyLinkedin !== ''): ?>
                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($companyLinkedin); ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-linkedin me-1"></i>Open Page
                                </a>
                            <?php else: ?>
                                <div class="fw-semibold">—</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Company Website</div>
                            <?php if ($companyWebsite !== ''): ?>
                                <?php echo renderUrlPreviewCard($companyWebsite, 'Company Website', 'Open Website'); ?>
                            <?php else: ?>
                                <div class="fw-semibold">—</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Lead Tracking</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="text-muted small">Submitted At</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($formFilledAt); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">QA Updated</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($qaUpdatedAt); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Reviewed By</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($lead['reviewer_name'] ?? '—'); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">QA Comment</div>
                            <div class="fw-semibold"><?php echo nl2br(htmlspecialchars(($lead['qa_comment'] ?? '') !== '' ? (string)$lead['qa_comment'] : '—')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Notes & Media</div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="text-muted small">Comment</div>
                        <div class="fw-semibold"><?php echo nl2br(htmlspecialchars($comment !== '' ? $comment : '—')); ?></div>
                    </div>
                    <?php if (!empty($lead['recording_path'])): ?>
                        <div class="text-muted small mb-1">Recording</div>
                        <audio controls class="w-100" src="<?php echo htmlspecialchars($lead['recording_path']); ?>"></audio>
                    <?php else: ?>
                        <div class="text-muted small">Recording</div>
                        <div class="fw-semibold">—</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-12">
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-light fw-semibold">Lead Activity Timeline (Client Notes)</div>
                <div class="card-body p-0">
                    <?php if (empty($activity)): ?>
                        <div class="p-3 text-muted small text-center">No activity recorded yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Date</th>
                                        <th>Action</th>
                                        <th>Details</th>
                                        <th class="pe-3">By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activity as $a): ?>
                                        <?php 
                                            $action = (string)($a['action'] ?? '');
                                            $meta = !empty($a['meta_json']) ? json_decode((string)$a['meta_json'], true) : [];
                                            if (!is_array($meta)) $meta = [];

                                            $detailsParts = [];
                                            if ($action === 'qa_updated') {
                                                $prev = (string)($meta['qa_prev_status'] ?? '—');
                                                $cur = (string)($meta['qa_status'] ?? '—');
                                                $detailsParts[] = 'QA: ' . $prev . ' → ' . $cur;

                                                $dPrev = (string)($meta['client_delivery_prev_status'] ?? '—');
                                                $dCur = (string)($meta['client_delivery_status'] ?? '—');
                                                if ($dPrev !== '—' || $dCur !== '—') $detailsParts[] = 'Delivery: ' . $dPrev . ' → ' . $dCur;

                                                $qaNote = trim((string)($meta['qa_comment'] ?? ''));
                                                if ($qaNote !== '') $detailsParts[] = 'QA Note: ' . $qaNote;

                                                $clientNote = trim((string)($meta['qa_client_comment'] ?? ''));
                                                if ($clientNote !== '') $detailsParts[] = 'Client Note: ' . $clientNote;
                                            } elseif ($action === 'lead_updated') {
                                                $fields = $meta['fields'] ?? [];
                                                if (is_array($fields) && !empty($fields)) {
                                                    $detailsParts[] = 'Updated: ' . implode(', ', array_map('strval', $fields));
                                                }
                                            } elseif ($action === 'form_submission_saved') {
                                                $fid = (int)($meta['form_id'] ?? 0);
                                                $detailsParts[] = $fid > 0 ? ('Form submission saved (Form #' . $fid . ')') : 'Form submission saved';
                                            } elseif ($action === 'recording_replaced') {
                                                $detailsParts[] = 'Recording replaced';
                                            } elseif ($action === 'recording_removed') {
                                                $detailsParts[] = 'Recording removed';
                                            }

                                            $note = trim((string)($meta['note'] ?? ''));
                                            if ($note !== '') $detailsParts[] = 'Note: ' . $note;

                                            $tag = trim((string)($meta['tag'] ?? ''));
                                            if ($tag !== '') $detailsParts[] = 'Tag: ' . $tag;

                                            $tags = $meta['tags'] ?? null;
                                            if (is_array($tags) && !empty($tags)) $detailsParts[] = 'Tags: ' . implode(', ', array_map('strval', $tags));

                                            $details = !empty($detailsParts) ? implode(' · ', $detailsParts) : '—';
                                        ?>
                                        <tr>
                                            <td class="ps-3 small text-muted"><?php echo date('M j, Y H:i', strtotime((string)$a['created_at'])); ?></td>
                                            <td class="small fw-semibold"><?php echo str_replace('_', ' ', ucfirst((string)$a['action'])); ?></td>
                                            <td class="small text-muted"><?php echo nl2br(htmlspecialchars($details)); ?></td>
                                            <td class="pe-3 small text-muted"><?php echo htmlspecialchars($a['user_name'] ?? 'System'); ?> (<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($a['user_role'] ?? '')))); ?>)</td>
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
</div>
