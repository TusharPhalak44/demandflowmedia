<?php
/**
 * Export Leads
 * 
 * Allows exporting leads data in CSV format
 */

// Include required files
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Ensure user is logged in and has appropriate role
requireRole(['admin','director','manager_director','qa','qa_director','qa_manager','qa_agent','email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler']);
// Ensure CSRF token is available for form submission
ensureCsrfToken();
$error = '';
$exportMode = (isQA() && !(isAdmin() || isDirector() || hasRole('manager_director'))) ? 'qa' : 'full';

// Current user + visibility scope
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();
$canSeeAllCampaigns = isAdmin() || isDirector() || isSalesDirector() || isSalesManager() || hasRole(['manager_director','operations_director']);
$visibleCampaignIds = null;
if (!$canSeeAllCampaigns) {
    $assigned = getUserAssignedCampaignIds($userId);
    $assigned = getTeamVisibleCampaignIdsForUser($userId, $assigned);
    $visibleCampaignIds = $assigned === null ? null : array_keys($assigned);
}

// Campaigns dropdown (scoped)
$campaigns = [];
if ($visibleCampaignIds !== null) {
    if (!empty($visibleCampaignIds)) {
        $in = implode(',', array_fill(0, count($visibleCampaignIds), '?'));
        $types = str_repeat('i', count($visibleCampaignIds));
        $stmt = $conn->prepare("
            SELECT c.id, c.name, d.code, d.status
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE c.active = 1 AND c.id IN ($in)
            ORDER BY d.created_at DESC, c.name ASC
        ");
        $stmt->bind_param($types, ...$visibleCampaignIds);
        $stmt->execute();
        $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} else {
    $rs = $conn->query("
        SELECT c.id, c.name, d.code, d.status
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.active = 1
        ORDER BY d.created_at DESC, c.name ASC
    ");
    $campaigns = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

// Agents dropdown (admin and QA only)
$agents = [];
if (isAdmin() || isQA()) {
    $agents = getAgents();
}

// Process export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    // Validate CSRF token
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token.';
    } else {
    // Get filter parameters (campaign is mandatory for export)
    $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
    $agentId = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : 0;
    $dateFrom = isset($_POST['date_from']) ? $_POST['date_from'] : '';
    $dateTo = isset($_POST['date_to']) ? $_POST['date_to'] : '';
    $qaStatus = isset($_POST['qa_status']) ? $_POST['qa_status'] : '';
    $formFilled = isset($_POST['form_filled']) ? $_POST['form_filled'] : '';
    $formDone = isset($_POST['form_done']) ? $_POST['form_done'] : '';
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
    
    $allowAllCampaignsExport = isAdmin() || isDirector() || hasRole(['manager_director']);
    if ($exportMode === 'qa') $allowAllCampaignsExport = false;
    if ($campaignId <= 0 && !$allowAllCampaignsExport) {
        $error = 'Please select a campaign to export.';
    } elseif ($campaignId > 0 && $visibleCampaignIds !== null && !in_array($campaignId, $visibleCampaignIds, true)) {
        $error = 'Not allowed for this campaign.';
    }
    // Build filters array
    $filters = [];
    if ($campaignId > 0) $filters['campaign_id'] = $campaignId;
    if ($agentId > 0) {
        $filters['agent_id'] = $agentId;
    }
    if (!empty($dateFrom)) {
        $filters['date_from'] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $filters['date_to'] = $dateTo;
    }
    if (!empty($qaStatus)) {
        $filters['qa_status'] = $qaStatus;
    }
    // Prefer normalized form_done; fall back to legacy form_filled
    if (!empty($formDone)) {
        $filters['form_done'] = $formDone;
    } elseif (!empty($formFilled)) {
        $filters['form_filled'] = $formFilled;
    }
    if (!empty($search)) {
        $filters['search'] = $search;
    }
    
    if (empty($error)) {
        ensureLeadsTrackingColumns();

        $campMeta = [];
        if ($campaignId > 0) {
            $stmt = $conn->prepare("
                SELECT c.id, c.name, d.code, d.status, d.campaign_type, d.delivery_format, d.pacing_type, d.pacing_count,
                       d.start_date, d.end_date, d.total_leads,
                       d.client_id, cl.client_code, cl.name AS client_name
                FROM campaigns c
                JOIN campaign_details d ON d.campaign_id = c.id
                LEFT JOIN clients cl ON cl.id = d.client_id
                WHERE c.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $campMeta = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (empty($campMeta)) {
                $error = 'Campaign not found.';
            }
        }
    }

    if (empty($error)) {
        @set_time_limit(0);
        while (ob_get_level() > 0) { @ob_end_clean(); }

        $chunkSize = 2000;

        $schemaKeys = [];
        $schemaKeyLabels = [];
        if ($campaignId > 0) {
            $form = getFormForCampaign($campaignId);
            $schemaFields = (array)(($form['schema']['fields'] ?? []) ?: []);
            foreach ($schemaFields as $f) {
                if (!is_array($f)) continue;
                $key = trim((string)($f['key'] ?? ''));
                if ($key === '') continue;
                $schemaKeys[] = $key;
                $schemaKeyLabels[$key] = (string)($f['label'] ?? '');
            }
        }

        $fetchLatestSubmissions = function(array $leadIds) use ($conn, $campaignId): array {
            if ($campaignId <= 0) return [];
            $leadIds = array_values(array_filter(array_map('intval', $leadIds), fn($v) => $v > 0));
            if (empty($leadIds)) return [];
            $in = implode(',', array_fill(0, count($leadIds), '?'));
            $types = 'i' . str_repeat('i', count($leadIds));
            $sql = "
                SELECT fs.lead_id, fs.submitted_at, fs.data_json
                FROM form_submissions fs
                JOIN (
                    SELECT lead_id, MAX(id) AS max_id
                    FROM form_submissions
                    WHERE campaign_id = ? AND lead_id IN ($in)
                    GROUP BY lead_id
                ) x ON x.max_id = fs.id
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return [];
            $bind = array_merge([$campaignId], $leadIds);
            $stmt->bind_param($types, ...$bind);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            $out = [];
            foreach ($rows as $r) {
                $lid = (int)($r['lead_id'] ?? 0);
                $data = json_decode((string)($r['data_json'] ?? ''), true);
                if (!is_array($data)) $data = [];
                $out[$lid] = ['submitted_at' => $r['submitted_at'] ?? null, 'data' => $data];
            }
            return $out;
        };

        $qaPreferredFormKeys = [
            'first_name',
            'last_name',
            'job_title',
            'email',
            'company_website',
            'phone',
            'prospect_linkedin_link',
            'company_name',
            'industry',
            'employee_size',
            'country',
            'company_linkedin_link',
            '0_to_3_months3_to_6_months6_to_9_months9_to_12_months',
        ];

        $dynamicKeys = $schemaKeys;
        if ($exportMode === 'qa' && $campaignId > 0) {
            $dynamicKeys = $qaPreferredFormKeys;
        } elseif ($campaignId > 0) {
            $extraKeys = [];
            $firstPage = getLeads($filters, $chunkSize, 1);
            $totalPages = (int)($firstPage['totalPages'] ?? 1);
            for ($p = 1; $p <= $totalPages; $p++) {
                $chunk = $p === 1 ? ($firstPage['leads'] ?? []) : (getLeads($filters, $chunkSize, $p)['leads'] ?? []);
                $leadIds = array_values(array_filter(array_map(fn($l) => (int)($l['id'] ?? 0), $chunk), fn($v) => $v > 0));
                $subs = $fetchLatestSubmissions($leadIds);
                foreach ($subs as $s) {
                    $data = $s['data'] ?? [];
                    if (!is_array($data)) continue;
                    foreach ($data as $k => $_v) {
                        $kk = (string)$k;
                        if ($kk === '') continue;
                        if (!in_array($kk, $schemaKeys, true)) $extraKeys[$kk] = true;
                    }
                }
            }
            if (!empty($extraKeys)) {
                $extras = array_keys($extraKeys);
                sort($extras, SORT_NATURAL | SORT_FLAG_CASE);
                $dynamicKeys = array_merge($dynamicKeys, $extras);
            }
        } else {
            $firstPage = getLeads($filters, $chunkSize, 1);
            $totalPages = (int)($firstPage['totalPages'] ?? 1);
        }

        $baseCols = [];
        if ($exportMode === 'qa') {
            $baseCols = [
                'qa_status','qa_comment','qa_client_comment','client_delivery_status','qa_reviewer_name','qa_updated_at',
                'form_done','form_filled_time','form_submitted_at',
                'created_at','updated_at',
            ];
        } else {
            $baseCols = [
                'lead_id',
                'campaign_code','campaign_name','campaign_status','campaign_type','campaign_start_date','campaign_end_date',
                'client_code',
                'agent_name',
                'created_by_name','updated_by_name',
                'first_name','last_name','job_title','email','company_website','company_domain','linkedin_link','contact_phone','industry','company_linkedin','company_name','company_size','country','software_implementation_timeline',
                'lead_source','lead_comment','recording_path','ip_address',
                'qa_status','qa_comment','qa_client_comment','client_delivery_status','qa_reviewed_by','qa_reviewer_name','qa_updated_at',
                'email_status','email_status_comment','email_status_updated_by','email_status_updated_at',
                'form_done','form_filled_time','form_submitted_at',
                'created_at','updated_at',
            ];
        }

        $headers = [];
        if ($exportMode === 'qa') {
            foreach ($dynamicKeys as $k) {
                $h = 'cf_' . $k;
                $lbl = trim((string)($schemaKeyLabels[$k] ?? ''));
                if ($lbl !== '') $h .= ' (' . $lbl . ')';
                $headers[] = $h;
            }
            foreach ($baseCols as $c) $headers[] = $c;
        } else {
            $headers = $baseCols;
            foreach ($dynamicKeys as $k) {
                $h = 'cf_' . $k;
                $lbl = trim((string)($schemaKeyLabels[$k] ?? ''));
                if ($lbl !== '') $h .= ' (' . $lbl . ')';
                $headers[] = $h;
            }
        }

        $fnPrefix = $exportMode === 'qa' ? 'leads_qa_export' : 'leads_export';
        $fnCamp = $campaignId > 0 ? ('_campaign_' . $campaignId) : '_all_campaigns';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $fnPrefix . $fnCamp . '_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);

        $cell = function($v): string {
            if (is_array($v)) {
                $flat = array_map('strval', $v);
                $flat = array_map('trim', $flat);
                $flat = array_values(array_filter($flat, fn($x) => $x !== ''));
                return implode(', ', $flat);
            }
            if ($v === null) return '';
            return (string)$v;
        };

        for ($p = 1; $p <= $totalPages; $p++) {
            $chunk = $p === 1 ? ($firstPage['leads'] ?? []) : (getLeads($filters, $chunkSize, $p)['leads'] ?? []);
            if (empty($chunk)) continue;

            $leadIds = [];
            $vendorIds = [];
            $userIds = [];
            foreach ($chunk as $l) {
                $lid = (int)($l['id'] ?? 0);
                if ($lid > 0) $leadIds[] = $lid;
                $vid = (int)($l['vendor_id'] ?? 0);
                if ($vid > 0) $vendorIds[$vid] = true;
                foreach (['created_by','updated_by','qa_reviewed_by','assigned_to_id','assigned_to_user','agent_id'] as $k) {
                    $u = (int)($l[$k] ?? 0);
                    if ($u > 0) $userIds[$u] = true;
                }
            }

            $vendorMap = [];
            if (!empty($vendorIds)) {
                $ids = array_keys($vendorIds);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $stmt = $conn->prepare("SELECT id, vendor_code, name FROM vendors WHERE id IN ($in)");
                if ($stmt) {
                    $stmt->bind_param($types, ...$ids);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $stmt->close();
                    foreach ($rows as $r) $vendorMap[(int)$r['id']] = $r;
                }
            }

            $userMap = [];
            if (!empty($userIds)) {
                $ids = array_keys($userIds);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE id IN ($in)");
                if ($stmt) {
                    $stmt->bind_param($types, ...$ids);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $stmt->close();
                    foreach ($rows as $r) $userMap[(int)$r['id']] = $r;
                }
            }

            $formDataByLead = $fetchLatestSubmissions($leadIds);

            $campMetaMap = [];
            if ($campaignId <= 0) {
                $cids = array_values(array_unique(array_values(array_filter(array_map(fn($l) => (int)($l['campaign_id'] ?? 0), $chunk), fn($v) => $v > 0))));
                if (!empty($cids)) {
                    $in = implode(',', array_fill(0, count($cids), '?'));
                    $types = str_repeat('i', count($cids));
                    $stmt = $conn->prepare("
                        SELECT c.id, c.name, d.code, d.status, d.campaign_type, d.start_date, d.end_date,
                               d.client_id, cl.client_code, cl.name AS client_name
                        FROM campaigns c
                        JOIN campaign_details d ON d.campaign_id = c.id
                        LEFT JOIN clients cl ON cl.id = d.client_id
                        WHERE c.id IN ($in)
                    ");
                    if ($stmt) {
                        $stmt->bind_param($types, ...$cids);
                        $stmt->execute();
                        $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                        $stmt->close();
                        foreach ($tmp as $r) $campMetaMap[(int)($r['id'] ?? 0)] = $r;
                    }
                }
            }

            foreach ($chunk as $l) {
                $lid = (int)($l['id'] ?? 0);
                $vid = (int)($l['vendor_id'] ?? 0);
                $vendor = $vid > 0 ? ($vendorMap[$vid] ?? null) : null;
                $createdBy = (int)($l['created_by'] ?? 0);
                $updatedBy = (int)($l['updated_by'] ?? 0);
                $reviewerId = (int)($l['qa_reviewed_by'] ?? 0);
                $assignedTo = (int)($l['assigned_to_user'] ?? ($l['assigned_to_id'] ?? 0));

                $row = [];
                $row['lead_db_id'] = $lid;
                $row['lead_id'] = (string)($l['lead_id'] ?? '');
                $cid = (int)($l['campaign_id'] ?? $campaignId);
                $cm = $campaignId > 0 ? $campMeta : ($campMetaMap[$cid] ?? []);
                $row['campaign_id'] = $cid;
                $row['campaign_code'] = (string)($l['campaign_code'] ?? ($cm['code'] ?? ''));
                $row['campaign_name'] = (string)($l['campaign_name'] ?? ($cm['name'] ?? ''));
                $row['campaign_status'] = (string)($cm['status'] ?? '');
                $row['campaign_type'] = (string)($cm['campaign_type'] ?? '');
                $row['campaign_start_date'] = (string)($cm['start_date'] ?? '');
                $row['campaign_end_date'] = (string)($cm['end_date'] ?? '');
                $row['client_id'] = (int)($cm['client_id'] ?? ($l['client_id'] ?? 0));
                $row['client_code'] = (string)($cm['client_code'] ?? ($l['client_code'] ?? ''));
                $row['client_name'] = (string)($cm['client_name'] ?? ($l['client_name'] ?? ''));
                $row['vendor_id'] = $vid;
                $row['vendor_code'] = $vendor ? (string)($vendor['vendor_code'] ?? '') : '';
                $row['vendor_name'] = $vendor ? (string)($vendor['name'] ?? '') : '';
                $row['agent_id'] = (int)($l['agent_id'] ?? 0);
                $row['agent_name'] = (string)($l['agent_name'] ?? '');
                $row['assigned_to_user'] = $assignedTo;
                $row['assigned_to_name'] = (string)($l['assigned_to_name'] ?? (($assignedTo > 0 && isset($userMap[$assignedTo])) ? ($userMap[$assignedTo]['full_name'] ?? '') : ''));
                $row['assigned_to_role'] = (string)($l['assigned_to_role'] ?? (($assignedTo > 0 && isset($userMap[$assignedTo])) ? ($userMap[$assignedTo]['role'] ?? '') : ''));
                $row['created_by'] = $createdBy;
                $row['created_by_name'] = $createdBy > 0 && isset($userMap[$createdBy]) ? (string)($userMap[$createdBy]['full_name'] ?? '') : '';
                $row['updated_by'] = $updatedBy;
                $row['updated_by_name'] = $updatedBy > 0 && isset($userMap[$updatedBy]) ? (string)($userMap[$updatedBy]['full_name'] ?? '') : '';
                $row['first_name'] = (string)($l['first_name'] ?? '');
                $row['last_name'] = (string)($l['last_name'] ?? '');
                $row['job_title'] = (string)($l['job_title'] ?? '');
                $row['email'] = (string)($l['email'] ?? '');
                $row['company_website'] = (string)($l['company_website'] ?? '');
                $row['company_domain'] = (string)($l['company_domain'] ?? '');
                $row['linkedin_link'] = (string)($l['linkedin_link'] ?? '');
                $row['contact_phone'] = (string)($l['contact_phone'] ?? '');
                $row['industry'] = (string)($l['industry'] ?? '');
                $row['company_linkedin'] = (string)($l['company_linkedin'] ?? '');
                $row['company_name'] = (string)($l['company_name'] ?? '');
                $row['company_size'] = (string)($l['company_size'] ?? '');
                $row['country'] = (string)($l['country'] ?? '');
                $row['software_implementation_timeline'] = (string)($l['software_implementation_timeline'] ?? '');
                $row['lead_source'] = (string)($l['lead_source'] ?? '');
                $row['lead_comment'] = (string)($l['lead_comment'] ?? '');
                $row['recording_path'] = (string)($l['recording_path'] ?? '');
                $row['ip_address'] = (string)($l['ip_address'] ?? '');
                $row['qa_status'] = (string)($l['qa_status'] ?? '');
                $row['qa_comment'] = (string)($l['qa_comment'] ?? '');
                $row['qa_client_comment'] = (string)($l['qa_client_comment'] ?? '');
                $row['client_delivery_status'] = (string)($l['client_delivery_status'] ?? '');
                $row['qa_reviewed_by'] = $reviewerId;
                $row['qa_reviewer_name'] = $reviewerId > 0 && isset($userMap[$reviewerId]) ? (string)($userMap[$reviewerId]['full_name'] ?? '') : '';
                $row['qa_updated_at'] = (string)($l['qa_updated_at'] ?? '');
                $row['email_status'] = (string)($l['email_status'] ?? '');
                $row['email_status_comment'] = (string)($l['email_status_comment'] ?? '');
                $row['email_status_updated_by'] = (string)($l['email_status_updated_by'] ?? '');
                $row['email_status_updated_at'] = (string)($l['email_status_updated_at'] ?? '');
                $row['form_done'] = (string)($l['form_done'] ?? '');
                $row['form_filled_time'] = (string)($l['form_filled_time'] ?? '');
                $row['created_at'] = (string)($l['created_at'] ?? '');
                $row['updated_at'] = (string)($l['updated_at'] ?? '');

                $fs = $formDataByLead[$lid] ?? null;
                $row['form_submitted_at'] = $fs ? (string)($fs['submitted_at'] ?? '') : '';
                $data = $fs ? ($fs['data'] ?? []) : [];
                if (!is_array($data)) $data = [];

                $outRow = [];
                if ($exportMode === 'qa') {
                    foreach ($dynamicKeys as $k) $outRow[] = $cell($data[$k] ?? '');
                    foreach ($baseCols as $c) $outRow[] = $cell($row[$c] ?? '');
                } else {
                    foreach ($baseCols as $c) $outRow[] = $cell($row[$c] ?? '');
                    foreach ($dynamicKeys as $k) $outRow[] = $cell($data[$k] ?? '');
                }
                fputcsv($output, $outRow);
            }
        }

        fclose($output);
        exit;
    }
    }
}

?>
<?php $pageTitle = 'Export Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Export Leads</div>
            <div class="text-muted small">
                <?php echo $exportMode === 'qa' ? 'QA export (Lead + QA + Form fields)' : 'Full export'; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('leads-edit.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-download me-1"></i>Export Filters</div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <div class="col-lg-4">
                    <label for="campaign_id" class="form-label small text-muted">Campaign<?php echo $exportMode === 'qa' ? ' (Required)' : ''; ?></label>
                    <select class="form-select form-select-sm" id="campaign_id" name="campaign_id" <?php echo $exportMode === 'qa' ? 'required' : ''; ?>>
                        <option value=""><?php echo $exportMode === 'qa' ? 'Select Campaign' : 'All Campaigns'; ?></option>
                        <?php foreach ($campaigns as $campaign): ?>
                            <option value="<?php echo (int)$campaign['id']; ?>">
                                <?php echo htmlspecialchars((string)($campaign['name'] ?? '') . (!empty($campaign['code']) ? (' · ' . (string)$campaign['code']) : '') . (!empty($campaign['status']) ? (' · ' . (string)$campaign['status']) : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (isAdmin() || isQA()): ?>
                    <div class="col-lg-3">
                        <label for="agent_id" class="form-label small text-muted">Agent</label>
                        <select class="form-select form-select-sm" id="agent_id" name="agent_id">
                            <option value="">All Agents</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?php echo (int)($agent['id'] ?? 0); ?>"><?php echo htmlspecialchars((string)($agent['full_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-lg-2">
                    <label for="date_from" class="form-label small text-muted">Date From</label>
                    <input type="date" class="form-control form-control-sm" id="date_from" name="date_from">
                </div>

                <div class="col-lg-2">
                    <label for="date_to" class="form-label small text-muted">Date To</label>
                    <input type="date" class="form-control form-control-sm" id="date_to" name="date_to">
                </div>

                <div class="col-lg-3">
                    <label for="qa_status" class="form-label small text-muted">QA Status</label>
                    <select class="form-select form-select-sm" id="qa_status" name="qa_status">
                        <option value="">All</option>
                        <?php foreach (getQaStatuses() as $st): ?>
                            <option value="<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars($st); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label for="form_filled" class="form-label small text-muted">Form Done</label>
                    <select class="form-select form-select-sm" id="form_filled" name="form_filled">
                        <option value="">All</option>
                        <option value="Yes">Yes</option>
                        <option value="No">No</option>
                    </select>
                </div>

                <div class="col-lg-4">
                    <label for="search" class="form-label small text-muted">Search</label>
                    <input type="text" class="form-control form-control-sm" id="search" name="search" placeholder="Lead ID, name, email, company">
                </div>

                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" name="export" class="btn btn-primary btn-sm">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
