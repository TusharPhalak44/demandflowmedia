<?php
/**
 * Manage Leads
 * 
 * Provides filters, normalized data table, pagination, export, and lead editing/QA updates.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Allow QA, Admin, Client and Vendor to manage
requireRole(['admin','director','manager_director','qa','qa_agent','qa_manager','qa_director','operations_director','operations_manager','operations_agent','client_admin','client_sdr','vendor_admin','vendor_user','agent','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);

$user = getCurrentUser();
$isVendor = isVendor();
$isClient = isClient();
$isEmailDept = hasRole(['email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);
$isPrivInternal = isAdmin() || hasRole(['director','manager_director','operations_director','operations_manager']) || isQA();
$canDeleteLead = isAdmin() || hasRole(['director','manager_director','operations_director','operations_manager']);

// CSRF token generation
ensureCsrfToken();

// Handle lead edit submission
$editMessage = '';
$editMessageClass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_lead') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $editMessage = 'Invalid request token.';
        $editMessageClass = 'danger';
    } else {
        $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
        $lead = $leadId > 0 ? getLeadById($leadId) : null;
        if (!$lead) {
            $editMessage = 'Lead not found.';
            $editMessageClass = 'danger';
        } else {
            if (!$isPrivInternal) {
                $ownerId = (int)($lead['agent_id'] ?? 0);
                if ($ownerId !== (int)($user['id'] ?? 0)) {
                    $editMessage = 'Access denied.';
                    $editMessageClass = 'danger';
                }
            }
        }
        if ($editMessage === '') {
            $update = [
                'qa_status' => 'Pending',
                'qa_comment' => null,
                'qa_reviewed_by' => null,
                'qa_updated_at' => null,
                'updated_by' => (int)($user['id'] ?? 0),
            ];
            if (array_key_exists('lead_comment', $_POST)) {
                $update['lead_comment'] = (string)($_POST['lead_comment'] ?? '');
            }
            $camp = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
            if ($camp > 0) { $update['campaign_id'] = $camp; }

            $campaignForForm = $camp > 0 ? $camp : (int)($lead['campaign_id'] ?? 0);
            $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
            if ($campaignForForm > 0 && $formId > 0) {
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
                    'industry',
                    'lead_comment','comment','notes',
                ]), true);
                $form = getFormById($formId) ?: getFormForCampaign($campaignForForm);
                $fields = (array)($form['schema']['fields'] ?? []);
                $cf = $_POST['cf'] ?? [];
                $cfFiles = $_FILES['cffile'] ?? [];
                $data = [];
                foreach ($fields as $f) {
                    $key = (string)($f['key'] ?? '');
                    if ($key === '') continue;
                    if (isset($skipNorms[$norm($key)])) continue;
                    $type = (string)($f['type'] ?? 'text');
                    if ($type === 'file_upload') {
                        if (isset($cfFiles['name'][$key]) && (string)$cfFiles['name'][$key] !== '') {
                            $file = [
                                'name' => $cfFiles['name'][$key] ?? '',
                                'type' => $cfFiles['type'][$key] ?? '',
                                'tmp_name' => $cfFiles['tmp_name'][$key] ?? '',
                                'error' => $cfFiles['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                                'size' => $cfFiles['size'][$key] ?? 0,
                            ];
                            $p = saveLeadFieldFile($leadId, $key, $file);
                            if ($p) $data[$key] = $p;
                        } else {
                            if (isset($_POST['existing_cf'][$key]) && (string)$_POST['existing_cf'][$key] !== '') {
                                $data[$key] = (string)$_POST['existing_cf'][$key];
                            }
                        }
                    } else {
                        if (array_key_exists($key, $cf)) {
                            $data[$key] = $cf[$key];
                        }
                    }
                }
                if (!empty($data)) {
                    saveFormSubmission((int)($form['form_id'] ?? $formId), $campaignForForm, $leadId, (int)($user['id'] ?? 0), $data);
                    $update['form_done'] = 'Yes';
                    $update['form_filled_time'] = date('Y-m-d H:i:s');
                    logLeadActivity($leadId, (int)($user['id'] ?? 0), 'form_submission_saved', ['form_id' => (int)($form['form_id'] ?? $formId)]);
                }
            }
            
            // Handle recording removal or upload
            $recLogAction = null;
            $recLogMeta = [];
            $removeRec = !empty($_POST['remove_recording']);
            if (isset($_FILES['recording']) && $_FILES['recording']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploaded = uploadRecording($_FILES['recording']);
                if ($uploaded !== false) {
                    $update['recording_path'] = $uploaded;
                    $recLogAction = 'recording_replaced';
                    $recLogMeta = ['path' => $uploaded];
                } else {
                    $editMessage = 'Invalid recording file (type or size).';
                    $editMessageClass = 'danger';
                }
            } elseif ($removeRec) {
                $update['recording_path'] = null;
                $recLogAction = 'recording_removed';
            }
            
            if (empty($editMessage)) {
                if (updateLead($leadId, $update)) {
                    if ($recLogAction) {
                        logLeadActivity($leadId, (int)($user['id'] ?? 0), $recLogAction, $recLogMeta);
                    }
                    logLeadActivity($leadId, (int)($user['id'] ?? 0), 'lead_updated', ['fields' => array_keys($update)]);
                    $editMessage = 'Lead updated successfully. QA status reset to Pending.';
                    $editMessageClass = 'success';
                } else {
                    $editMessage = 'Failed to update lead.';
                    $editMessageClass = 'danger';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_lead') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $editMessage = 'Invalid request token.';
        $editMessageClass = 'danger';
    } elseif (!$canDeleteLead) {
        $editMessage = 'Access denied.';
        $editMessageClass = 'danger';
    } else {
        $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
        $confirm = strtoupper(trim((string)($_POST['confirm_text'] ?? '')));
        if ($confirm !== 'DELETE') {
            $editMessage = 'Type DELETE to confirm.';
            $editMessageClass = 'danger';
        } elseif ($leadId <= 0) {
            $editMessage = 'Lead not found.';
            $editMessageClass = 'danger';
        } else {
            $deleteFiles = !empty($_POST['delete_files']);
            if (deleteSingleLead($leadId, $deleteFiles)) {
                $editMessage = 'Lead deleted.';
                $editMessageClass = 'success';
            } else {
                $editMessage = 'Failed to delete lead.';
                $editMessageClass = 'danger';
            }
        }
    }
}

// Filters
$campaignId = $_GET['campaign_id'] ?? '';
$agentId = $_GET['agent_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$qaStatus = $_GET['qa_status'] ?? '';
$formFilled = $_GET['form_filled'] ?? '';
$search = $_GET['search'] ?? '';
$mode = (string)($_GET['mode'] ?? '');
$isVendorMode = ($mode === 'vendor');
$view = $_GET['view'] ?? 'table';
$view = in_array($view, ['table','cards'], true) ? $view : 'table';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 25;
$perPage = min(500, $perPage);

$filters = [];
if (!empty($campaignId)) { $filters['campaign_id'] = (int)$campaignId; }
if (!empty($agentId)) { $filters['agent_id'] = (int)$agentId; }
if (!empty($dateFrom)) { $filters['date_from'] = $dateFrom; }
if (!empty($dateTo)) { $filters['date_to'] = $dateTo; }
if (!empty($qaStatus)) { $filters['qa_status'] = $qaStatus; }
if (!empty($formFilled)) { $filters['form_filled'] = $formFilled; }
if (!empty($search)) { $filters['search'] = $search; }

if ($isVendorMode && !$isVendor && !$isClient) {
    $filters['vendor_only'] = true;
}

if ($isVendor) {
    $filters['vendor_id'] = (int)($user['vendor_id'] ?? 0);
    if ($user['role'] === 'vendor_user') {
        $conn = getDbConnection();
        $assigned = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $assigned[] = (int)$r['campaign_id'];
        $stmt->close();
        $filters['campaign_ids'] = $assigned;
    }
} elseif ($isClient) {
    $filters['client_id'] = (int)($user['client_id'] ?? 0);
    if ($user['role'] === 'client_sdr') {
        $conn = getDbConnection();
        $assigned = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $assigned[] = (int)$r['campaign_id'];
        $stmt->close();
        $filters['campaign_ids'] = $assigned;
    }
} else {
    $privForThisPage = $isPrivInternal || ($isVendorMode && $isEmailDept);
    if (!$privForThisPage) {
        $filters['agent_id'] = (int)($user['id'] ?? 0);
    }
}

$leadsData = getLeads($filters, $perPage, $page);
$leads = $leadsData['leads'];
$total = $leadsData['total'];
$totalPages = $leadsData['totalPages'];

// Dropdown data
$campaigns = [];
if ($isVendor || $isClient || $isPrivInternal) {
    $campaigns = getAllCampaignsBasic();
} else {
    $scopeMap = getTeamVisibleCampaignIdsForUser((int)($user['id'] ?? 0), (isQA() ? getUserAssignedCampaignIds((int)($user['id'] ?? 0), ['qa_campaign_assignments']) : getUserAssignedCampaignIds((int)($user['id'] ?? 0))));
    $campaigns = $scopeMap === null ? getAllCampaignsBasic() : getCampaignsBasicByIds(array_keys($scopeMap), false);
}
$agents = getAgents();
?>
<?php $pageTitle = $isVendorMode ? 'Vendor Leads' : 'All Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h3 mb-0"><?php echo $isVendorMode ? 'Vendor Leads' : 'All Leads'; ?></h2>
        <div class="d-flex gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="View toggle">
                <?php $qsBase = $_GET; unset($qsBase['page']); ?>
                <a class="btn btn-outline-primary <?php echo $view === 'table' ? 'active' : ''; ?>" href="list?<?php echo htmlspecialchars(http_build_query(array_merge($qsBase, ['view' => 'table']))); ?>">
                    <i class="bi bi-table me-1"></i>Table
                </a>
                <a class="btn btn-outline-primary <?php echo $view === 'cards' ? 'active' : ''; ?>" href="list?<?php echo htmlspecialchars(http_build_query(array_merge($qsBase, ['view' => 'cards']))); ?>">
                    <i class="bi bi-grid-3x3-gap me-1"></i>Cards
                </a>
            </div>
            <?php $canExport = hasRole(['admin','director','manager_director','qa','qa_agent','qa_manager','qa_director','email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler','operations_director','sales_director']); ?>
            <?php if ($canExport): ?>
                <form method="post" action="export.php" target="_blank">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                    <input type="hidden" name="agent_id" value="<?php echo htmlspecialchars($agentId); ?>">
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                    <input type="hidden" name="qa_status" value="<?php echo htmlspecialchars($qaStatus); ?>">
                    <input type="hidden" name="form_filled" value="<?php echo htmlspecialchars($formFilled); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php
                        $mustSelectCampaign = !(isAdmin() || isDirector() || hasRole(['manager_director']));
                        $disableExport = $mustSelectCampaign && ((int)$campaignId <= 0);
                        $exportTitle = '';
                        if ($disableExport) $exportTitle = 'Select a campaign to export';
                        elseif ((int)$campaignId <= 0) $exportTitle = 'Exports current filtered leads (without custom fields)';
                    ?>
                    <button type="submit" name="export" class="btn btn-outline-primary btn-sm" <?php echo $disableExport ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars($exportTitle); ?>">
                        <i class="bi bi-download me-1"></i> Export CSV
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($editMessage)): ?>
        <div class="alert alert-<?php echo $editMessageClass; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <?php echo htmlspecialchars($editMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
        <form method="get" action="leads-edit.php" class="row g-3 align-items-end">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            <div class="col-xl-3 col-lg-4 col-md-6">
                <label for="search" class="form-label">Quick Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, company...">
                </div>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="campaign_id" class="form-label">Campaign</label>
                <select class="form-select form-select-sm" id="campaign_id" name="campaign_id">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaigns as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($campaignId == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="agent_id" class="form-label">Agent</label>
                <select class="form-select form-select-sm" id="agent_id" name="agent_id">
                    <option value="">All Agents</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php echo ($agentId == $a['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <label for="qa_status" class="form-label">QA Status</label>
                <select class="form-select form-select-sm" id="qa_status" name="qa_status">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo ($qaStatus == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="Qualified" <?php echo ($qaStatus == 'Qualified') ? 'selected' : ''; ?>>Qualified</option>
                    <option value="Disqualified" <?php echo ($qaStatus == 'Disqualified') ? 'selected' : ''; ?>>Disqualified</option>
                    <option value="Rectified" <?php echo ($qaStatus == 'Rectified') ? 'selected' : ''; ?>>Rectified</option>
                    <option value="Rework Needed" <?php echo ($qaStatus == 'Rework Needed') ? 'selected' : ''; ?>>Rework Needed</option>
                    <option value="Duplicate" <?php echo ($qaStatus == 'Duplicate') ? 'selected' : ''; ?>>Duplicate</option>
                </select>
            </div>
            <div class="col-xl-3 col-lg-4 col-md-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
                <a href="leads-edit.php" class="btn btn-light btn-sm flex-grow-1">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
            <!-- Collapsible Advanced Filters -->
            <div class="col-12">
                <button class="btn btn-link btn-sm p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters">
                    <i class="bi bi-plus-circle me-1"></i> Advanced Filters
                </button>
                <div class="collapse mt-3" id="advancedFilters">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="date_from" class="form-label small">Date From</label>
                            <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="date_to" class="form-label small">Date To</label>
                            <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="form_filled" class="form-label small">Form Status</label>
                            <select class="form-select form-select-sm" id="form_filled" name="form_filled">
                                <option value="">All</option>
                                <option value="Yes" <?php echo ($formFilled == 'Yes') ? 'selected' : ''; ?>>Filled</option>
                                <option value="No" <?php echo ($formFilled == 'No') ? 'selected' : ''; ?>>Not Filled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="per_page" class="form-label small">Rows per page</label>
                            <select class="form-select form-select-sm" id="per_page" name="per_page">
                                <option value="25" <?php echo ($perPage == 25) ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo ($perPage == 50) ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo ($perPage == 100) ? 'selected' : ''; ?>>100</option>
                                <option value="500" <?php echo ($perPage == 500) ? 'selected' : ''; ?>>500</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        </div>
    </div>

    <?php if ($view === 'cards'): ?>
        <div class="row g-3">
            <?php if (empty($leads)): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center py-5 text-muted">No leads found matching your criteria.</div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($leads as $lead): ?>
                    <?php
                        $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                        $rec = $lead['recording_path'] ?? '';
                        $qaStatusRow = $lead['qa_status'] ?? 'Pending';
                        $qaClass = 'bg-warning-subtle text-warning';
                        if ($qaStatusRow === 'Qualified') $qaClass = 'bg-success-subtle text-success';
                        if ($qaStatusRow === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
                        if ($qaStatusRow === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
                        if ($qaStatusRow === 'Rework Needed') $qaClass = 'bg-info-subtle text-info';
                        if ($qaStatusRow === 'Reopened') $qaClass = 'bg-warning-subtle text-warning';
                    ?>
                    <div class="col-xl-4 col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="min-w-0">
                                        <div class="fw-bold text-truncate"><?php echo htmlspecialchars($name !== '' ? $name : '—'); ?></div>
                                        <div class="text-muted small text-truncate"><?php echo htmlspecialchars($lead['email'] ?? ''); ?></div>
                                        <div class="text-muted small text-truncate"><?php echo htmlspecialchars($lead['contact_phone'] ?? ''); ?></div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars($qaStatusRow); ?></span>
                                        <?php if (!empty($lead['reviewer_name'])): ?>
                                            <div class="text-muted small mt-1" style="font-size:0.7rem;">by <?php echo htmlspecialchars($lead['reviewer_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr class="my-3">

                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="text-muted small">Company</div>
                                        <div class="fw-semibold small text-truncate"><?php echo htmlspecialchars($lead['company_name'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Title</div>
                                        <div class="small text-truncate"><?php echo htmlspecialchars($lead['job_title'] ?? ''); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Campaign</div>
                                        <div class="small text-truncate"><?php echo htmlspecialchars($lead['campaign_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Agent</div>
                                        <div class="small text-truncate"><?php echo htmlspecialchars($lead['agent_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-12">
                                        <div class="text-muted small">Form</div>
                                        <?php if (($lead['form_done'] ?? 'No') === 'Yes'): ?>
                                            <span class="badge bg-success-subtle text-success border">Filled</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border">Pending</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-white border-0 pt-0">
                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                    <?php if (!empty($rec)): ?>
                                        <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#recordingModal" data-lead-name="<?php echo htmlspecialchars($name); ?>" data-recording="<?php echo htmlspecialchars($rec); ?>" title="Play Recording">
                                            <i class="bi bi-play-fill"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#qaModal"
                                        data-lead-id="<?php echo (int)$lead['id']; ?>"
                                        data-lead-name="<?php echo htmlspecialchars($name); ?>"
                                        data-qa-status="<?php echo htmlspecialchars($qaStatusRow); ?>"
                                        data-qa-comment="<?php echo htmlspecialchars($lead['qa_comment'] ?? ''); ?>"
                                        data-qa-client-comment="<?php echo htmlspecialchars($lead['qa_client_comment'] ?? ''); ?>"
                                        data-client-delivery-status="<?php echo htmlspecialchars($lead['client_delivery_status'] ?? 'Pending'); ?>"
                                        data-recording="<?php echo htmlspecialchars($rec); ?>" title="Update QA">
                                        <i class="bi bi-shield-check"></i>
                                    </button>
                                    <?php $canEditRow = $isPrivInternal || ((int)($lead['agent_id'] ?? 0) === (int)($user['id'] ?? 0)); ?>
                                    <?php if ($canEditRow): ?>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editLeadModal" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Edit Lead">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-light border" href="view?id=<?php echo (int)$lead['id']; ?>" title="View Full Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($canDeleteLead): ?>
                                        <button type="button" class="btn btn-sm btn-danger delete-lead" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Delete Lead">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="mt-3 d-flex align-items-center justify-content-between">
            <div class="small text-muted">Showing <?php echo count($leads); ?> of <?php echo number_format($total); ?> leads</div>
            <?php if ($totalPages > 1): ?>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Prev</a>
                        </li>
                        <?php
                            $window = 2;
                            $startPage = max(1, $page - $window);
                            $endPage = min($totalPages, $page + $window);
                            for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-container bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-3">SR No.</th>
                        <th>Lead Info</th>
                        <th>Company</th>
                        <th>Campaign/Agent</th>
                        <th>Form</th>
                        <th>Quality</th>
                        <th class="text-end pe-3 sticky-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leads)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">No leads found matching your criteria.</td>
                        </tr>
                    <?php else: ?>
                        <?php $serialStart = (($page - 1) * $perPage) + 1; ?>
                        <?php foreach ($leads as $i => $lead): ?>
                            <?php 
                                $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                                $rec = $lead['recording_path'] ?? '';
                                $canEditRow = $isPrivInternal || ((int)($lead['agent_id'] ?? 0) === (int)($user['id'] ?? 0));
                            ?>
                            <tr>
                                <td class="ps-3 text-muted small"><?php echo $serialStart + $i; ?></td>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($lead['email'] ?? ''); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($lead['contact_phone'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?php echo htmlspecialchars($lead['company_name'] ?? ''); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($lead['country'] ?? ''); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($lead['job_title'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <div class="small"><span class="text-muted">Camp:</span> <?php echo htmlspecialchars($lead['campaign_name'] ?? 'N/A'); ?></div>
                                    <div class="small"><span class="text-muted">Agent:</span> <?php echo htmlspecialchars($lead['agent_name'] ?? 'N/A'); ?></div>
                                </td>
                                <td>
                                    <?php if (($lead['form_done'] ?? 'No') === 'Yes'): ?>
                                        <span class="badge bg-success-subtle text-success border">Filled</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $qaStatus = $lead['qa_status'] ?? 'Pending';
                                    $qaClass = 'bg-warning-subtle text-warning';
                                    if ($qaStatus === 'Qualified') $qaClass = 'bg-success-subtle text-success';
                                    if ($qaStatus === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
                                    if ($qaStatus === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
                                    ?>
                                    <span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars($qaStatus); ?></span>
                                    <?php if (!empty($lead['reviewer_name'])): ?>
                                        <div class="small text-muted mt-1" style="font-size: 0.7rem;">by <?php echo htmlspecialchars($lead['reviewer_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3 sticky-actions">
                                    <div class="btn-group btn-group-sm">
                                        <?php if (!empty($rec)): ?>
                                            <button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#recordingModal" data-lead-name="<?php echo htmlspecialchars($name); ?>" data-recording="<?php echo htmlspecialchars($rec); ?>" title="Play Recording">
                                                <i class="bi bi-play-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qaModal" 
                                            data-lead-id="<?php echo (int)$lead['id']; ?>" 
                                            data-lead-name="<?php echo htmlspecialchars($name); ?>" 
                                            data-qa-status="<?php echo htmlspecialchars($qaStatus); ?>" 
                                            data-qa-comment="<?php echo htmlspecialchars($lead['qa_comment'] ?? ''); ?>" 
                                            data-qa-client-comment="<?php echo htmlspecialchars($lead['qa_client_comment'] ?? ''); ?>" 
                                            data-client-delivery-status="<?php echo htmlspecialchars($lead['client_delivery_status'] ?? 'Pending'); ?>" 
                                            data-recording="<?php echo htmlspecialchars($rec); ?>" title="Update QA">
                                            <i class="bi bi-shield-check"></i>
                                        </button>
                                        <?php if ($canEditRow): ?>
                                            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editLeadModal" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Edit Lead">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a class="btn btn-light border" href="view?id=<?php echo (int)$lead['id']; ?>" title="View Full Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($canDeleteLead): ?>
                                            <button type="button" class="btn btn-danger delete-lead" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Delete Lead">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="p-3 border-top d-flex align-items-center justify-content-between">
            <div class="small text-muted">Showing <?php echo count($leads); ?> of <?php echo number_format($total); ?> leads</div>
            <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Prev</a>
                    </li>
                    <?php
                        $window = 2;
                        $startPage = max(1, $page - $window);
                        $endPage = min($totalPages, $page + $window);
                        for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">Next</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<form id="deleteLeadForm" method="post" action="list" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="delete_lead">
    <input type="hidden" name="lead_id" id="deleteLeadId" value="">
    <input type="hidden" name="confirm_text" id="deleteConfirmText" value="">
    <input type="hidden" name="delete_files" id="deleteFiles" value="0">
</form>

<!-- Modals -->
<!-- Recording Player Modal -->
<div class="modal fade" id="recordingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Recording Player</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <h6 id="recLeadName" class="mb-3 fw-bold text-primary"></h6>
                <audio id="recAudio" controls class="w-100 mb-2">Your browser does not support the audio element.</audio>
            </div>
        </div>
    </div>
</div>

<!-- QA Update Modal -->
<div class="modal fade" id="qaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post" action="../qa/action">
                <div class="modal-header">
                    <h5 class="modal-title">Update Lead Quality</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    <input type="hidden" id="qa_lead_id" name="lead_id">
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted text-uppercase fw-bold">Lead Name</label>
                        <div id="qa_lead_name" class="fw-bold h6"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="qa_status_select" class="form-label small text-muted text-uppercase fw-bold">Quality Status</label>
                        <select class="form-select" id="qa_status_select" name="qa_status" required>
                            <option value="Pending">Pending</option>
                            <option value="Qualified">Qualified</option>
                            <option value="Disqualified">Disqualified</option>
                            <option value="Rectified">Rectified</option>
                            <option value="Rework Needed">Rework Needed</option>
                            <option value="Duplicate">Duplicate</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="client_delivery_status_select" class="form-label small text-muted text-uppercase fw-bold">Client Delivery Status</label>
                        <select class="form-select" id="client_delivery_status_select" name="client_delivery_status" required>
                            <?php foreach (getClientDeliveryStatuses() as $v): ?>
                                <option value="<?php echo htmlspecialchars($v); ?>"><?php echo htmlspecialchars($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="qa_comment_internal" class="form-label small text-muted text-uppercase fw-bold">Internal Comments</label>
                        <textarea class="form-control" id="qa_comment_internal" name="qa_comment_internal" rows="3" placeholder="Internal QA notes..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="qa_comment_client" class="form-label small text-muted text-uppercase fw-bold">Client Comments (visible to client)</label>
                        <textarea class="form-control" id="qa_comment_client" name="qa_comment_client" rows="3" placeholder="Client-facing notes..."></textarea>
                    </div>
                    
                    <div id="qaRecordingContainer" class="p-3 bg-light rounded-3 mb-3" style="display:none;">
                        <label class="form-label small text-muted text-uppercase fw-bold d-block">Recording Audit</label>
                        <audio id="qaAudioPlayer" controls class="w-100 mb-2"></audio>
                        <a id="qaDownloadLink" class="btn btn-sm btn-outline-secondary w-100" href="#" download>
                            <i class="bi bi-download me-1"></i> Download File
                        </a>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Lead Modal -->
<div class="modal fade" id="editLeadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title">Edit Lead</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="editLeadBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Recording Modal Logic
    const recordingModal = document.getElementById('recordingModal');
    if (recordingModal) {
        recordingModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const name = button.getAttribute('data-lead-name');
            const rec = button.getAttribute('data-recording');
            document.getElementById('recLeadName').textContent = name;
            document.getElementById('recAudio').src = rec;
        });
        recordingModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('recAudio').pause();
            document.getElementById('recAudio').src = '';
        });
    }

    // QA Modal Logic
    const qaModal = document.getElementById('qaModal');
    if (qaModal) {
        qaModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const leadId = button.getAttribute('data-lead-id');
            const name = button.getAttribute('data-lead-name');
            const qaStatus = button.getAttribute('data-qa-status');
            const qaComment = button.getAttribute('data-qa-comment');
            const qaClientComment = button.getAttribute('data-qa-client-comment');
            const clientDeliveryStatus = button.getAttribute('data-client-delivery-status');
            const rec = button.getAttribute('data-recording');

            document.getElementById('qa_lead_id').value = leadId;
            document.getElementById('qa_lead_name').textContent = name;
            document.getElementById('qa_status_select').value = qaStatus || 'Pending';
            document.getElementById('client_delivery_status_select').value = clientDeliveryStatus || 'Pending';
            document.getElementById('qa_comment_internal').value = qaComment || '';
            document.getElementById('qa_comment_client').value = qaClientComment || '';

            const container = document.getElementById('qaRecordingContainer');
            const player = document.getElementById('qaAudioPlayer');
            const download = document.getElementById('qaDownloadLink');

            if (rec && rec !== 'null') {
                container.style.display = 'block';
                player.src = rec;
                download.href = rec;
            } else {
                container.style.display = 'none';
                player.src = '';
            }
        });
        qaModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('qaAudioPlayer').pause();
            document.getElementById('qaAudioPlayer').src = '';
        });
    }

    const editLeadModalEl = document.getElementById('editLeadModal');
    if (editLeadModalEl) {
        editLeadModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const leadId = button.getAttribute('data-lead-id');
            document.getElementById('editLeadBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>';
            fetch('details?id=' + leadId + '&edit=1&format=html&post_to=list')
                .then(r => r.text())
                .then(html => { document.getElementById('editLeadBody').innerHTML = html; })
                .catch(() => { document.getElementById('editLeadBody').innerHTML = '<div class="alert alert-danger m-3">Error loading edit form.</div>'; });
        });
    }

    document.querySelectorAll('.delete-lead').forEach(btn => {
        btn.addEventListener('click', function() {
            const leadId = this.getAttribute('data-lead-id');
            if (!leadId) return;
            if (!confirm('Are you sure you want to delete this lead?')) return;
            const text = prompt('Type DELETE to confirm.');
            if ((text || '').trim().toUpperCase() !== 'DELETE') return;
            const delFiles = confirm('Also delete uploaded lead files/recording from server?');
            document.getElementById('deleteLeadId').value = leadId;
            document.getElementById('deleteConfirmText').value = 'DELETE';
            document.getElementById('deleteFiles').value = delFiles ? '1' : '0';
            document.getElementById('deleteLeadForm').submit();
        });
    });
});
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
