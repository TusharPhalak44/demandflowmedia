<?php
/**
 * My Leads (Agent View)
 * 
 * Provides agents with a focused view of their own lead generation history, 
 * performance status, and editing capabilities.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Ensure user has appropriate role
requireRole(['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director']);
$user = getCurrentUser();

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
        } elseif (!isAdmin() && (int)($lead['agent_id'] ?? 0) !== (int)$user['id']) {
            $editMessage = 'You can only edit your own leads.';
            $editMessageClass = 'danger';
        } else {
            $update = [
                'first_name' => $_POST['first_name'] ?? null,
                'last_name' => $_POST['last_name'] ?? null,
                'job_title' => $_POST['job_title'] ?? null,
                'email' => $_POST['email'] ?? null,
                'linkedin_link' => $_POST['linkedin_link'] ?? null,
                'contact_phone' => $_POST['contact_phone'] ?? null,
                'industry' => $_POST['industry'] ?? null,
                'company_linkedin' => $_POST['company_linkedin'] ?? null,
                'company_name' => $_POST['company_name'] ?? null,
                'company_size' => $_POST['company_size'] ?? null,
                'country' => $_POST['country'] ?? null,
                'lead_comment' => $_POST['lead_comment'] ?? null,
                // Reset QA-related fields
                'qa_status' => 'Pending',
                'qa_comment' => null,
                'qa_reviewed_by' => null,
                'qa_updated_at' => null,
                'updated_by' => (int)($user['id'] ?? 0),
            ];

            $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : (int)($lead['campaign_id'] ?? 0);
            $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
            if ($campaignId > 0 && $formId > 0) {
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
                $form = getFormById($formId) ?: getFormForCampaign($campaignId);
                $fields = (array)($form['schema']['fields'] ?? []);
                $selectMap = getSelectOptionsByFormSchema((array)($form['schema'] ?? []), ['industry','employee_size','company_size','country','software_implementation_timeline']);
                if (!empty($selectMap['industry']) && !valueInAllowedOptions((string)($update['industry'] ?? ''), $selectMap['industry'])) {
                    $editMessage = 'Invalid Industry. Allowed: ' . implode(' | ', $selectMap['industry']);
                    $editMessageClass = 'danger';
                }
                $empOpts = $selectMap['employee_size'] ?? ($selectMap['company_size'] ?? null);
                if (empty($editMessage) && is_array($empOpts) && !valueInAllowedOptions((string)($update['company_size'] ?? ''), $empOpts)) {
                    $editMessage = 'Invalid Employee Size. Allowed: ' . implode(' | ', $empOpts);
                    $editMessageClass = 'danger';
                }
                if (empty($editMessage) && !empty($selectMap['country']) && !valueInAllowedOptions((string)($update['country'] ?? ''), $selectMap['country'])) {
                    $editMessage = 'Invalid Country. Allowed: ' . implode(' | ', $selectMap['country']);
                    $editMessageClass = 'danger';
                }
                $cf = $_POST['cf'] ?? [];
                $cfFiles = $_FILES['cffile'] ?? [];
                $data = [];
                foreach ($fields as $f) {
                    if (is_array($f) && array_key_exists('visible', $f) && empty($f['visible'])) continue;
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
                            $typeLower = strtolower((string)$type);
                            $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
                            $val = $cf[$key];
                            if (!empty($opts) && in_array($typeLower, ['select','radio'], true) && is_scalar($val) && trim((string)$val) !== '') {
                                if (!valueInAllowedOptions((string)$val, $opts)) {
                                    $editMessage = 'Invalid value for ' . (string)($f['label'] ?? $key);
                                    $editMessageClass = 'danger';
                                    break;
                                }
                            }
                            if (!empty($opts) && $typeLower === 'checkbox' && is_array($val) && !empty($val)) {
                                foreach ($val as $vv) {
                                    if (!valueInAllowedOptions((string)$vv, $opts)) {
                                        $editMessage = 'Invalid value for ' . (string)($f['label'] ?? $key);
                                        $editMessageClass = 'danger';
                                        break 2;
                                    }
                                }
                            }
                            $data[$key] = $cf[$key];
                        }
                    }
                }
                $companyWebsite = trim((string)($_POST['company_website'] ?? ''));
                if ($companyWebsite !== '') {
                    $data['company_website'] = $companyWebsite;
                }
                $timelineAnswer = trim((string)($_POST['software_implementation_timeline'] ?? ''));
                if ($timelineAnswer !== '') {
                    if (!empty($selectMap['software_implementation_timeline']) && !valueInAllowedOptions($timelineAnswer, $selectMap['software_implementation_timeline'])) {
                        $editMessage = 'Invalid Implementation Timeline. Allowed: ' . implode(' | ', $selectMap['software_implementation_timeline']);
                        $editMessageClass = 'danger';
                    } else {
                        $data['software_implementation_timeline'] = $timelineAnswer;
                    }
                }
                $li = trim((string)($_POST['linkedin_link'] ?? ''));
                if ($li !== '') $data['linkedin_link'] = $li;
                $cli = trim((string)($_POST['company_linkedin'] ?? ''));
                if ($cli !== '') $data['company_linkedin'] = $cli;
                if (!empty($data)) {
                    saveFormSubmission((int)($form['form_id'] ?? $formId), $campaignId, $leadId, (int)$user['id'], $data);
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

// Get filter parameters
$campaignId = $_GET['campaign_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$qaStatus = $_GET['qa_status'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(5, (int)$_GET['per_page']) : 10;
$perPage = min(500, $perPage);

// Build filters array
$filters = [];
if (!isAdmin()) { $filters['agent_id'] = $user['id']; }
if (!empty($campaignId)) $filters['campaign_id'] = $campaignId;
if (!empty($dateFrom)) $filters['date_from'] = $dateFrom;
if (!empty($dateTo)) $filters['date_to'] = $dateTo;
if (!empty($qaStatus)) $filters['qa_status'] = $qaStatus;

// Get leads
$leadsData = getLeads($filters, $perPage, $page);
$leads = $leadsData['leads'];
$total = $leadsData['total'];
$totalPages = $leadsData['totalPages'];
$campaigns = getCampaigns();

?>
<?php $pageTitle = 'My Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

    <div class="container-fluid px-0">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0">My Leads</h2>
            <a href="agent.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i> New Lead
            </a>
        </div>

        <?php if (!empty($editMessage)): ?>
            <div class="alert alert-<?php echo $editMessageClass; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                <?php echo htmlspecialchars($editMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="campaign_id" class="form-label small text-muted">Campaign</label>
                        <select class="form-select form-select-sm" id="campaign_id" name="campaign_id">
                            <option value="">All Campaigns</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($campaignId == $c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="qa_status" class="form-label small text-muted">QA Status</label>
                        <select class="form-select form-select-sm" id="qa_status" name="qa_status">
                            <option value="">All Status</option>
                            <option value="Pending" <?php echo ($qaStatus == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="Qualified" <?php echo ($qaStatus == 'Qualified') ? 'selected' : ''; ?>>Qualified</option>
                            <option value="Disqualified" <?php echo ($qaStatus == 'Disqualified') ? 'selected' : ''; ?>>Disqualified</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from" class="form-label small text-muted">From</label>
                        <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to" class="form-label small text-muted">To</label>
                        <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="col-md-1">
                        <label for="per_page" class="form-label small text-muted">Rows</label>
                        <select class="form-select form-select-sm" id="per_page" name="per_page">
                            <?php foreach ([10,25,50,100,500] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Apply</button>
                        <a href="my-leads.php" class="btn btn-light btn-sm flex-grow-1 border">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Leads Table -->
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">SR No.</th>
                            <th class="ps-3">Date</th>
                            <th>Lead</th>
                            <th>Contact</th>
                            <th>Company</th>
                            <th>Campaign</th>
                            <th>QA</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No leads found.</td>
                            </tr>
                        <?php else: ?>
                            <?php $serialStart = (($page - 1) * $perPage) + 1; $i = 0; ?>
                            <?php foreach ($leads as $lead): $i++; ?>
                                <?php 
                                    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                                    $qaStatus = $lead['qa_status'] ?? 'Pending';
                                    $qaClass = 'bg-warning-subtle text-warning';
                                    if ($qaStatus === 'Qualified') $qaClass = 'bg-success-subtle text-success';
                                    if ($qaStatus === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
                                ?>
                                <tr>
                                    <td class="ps-3 small text-muted"><?php echo $serialStart + $i - 1; ?></td>
                                    <td class="ps-3 small text-muted"><?php echo date('M d, Y', strtotime($lead['created_at'])); ?></td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string)($lead['lead_id'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?php echo htmlspecialchars((string)($lead['email'] ?? '')); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string)($lead['contact_phone'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?php echo htmlspecialchars($lead['company_name'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($lead['job_title'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold">
                                            <span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars($lead['campaign_name'] ?? 'N/A'); ?></span>
                                        </div>
                                        <?php if (!empty($lead['client_code'])): ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars((string)($lead['client_code'] ?? '')); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars($qaStatus); ?></span></td>
                                    <td class="text-end pe-3">
                                        <div class="btn-group btn-group-sm">
                                            <a href="lead-details.php?id=<?php echo $lead['id']; ?>" class="btn btn-light border" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($qaStatus === 'Pending' || $qaStatus === 'Rework Needed' || isAdmin()): ?>
                                                <button class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#editLeadModal" data-lead-id="<?php echo (int)$lead['id']; ?>" title="Edit Lead">
                                                    <i class="bi bi-pencil"></i>
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
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
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
    </div>

    <!-- Edit Lead Modal (Simplified for Agents) -->
    <div class="modal fade" id="editLeadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="editLeadBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('editLeadModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const leadId = button.getAttribute('data-lead-id');
        fetch('get_lead_details?id=' + encodeURIComponent(leadId) + '&edit=1&format=html', { credentials: 'same-origin' })
            .then(r => r.text())
            .then(html => { document.getElementById('editLeadBody').innerHTML = html; })
            .catch(() => { document.getElementById('editLeadBody').innerHTML = '<div class="text-danger p-3">Unable to load lead details.</div>'; });
    });
    </script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
