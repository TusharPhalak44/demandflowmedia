<?php
ob_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','operations_manager','operations_director','vendor_admin','vendor_user']);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$userName = (string)($user['full_name'] ?? '');
$userRole = (string)($user['role'] ?? '');
$userVendorId = (int)($user['vendor_id'] ?? 0);
$conn = getDbConnection();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

$norm = function(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
};

$baseFields = [
    ['key' => 'first_name', 'label' => 'First Name', 'required' => true],
    ['key' => 'last_name', 'label' => 'Last Name', 'required' => true],
    ['key' => 'job_title', 'label' => 'Job Title', 'required' => false],
    ['key' => 'email', 'label' => 'Email', 'required' => false],
    ['key' => 'prospect_linkedin_link', 'label' => 'LinkedIn', 'required' => false],
    ['key' => 'contact_phone', 'label' => 'Phone', 'required' => false],
    ['key' => 'industry', 'label' => 'Industry', 'required' => false],
    ['key' => 'company_linkedin_link', 'label' => 'Company LinkedIn', 'required' => false],
    ['key' => 'company_name', 'label' => 'Company Name', 'required' => false],
    ['key' => 'company_website', 'label' => 'Company Website', 'required' => false],
    ['key' => 'employee_size', 'label' => 'Employee Size', 'required' => false],
    ['key' => 'country', 'label' => 'Country', 'required' => false],
    ['key' => 'software_implementation_timeline', 'label' => 'Implementation Timeline', 'required' => false],
    ['key' => 'lead_comment', 'label' => 'Comment', 'required' => false],
];

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
    'company_website','website','company_site','domain',
    'implementation_timeline','software_implementation_timeline','timeline',
    'when_is_your_company_planning_to_implement_new_software',
    'lead_comment','comment','notes',
]), true);

$normalizeDomain = function(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';
    $s = preg_replace('/^\s*https?:\/\//i', '', $s);
    $s = preg_replace('/^\s*www\./i', '', $s);
    $s = preg_replace('/[\/?#].*$/', '', $s);
    $s = trim($s);
    $s = rtrim($s, '.');
    return $s;
};

$getCampaignMeta = function(int $campaignId) use ($conn, $baseFields, $norm, $skipNorms): array {
    $stmt = $conn->prepare("SELECT id, name FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $camp = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$camp) return ['ok' => false, 'message' => 'Campaign not found'];

    $form = getFormForCampaign($campaignId);
    $custom = [];
    if ($form && isset($form['schema']['fields']) && is_array($form['schema']['fields'])) {
        $seen = [];
        foreach ($form['schema']['fields'] as $ff) {
            if (array_key_exists('visible', $ff) && empty($ff['visible'])) continue;
            $k = (string)($ff['key'] ?? '');
            if ($k === '') continue;
            $lbl = (string)($ff['label'] ?? $k);
            $kn = $norm($k);
            $ln = $norm($lbl);
            if (isset($skipNorms[$kn]) || isset($skipNorms[$ln])) continue;
            if ($kn !== '' && isset($seen[$kn])) continue;
            if ($ln !== '' && isset($seen[$ln])) continue;
            if ($kn !== '') $seen[$kn] = true;
            if ($ln !== '') $seen[$ln] = true;
            $custom[] = [
                'key' => $k,
                'label' => $lbl,
                'type' => (string)($ff['type'] ?? 'text'),
                'options' => (function($ff){
                    $opts = $ff['options'] ?? [];
                    if (!is_array($opts)) return [];
                    $out = [];
                    foreach ($opts as $o) {
                        if (is_array($o)) {
                            $v = (string)($o['value'] ?? ($o['label'] ?? ''));
                        } else {
                            $v = (string)$o;
                        }
                        $v = trim($v);
                        if ($v !== '') $out[] = $v;
                    }
                    return array_values(array_unique($out));
                })($ff),
                'required' => !empty($ff['required']),
            ];
        }
    }

    $headers = array_map(fn($f) => $f['key'], $baseFields);
    foreach ($custom as $c) $headers[] = $c['key'];
    $headers[] = 'error_reason';

    return [
        'ok' => true,
        'campaign' => ['id' => (int)$camp['id'], 'name' => (string)$camp['name']],
        'form' => $form ? ['form_id' => (int)$form['form_id'], 'name' => (string)$form['name']] : null,
        'base_fields' => $baseFields,
        'custom_fields' => $custom,
        'template_headers' => array_values(array_unique($headers)),
    ];
};

if (isset($_GET['action']) && $_GET['action'] === 'meta') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $res = $campaignId > 0 ? $getCampaignMeta($campaignId) : ['ok' => false, 'message' => 'Invalid campaign'];
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $meta = $campaignId > 0 ? $getCampaignMeta($campaignId) : ['ok' => false, 'message' => 'Invalid campaign'];
    if (empty($meta['ok'])) {
        http_response_code(400);
        echo htmlspecialchars((string)($meta['message'] ?? 'Invalid request'));
        exit;
    }
    ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    $fname = 'bulk_upload_template_campaign_' . (int)$campaignId . '.csv';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    $headers = $meta['template_headers'] ?? [];
    $headers = array_values(array_filter($headers, fn($h) => $h !== 'error_reason'));
    fputcsv($out, $headers);

    $idx = array_flip($headers);
    $customFields = $meta['custom_fields'] ?? [];

    $formatOptions = function(array $opts): string {
        $clean = [];
        foreach ($opts as $o) {
            $v = trim((string)$o);
            if ($v !== '') $clean[] = $v;
        }
        $clean = array_values(array_unique($clean));
        if (empty($clean)) return '';
        $maxItems = 50;
        $slice = array_slice($clean, 0, $maxItems);
        $txt = implode(' | ', $slice);
        if (count($clean) > $maxItems) $txt .= ' | (+'.(count($clean) - $maxItems).' more)';
        return $txt;
    };

    $accepted = array_fill(0, count($headers), '');
    $accepted[0] = '#ACCEPTED_VALUES';
    if (isset($idx['email'])) $accepted[$idx['email']] = 'valid email';
    if (isset($idx['contact_phone'])) $accepted[$idx['contact_phone']] = 'digits only';
    if (isset($idx['prospect_linkedin_link'])) $accepted[$idx['prospect_linkedin_link']] = 'URL';
    if (isset($idx['company_website'])) $accepted[$idx['company_website']] = 'domain or URL';

    $formForCampaign = getFormForCampaign($campaignId);
    $schema = (array)($formForCampaign['schema'] ?? []);
    $baseOptions = $formForCampaign ? getSelectOptionsByFormSchema($schema, ['industry','employee_size','company_size','country','software_implementation_timeline']) : [];

    if (isset($idx['industry']) && !empty($baseOptions['industry'])) {
        $accepted[$idx['industry']] = $formatOptions($baseOptions['industry']);
    }
    if (isset($idx['employee_size'])) {
        $emp = $baseOptions['employee_size'] ?? ($baseOptions['company_size'] ?? []);
        if (!empty($emp)) $accepted[$idx['employee_size']] = $formatOptions($emp);
    }
    if (isset($idx['country']) && !empty($baseOptions['country'])) {
        $accepted[$idx['country']] = $formatOptions($baseOptions['country']);
    }
    if (isset($idx['software_implementation_timeline']) && !empty($baseOptions['software_implementation_timeline'])) {
        $accepted[$idx['software_implementation_timeline']] = $formatOptions($baseOptions['software_implementation_timeline']);
    }

    foreach ($customFields as $cf) {
        $k = (string)($cf['key'] ?? '');
        if ($k === '' || !isset($idx[$k])) continue;
        $opts = $cf['options'] ?? [];
        if (is_array($opts) && !empty($opts)) {
            $accepted[$idx[$k]] = $formatOptions($opts);
        }
    }
    fputcsv($out, $accepted);

    $sample = array_fill(0, count($headers), '');
    $set = function(string $k, string $v) use (&$sample, $idx) {
        if (!isset($idx[$k])) return;
        $sample[$idx[$k]] = $v;
    };
    $set('first_name', '#SAMPLE');
    $set('last_name', 'ROW');
    $set('job_title', 'Manager');
    $set('email', 'john.doe@example.com');
    $set('contact_phone', '15550123');
    $set('company_name', 'Example Inc');
    $set('country', 'United States');

    foreach ($customFields as $cf) {
        $k = (string)($cf['key'] ?? '');
        if ($k === '' || !isset($idx[$k])) continue;
        $opts = $cf['options'] ?? [];
        if (is_array($opts) && !empty($opts)) {
            $sample[$idx[$k]] = (string)$opts[0];
        }
    }
    fputcsv($out, $sample);
    fclose($out);
    exit;
}

$message = '';
$messageType = 'success';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $streamProgressStarted = false;
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
            throw new RuntimeException('Invalid request token.');
        }

        ensureLeadsTrackingColumns();
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) throw new RuntimeException('Select a campaign.');
        $meta = $getCampaignMeta($campaignId);
        if (empty($meta['ok'])) throw new RuntimeException((string)($meta['message'] ?? 'Campaign not found'));

        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please select a valid CSV file.');
        }
        $fileInfo = pathinfo((string)($_FILES['csv_file']['name'] ?? ''));
        $ext = strtolower((string)($fileInfo['extension'] ?? ''));
        if ($ext !== 'csv') throw new RuntimeException('Only CSV files are allowed.');

        $tmp = (string)($_FILES['csv_file']['tmp_name'] ?? '');
        $fh = fopen($tmp, 'r');
        if (!$fh) throw new RuntimeException('Unable to read uploaded file.');

        $rawHeader = fgetcsv($fh);
        if (!$rawHeader || !is_array($rawHeader)) throw new RuntimeException('CSV header row is missing.');

        $header = [];
        foreach ($rawHeader as $h) {
            $h = (string)$h;
            $hn = $norm($h);
            if ($hn === '') $hn = 'col_' . count($header);
            $header[] = $hn;
        }

        $map = [];
        foreach ($header as $i => $hn) {
            if ($hn === 'error_reason') continue;
            $map[$hn] = $i;
        }

        $requiredBase = array_values(array_map(fn($f) => $f['key'], array_filter($meta['base_fields'], fn($f) => !empty($f['required']))));
        $requiredCustom = [];
        foreach ($meta['custom_fields'] as $cf) {
            if (!empty($cf['required'])) $requiredCustom[] = (string)$cf['key'];
        }

        $customOptions = [];
        $customTypes = [];
        foreach ($meta['custom_fields'] as $cf) {
            $k = (string)($cf['key'] ?? '');
            if ($k === '') continue;
            $customTypes[$k] = (string)($cf['type'] ?? 'text');
            $opts = $cf['options'] ?? [];
            if (is_array($opts) && !empty($opts)) {
                $customOptions[$k] = array_values(array_unique(array_map(fn($x) => trim((string)$x), $opts)));
            }
        }

        $missingCols = [];
        foreach ($requiredBase as $k) {
            if (!isset($map[$norm($k)])) $missingCols[] = $k;
        }
        foreach ($requiredCustom as $k) {
            if (!isset($map[$norm($k)])) $missingCols[] = $k;
        }
        if (!empty($missingCols)) {
            throw new RuntimeException('Missing required columns: ' . implode(', ', $missingCols));
        }

        $reportDir = __DIR__ . '/../../uploads/bulk_upload_reports';
        if (!is_dir($reportDir)) @mkdir($reportDir, 0775, true);
        $token = date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        $okPath = $reportDir . "/bulk_{$token}_success.csv";
        $badPath = $reportDir . "/bulk_{$token}_rejected.csv";
        $okOut = fopen($okPath, 'w');
        $badOut = fopen($badPath, 'w');
        if (!$okOut || !$badOut) throw new RuntimeException('Unable to create report files.');

        $outHeader = $rawHeader;
        $outHeader[] = 'error_reason';
        fputcsv($okOut, array_merge($rawHeader, ['lead_db_id', 'lead_code']));
        fputcsv($badOut, $outHeader);

        $inserted = 0;
        $rejected = 0;
        $processed = 0;

        $campaignName = (string)($meta['campaign']['name'] ?? '');
        $formForCampaign = getFormForCampaign($campaignId);
        $formId = (int)($formForCampaign['form_id'] ?? 0);
        if (!$formForCampaign || $formId <= 0) {
            throw new RuntimeException('Form is not assigned to this campaign. Assign a form before uploading leads.');
        }
        $strictSelectOptions = $formForCampaign ? getSelectOptionsByFormSchema((array)($formForCampaign['schema'] ?? []), ['industry','employee_size','company_size','country','software_implementation_timeline']) : [];
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $clientId = getCampaignClientId($campaignId);

        $stmtLead = $conn->prepare("
            INSERT INTO leads (
                lead_id, campaign_id, campaign_name, client_id, agent_id, agent_name,
                first_name, last_name, job_title, email, company_domain,
                contact_phone, industry, company_name,
                company_size, country, lead_comment,
                recording_path, ip_address, created_by, updated_by, form_done, form_filled_time,
                vendor_id
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$stmtLead) throw new RuntimeException('Failed to prepare insert.');

        $totalInFile = 0;
        while (($rCount = fgetcsv($fh)) !== false) {
            if (!is_array($rCount) || empty($rCount)) continue;
            $firstCell = trim((string)($rCount[0] ?? ''));
            if ($firstCell !== '' && str_starts_with($firstCell, '#')) continue;
            $totalInFile++;
        }
        rewind($fh);
        fgetcsv($fh);

        $emitProgress = function(int $processed, int $total, int $inserted, int $rejected, string $phase) {};
        $streamProgress = !$isAjax;
        if ($streamProgress) {
            if (ob_get_level() > 0) { @ob_end_clean(); }
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @ob_implicit_flush(true);

            $pageTitle = 'Bulk Upload';
            include __DIR__ . '/../../includes/layout/app_start.php';
            $streamProgressStarted = true;
            ?>
            <div class="container-fluid px-0">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="h3 mb-1">Bulk Upload Processing</h2>
                        <div class="text-muted small">
                            Campaign: <?php echo htmlspecialchars($campaignName); ?>
                            <span class="mx-1">•</span>
                            Total rows detected: <span id="bulk_total"><?php echo (int)$totalInFile; ?></span>
                        </div>
                    </div>
                    <a href="bulk-upload.php" class="btn btn-outline-secondary btn-sm">Back</a>
                </div>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="fw-semibold">Processing</div>
                                    <div class="small text-muted" id="bulk_phase">Starting…</div>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" id="bulk_bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="d-flex flex-wrap gap-3 mt-3">
                                    <div><span class="text-muted small">Processed</span><div class="fw-semibold" id="bulk_processed">0</div></div>
                                    <div><span class="text-muted small">Uploaded</span><div class="fw-semibold text-success" id="bulk_inserted">0</div></div>
                                    <div><span class="text-muted small">Rejected</span><div class="fw-semibold text-danger" id="bulk_rejected">0</div></div>
                                </div>
                                <div class="small text-muted mt-3" id="bulk_last"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="fw-semibold mb-2">Notes</div>
                                <ul class="small text-muted mb-0 ps-3">
                                    <li>Leave this page open while processing.</li>
                                    <li>Results will appear automatically when finished.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function bulkUpdateProgress(processed, total, inserted, rejected, phase, lastMsg) {
                    const p = Math.max(0, parseInt(processed || 0, 10));
                    const t = Math.max(0, parseInt(total || 0, 10));
                    const pct = t > 0 ? Math.min(100, Math.round((p / t) * 100)) : 0;
                    const bar = document.getElementById('bulk_bar');
                    if (bar) bar.style.width = pct + '%';
                    const ph = document.getElementById('bulk_phase');
                    if (ph) ph.textContent = phase || '';
                    const elP = document.getElementById('bulk_processed');
                    if (elP) elP.textContent = String(p);
                    const elI = document.getElementById('bulk_inserted');
                    if (elI) elI.textContent = String(inserted || 0);
                    const elR = document.getElementById('bulk_rejected');
                    if (elR) elR.textContent = String(rejected || 0);
                    const last = document.getElementById('bulk_last');
                    if (last) last.textContent = lastMsg || '';
                }
                bulkUpdateProgress(0, <?php echo (int)$totalInFile; ?>, 0, 0, 'Starting…', '');
            </script>
            <?php
            echo str_repeat(' ', 4096);
            flush();

            $emitProgress = function(int $processed, int $total, int $inserted, int $rejected, string $phase) {
                $payload = json_encode([$processed, $total, $inserted, $rejected, $phase], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                echo "<script>bulkUpdateProgress(...$payload);</script>";
                echo str_repeat(' ', 1024);
                flush();
            };
            $emitProgress(0, $totalInFile, 0, 0, 'Reading file…');
        }

        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row) || empty($row)) continue;
            $firstCell = trim((string)($row[0] ?? ''));
            if ($firstCell !== '' && str_starts_with($firstCell, '#')) continue;
            $processed++;
            $get = function(string $key) use ($row, $map, $norm): string {
                $idx = $map[$norm($key)] ?? null;
                if ($idx === null) return '';
                return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            };
            $getAny = function(array $keys) use ($get): string {
                foreach ($keys as $k) {
                    $v = $get((string)$k);
                    if (trim((string)$v) !== '') return (string)$v;
                }
                return '';
            };

            $firstName = $get('first_name');
            $lastName = $get('last_name');
            if ($firstName === '' || $lastName === '') {
                $rejected++;
                $r = $row;
                $r[] = 'Missing required name';
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }

            $email = $getAny(['email','email_address','work_email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rejected++;
                $r = $row;
                $r[] = 'Invalid email address';
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $company = $getAny(['company_name','company']);
            $dups = findDuplicateLeads($firstName, $lastName, $email, $company, 1, $campaignId);
            if (!empty($dups)) {
                $rejected++;
                $r = $row;
                $r[] = 'Duplicate lead';
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }

            $leadCode = 'LD' . date('Ymd') . strtoupper(substr(md5(uniqid('', true)), 0, 6));
            $jobTitle = $getAny(['job_title','title','designation']) ?: null;
            $linkedin = $getAny(['prospect_linkedin_link','linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin_url']) ?: null;
            $phoneRaw = $getAny(['contact_phone','phone']);
            if ($phoneRaw !== '' && preg_match('/[^0-9]/', $phoneRaw)) {
                $rejected++;
                $r = $row;
                $r[] = 'Invalid contact_phone (digits only)';
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $phone = $phoneRaw !== '' ? $phoneRaw : null;
            $industry = $getAny(['industry']) ?: null;
            $companyLinkedin = $getAny(['company_linkedin_link','company_linkedin','company_linkedin_url']) ?: null;
            $companyWebsiteRaw = $getAny(['company_website','website','domain']);
            $companyWebsite = $companyWebsiteRaw !== '' ? $normalizeDomain($companyWebsiteRaw) : null;
            $companyDomain = extractDomain((string)$email);
            if ($companyDomain === '' && $companyWebsite) $companyDomain = extractDomain((string)$companyWebsite);
            $companyDomain = $companyDomain !== '' ? $companyDomain : null;

            if ($companyDomain) {
                $hit = findClientDomainSuppressionLead($campaignId, (string)$companyDomain, 90);
                if ($hit) {
                    $rejected++;
                    $r = $row;
                    $r[] = 'Client domain cooldown (90 days): ' . (string)($companyDomain);
                    fputcsv($badOut, $r);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue;
                }
            }
            $employeeSize = $getAny(['employee_size','company_size','company_size_range']) ?: null;
            $country = $getAny(['country','country_name','location_country']) ?: null;
            $timelineAnswer = $get('software_implementation_timeline') ?: null;
            $timeline = null;
            $comment = $get('lead_comment') ?: null;

            if ($industry !== null && isset($strictSelectOptions['industry']) && !valueInAllowedOptions($industry, $strictSelectOptions['industry'])) {
                $rejected++;
                $r = $row;
                $r[] = 'Invalid industry. Allowed: ' . implode(' | ', $strictSelectOptions['industry']);
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $empAllowed = $strictSelectOptions['employee_size'] ?? ($strictSelectOptions['company_size'] ?? null);
            if ($employeeSize !== null && is_array($empAllowed) && !empty($empAllowed) && !valueInAllowedOptions($employeeSize, $empAllowed)) {
                $rejected++;
                $r = $row;
                $r[] = 'Invalid employee_size. Allowed: ' . implode(' | ', $empAllowed);
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            if ($country !== null && isset($strictSelectOptions['country']) && !valueInAllowedOptions($country, $strictSelectOptions['country'])) {
                $rejected++;
                $r = $row;
                $r[] = 'Invalid country. Allowed: ' . implode(' | ', $strictSelectOptions['country']);
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            if ($timelineAnswer !== null && isset($strictSelectOptions['software_implementation_timeline']) && !valueInAllowedOptions($timelineAnswer, $strictSelectOptions['software_implementation_timeline'])) {
                $rejected++;
                $r = $row;
                $r[] = 'Invalid software_implementation_timeline. Allowed: ' . implode(' | ', $strictSelectOptions['software_implementation_timeline']);
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }

            $recording = null;
            $formDone = 'No';
            $formFilledTime = null;

            $customData = [];
            foreach ($meta['custom_fields'] as $cf) {
                $k = (string)($cf['key'] ?? '');
                if ($k === '') continue;
                $v = $get($k);
                if ($v === '') continue;
                $type = (string)($customTypes[$k] ?? ($cf['type'] ?? 'text'));
                if (($type === 'number' || $type === 'numeric') && preg_match('/[^0-9]/', $v)) {
                    $rejected++;
                    $r = $row;
                    $r[] = 'Invalid value for ' . $k . ' (digits only)';
                    fputcsv($badOut, $r);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue 2;
                }
                if (isset($customOptions[$k]) && !empty($customOptions[$k])) {
                    $allowed = $customOptions[$k];
                    $ok = false;
                    foreach ($allowed as $a) {
                        if (strcasecmp(trim((string)$a), trim((string)$v)) === 0) { $ok = true; break; }
                    }
                    if (!$ok) {
                        $rejected++;
                        $r = $row;
                        $r[] = 'Invalid value for ' . $k . '. Allowed: ' . implode(' | ', $allowed);
                        fputcsv($badOut, $r);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                }
                $customData[$k] = $v;
            }
            foreach ($requiredCustom as $rk) {
                if (!array_key_exists($rk, $customData)) {
                    $rejected++;
                    $r = $row;
                    $r[] = 'Missing required form field: ' . $rk;
                    fputcsv($badOut, $r);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue 2;
                }
            }
            if ($formId > 0 && !empty($customData)) {
                $formDone = 'Yes';
                $formFilledTime = date('Y-m-d H:i:s');
            }

            $submissionData = [];
            $schemaFields = (array)($formForCampaign['schema']['fields'] ?? []);
            $parseMulti = function(string $raw): array {
                $s = trim($raw);
                if ($s === '') return [];
                if (str_contains($s, '|')) $parts = explode('|', $s);
                else $parts = explode(',', $s);
                $parts = array_values(array_filter(array_map(fn($x) => trim((string)$x), $parts), fn($x) => $x !== ''));
                return array_values(array_unique($parts));
            };
            foreach ($schemaFields as $sf) {
                if (!is_array($sf)) continue;
                if (array_key_exists('visible', $sf) && empty($sf['visible'])) continue;
                $key = (string)($sf['key'] ?? '');
                if ($key === '') continue;
                $type = strtolower(trim((string)($sf['type'] ?? 'text')));
                if ($type === 'file_upload') continue;
                $raw = $get($key);
                if ($raw === '') continue;
                if ($type === 'checkbox') {
                    $arr = $parseMulti($raw);
                    if (!empty($arr)) $submissionData[$key] = $arr;
                } else {
                    $submissionData[$key] = $raw;
                }
            }
            foreach ($customData as $k => $v) {
                if (!array_key_exists($k, $submissionData)) $submissionData[$k] = $v;
            }
            if ($timelineAnswer !== null && !array_key_exists('software_implementation_timeline', $submissionData)) {
                $submissionData['software_implementation_timeline'] = $timelineAnswer;
            }
            if ($companyWebsiteRaw !== '' && !array_key_exists('company_website', $submissionData) && !array_key_exists('website', $submissionData) && !array_key_exists('domain', $submissionData)) {
                $submissionData['company_website'] = $companyWebsiteRaw;
            }
            if ($linkedin !== null && !array_key_exists('prospect_linkedin_link', $submissionData) && !array_key_exists('linkedin_link', $submissionData) && !array_key_exists('linkedin_url', $submissionData)) {
                $submissionData['prospect_linkedin_link'] = $linkedin;
            }
            if ($companyLinkedin !== null && !array_key_exists('company_linkedin', $submissionData) && !array_key_exists('company_linkedin_url', $submissionData) && !array_key_exists('company_linkedin_link', $submissionData)) {
                $submissionData['company_linkedin'] = $companyLinkedin;
            }

            $types = "sisiis" . str_repeat("s", 13) . "ii" . "ss" . "i";
            $stmtLead->bind_param(
                $types,
                $leadCode, $campaignId, $campaignName, $clientId, $userId, $userName,
                $firstName, $lastName, $jobTitle, $email, $companyDomain,
                $phone, $industry, $company,
                $employeeSize, $country, $comment,
                $recording, $ip, $userId, $userId, $formDone, $formFilledTime,
                $userVendorId
            );
            if (!$stmtLead->execute()) {
                $rejected++;
                $r = $row;
                $r[] = 'DB insert failed';
                fputcsv($badOut, $r);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $leadDbId = (int)$conn->insert_id;
            $inserted++;
            if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');

            logLeadActivity($leadDbId, $userId, 'lead_created', ['source' => 'bulk_upload', 'campaign_id' => $campaignId]);
            notifyLeadCreated($leadDbId, $campaignId, $userId);

            if ($formId > 0 && !empty($submissionData)) {
                saveFormSubmission($formId, $campaignId, $leadDbId, $userId, $submissionData);
                logLeadActivity($leadDbId, $userId, 'form_submission_saved', ['form_id' => $formId, 'source' => 'bulk_upload']);
            }

            // Sync to campaign-specific lead table
            syncLeadToCampaignTable($leadDbId);

            fputcsv($okOut, array_merge($row, [(string)$leadDbId, $leadCode]));
        }

        fclose($fh);
        fclose($okOut);
        fclose($badOut);
        $stmtLead->close();

        $okUrl = '../../uploads/bulk_upload_reports/' . basename($okPath);
        $badUrl = '../../uploads/bulk_upload_reports/' . basename($badPath);

        $result = [
            'success' => $rejected === 0,
            'message' => "Upload finished. Total: {$totalInFile}, Uploaded: {$inserted}, Rejected: {$rejected}.",
            'stats' => ['total' => $totalInFile, 'uploaded' => $inserted, 'rejected' => $rejected],
            'success_report_url' => $okUrl,
            'failed_report_url' => $badUrl,
        ];

        if ($streamProgress) {
            $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Finished');
            ?>
            <div class="container-fluid px-0 mt-4">
                <div class="alert alert-<?php echo $rejected === 0 ? 'success' : 'warning'; ?> border-0 shadow-sm">
                    <?php echo htmlspecialchars($result['message']); ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-success btn-sm" href="<?php echo htmlspecialchars($okUrl); ?>" target="_blank" rel="noopener">
                        <i class="bi bi-download me-1"></i>Download Success Report
                    </a>
                    <a class="btn btn-outline-danger btn-sm" href="<?php echo htmlspecialchars($badUrl); ?>" target="_blank" rel="noopener">
                        <i class="bi bi-download me-1"></i>Download Rejected Report
                    </a>
                    <a class="btn btn-outline-secondary btn-sm" href="bulk-upload.php">
                        Back to Bulk Upload
                    </a>
                </div>
            </div>
            <?php
            include __DIR__ . '/../../includes/layout/app_end.php';
            exit;
        }

        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $message = (string)$result['message'];
        $messageType = $result['success'] ? 'success' : 'warning';
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($streamProgressStarted)) {
            ?>
            <div class="container-fluid px-0 mt-4">
                <div class="alert alert-danger border-0 shadow-sm">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="bulk-upload.php">Back to Bulk Upload</a>
            </div>
            <?php
            include __DIR__ . '/../../includes/layout/app_end.php';
            exit;
        }
    }
}

$campaigns = getCampaigns();

if ($userRole === 'vendor_admin' || $userRole === 'vendor_user') {
    if ($userVendorId <= 0) { $campaigns = []; }
    else {
        $allowedIds = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM vendor_campaign_map WHERE vendor_id = ? AND uploads_enabled = 1");
        $stmt->bind_param('i', $userVendorId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) { $allowedIds[(int)$r['campaign_id']] = true; }
        $stmt->close();
        
        if ($userRole === 'vendor_user') {
            $userAssigned = [];
            $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) { $userAssigned[(int)$r['campaign_id']] = true; }
            $stmt->close();
            
            $campaigns = array_values(array_filter($campaigns, function($c) use ($allowedIds, $userAssigned) {
                $cid = (int)($c['id'] ?? 0);
                return isset($allowedIds[$cid]) && isset($userAssigned[$cid]);
            }));
        } else {
            $campaigns = array_values(array_filter($campaigns, function($c) use ($allowedIds) {
                return isset($allowedIds[(int)($c['id'] ?? 0)]);
            }));
        }
    }
} else {
    $opsVisible = getOpsVisibleCampaignIdsForUser($userId, $userRole);
    $opsVisible = getTeamVisibleCampaignIdsForUser($userId, $opsVisible);
    if ($opsVisible !== null) {
        $campaigns = array_values(array_filter($campaigns, fn($c) => isset($opsVisible[(int)($c['id'] ?? 0)])));
    }
}
ob_end_clean();
?>
<?php $pageTitle = 'Bulk Upload'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h3 mb-0">Bulk Upload Leads</h2>
        <a href="leads-purge.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Delete All Leads</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Upload</div>
                <div class="card-body">
                    <form id="uploadForm" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label small text-muted">Campaign</label>
                                <select class="form-select form-select-sm" name="campaign_id" id="campaign_id" required>
                                    <option value="">Select Campaign</option>
                                    <?php foreach ($campaigns as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name'] ?? ''); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-grid">
                                <a id="templateBtn" class="btn btn-outline-secondary btn-sm disabled" href="#">
                                    <i class="bi bi-download me-1"></i>Download Template (CSV)
                                </a>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">CSV File</label>
                                <input type="file" class="form-control form-control-sm" name="csv_file" id="csv_file" accept=".csv" required>
                                <div class="text-muted small mt-1">Excel supported: open the CSV in Excel.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Feedback Mode</label>
                                <select class="form-select form-select-sm" id="feedbackMode">
                                    <option value="realtime" selected>Realtime (Recommended)</option>
                                    <option value="toast">Toast</option>
                                    <option value="modal">Modal</option>
                                </select>
                            </div>
                        </div>

                        <div class="progress mt-3 d-none" id="uploadProgress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" id="progressBar">0%</div>
                        </div>

                        <div class="d-grid mt-3">
                            <button type="submit" class="btn btn-primary" id="uploadBtn"><i class="bi bi-upload"></i> Upload</button>
                        </div>
                    </form>

                    <div id="uploadStatus" class="mt-3"></div>
                    <div id="uploadResult" class="mt-2"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Expected Columns</div>
                <div class="card-body">
                    <div class="text-muted small mb-2">Select a campaign to see its form fields.</div>
                    <div id="expectedCols"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 mt-2">
        <?php if (!isVendor()): ?>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light fw-semibold">Sales Duplicate Check</div>
                    <div class="card-body">
                        <input type="hidden" id="dup_csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Company</label>
                                <input type="text" class="form-control form-control-sm" id="dup_company">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Email</label>
                                <input type="email" class="form-control form-control-sm" id="dup_email">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small text-muted">LinkedIn URL</label>
                                <input type="url" class="form-control form-control-sm" id="dup_linkedin" placeholder="https://www.linkedin.com/in/... or company page">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="auditAll">
                                    <label class="form-check-label small text-muted" for="auditAll">All departments audit</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-outline-primary btn-sm" id="runSalesDupCheck"><i class="bi bi-search me-1"></i>Check Sales Duplicates</button>
                            </div>
                        </div>
                        <div id="salesDupResult" class="mt-3" style="display:none;">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Source</th>
                                            <th>Company</th>
                                            <th>Status</th>
                                            <th>Website</th>
                                            <th>Email</th>
                                            <th>LinkedIn</th>
                                        </tr>
                                    </thead>
                                    <tbody id="salesDupBody"></tbody>
                                </table>
                            </div>
                        </div>
                        <div id="salesDupEmpty" class="text-muted small mt-3" style="display:none;">No duplicates found.</div>
                        <div id="salesDupError" class="text-danger small mt-3" style="display:none;">Unable to check duplicates.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="uploadToastContainer" style="z-index: 1080;"></div>
<div class="modal fade" id="uploadCompleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">Upload Summary</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="uploadCompleteSummary"></div>
        <div class="mt-3 d-flex flex-wrap gap-2" id="uploadCompleteLinks"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
 
<script>
(function(){
    function escapeHtml(str){
        return String(str).replace(/[&<>"']/g, function(s){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[s] || s;
        });
    }

    const campaignSel = document.getElementById('campaign_id');
    const templateBtn = document.getElementById('templateBtn');
    const expectedCols = document.getElementById('expectedCols');
    const pagePath = (window.location.pathname || '').replace(/\/$/, '');

    async function loadMeta(campaignId){
        expectedCols.innerHTML = '<div class="text-muted small">Loading...</div>';
        templateBtn.classList.add('disabled');
        templateBtn.href = '#';
        if (!campaignId) {
            expectedCols.innerHTML = '<div class="text-muted small">Select a campaign.</div>';
            return;
        }
        const metaUrl = pagePath + '?action=meta&campaign_id=' + encodeURIComponent(campaignId);
        const res = await fetch(metaUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        const data = await res.json().catch(() => null);
        if (!data || !data.ok) {
            expectedCols.innerHTML = '<div class="text-danger small">Unable to load form fields.</div>';
            return;
        }

        templateBtn.classList.remove('disabled');
        templateBtn.href = pagePath + '?action=template&campaign_id=' + encodeURIComponent(campaignId);

        let html = '';
        html += '<div class="fw-semibold mb-2">Campaign Fields</div>';
        if (!data.form) {
            html += '<div class="text-muted small">No form assigned to this campaign.</div>';
        } else {
            html += '<div class="text-muted small mb-2">' + escapeHtml(data.form.name || '') + '</div>';
            html += '<div class="d-flex flex-wrap gap-2">';
            const all = []
                .concat(Array.isArray(data.base_fields) ? data.base_fields : [])
                .concat(Array.isArray(data.custom_fields) ? data.custom_fields : []);
            all.forEach(f => {
                const req = f.required ? ' <span class="text-danger">*</span>' : '';
                html += '<span class="badge bg-light text-dark border">' + escapeHtml(f.key) + req + '</span>';
            });
            html += '</div>';
        }

        expectedCols.innerHTML = html;
    }

    campaignSel?.addEventListener('change', () => loadMeta(campaignSel.value));
    loadMeta(campaignSel?.value || '');

    const form = document.getElementById('uploadForm');
    const fileInput = document.getElementById('csv_file');
    const progressEl = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('progressBar');
    const statusDiv = document.getElementById('uploadStatus');
    const resultDiv = document.getElementById('uploadResult');
    const uploadBtn = document.getElementById('uploadBtn');
    const modeEl = document.getElementById('feedbackMode');

    form?.addEventListener('submit', function(e){
        statusDiv.innerHTML = '';
        resultDiv.innerHTML = '';

        const cid = (campaignSel?.value || '').trim();
        if (!cid) {
            e.preventDefault();
            statusDiv.innerHTML = '<div class="alert alert-danger">Select a campaign.</div>';
            return;
        }
        const file = fileInput?.files?.[0];
        if (!file) {
            e.preventDefault();
            statusDiv.innerHTML = '<div class="alert alert-danger">Select a CSV file.</div>';
            return;
        }

        const mode = modeEl ? modeEl.value : 'realtime';
        if (mode === 'realtime') {
            e.preventDefault();
            uploadBtn.disabled = true;
            uploadBtn.textContent = 'Starting...';
            progressEl.classList.add('d-none');
            form.submit();
            return;
        }

        e.preventDefault();
        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';

        const fd = new FormData(form);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', pagePath, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = function(ev){
            if (ev.lengthComputable){
                const percent = Math.round((ev.loaded/ev.total) * 100);
                progressEl.classList.remove('d-none');
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
            }
        };

        xhr.onreadystatechange = function(){
            if (xhr.readyState !== 4) return;
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Upload';
            progressEl.classList.add('d-none');

            const ct = xhr.getResponseHeader('Content-Type') || '';
            const body = xhr.responseText || '';
            try {
                if (!ct.includes('application/json')) throw new Error('Server returned non-JSON');
                const res = JSON.parse(body);
                const cls = res.success ? 'success' : 'warning';
                statusDiv.innerHTML = '<div class="alert alert-' + cls + ' border-0 shadow-sm">' + escapeHtml(res.message || '') + '</div>';

                let links = '';
                if (res.success_report_url) {
                    links += '<a class="btn btn-sm btn-outline-success me-2" href="' + escapeHtml(res.success_report_url) + '" download><i class="bi bi-file-earmark-check"></i> Download Uploaded</a>';
                }
                if (res.failed_report_url) {
                    links += '<a class="btn btn-sm btn-outline-danger" href="' + escapeHtml(res.failed_report_url) + '" download><i class="bi bi-file-earmark-excel"></i> Download Rejected</a>';
                }
                if (links) {
                    resultDiv.innerHTML = '<div class="d-flex flex-wrap gap-2">' + links + '</div>';
                }
                const mode = modeEl ? modeEl.value : 'toast';
                const total = (res.stats && typeof res.stats.total !== 'undefined') ? res.stats.total : 0;
                const uploaded = (res.stats && typeof res.stats.uploaded !== 'undefined') ? res.stats.uploaded : 0;
                const rejected = (res.stats && typeof res.stats.rejected !== 'undefined') ? res.stats.rejected : 0;
                if (mode === 'modal') {
                  const summaryEl = document.getElementById('uploadCompleteSummary');
                  const linksEl = document.getElementById('uploadCompleteLinks');
                  if (summaryEl && linksEl) {
                    summaryEl.innerHTML = '<div>Total: '+escapeHtml(String(total))+'</div><div>Uploaded: '+escapeHtml(String(uploaded))+'</div><div>Rejected: '+escapeHtml(String(rejected))+'</div>';
                    linksEl.innerHTML = '';
                    if (res.success_report_url) {
                      linksEl.insertAdjacentHTML('beforeend', '<a class=\"btn btn-sm btn-outline-success\" href=\"'+escapeHtml(res.success_report_url)+'\" download><i class=\"bi bi-file-earmark-check\"></i> Uploaded CSV</a>');
                    }
                    if (res.failed_report_url) {
                      linksEl.insertAdjacentHTML('beforeend', '<a class=\"btn btn-sm btn-outline-danger\" href=\"'+escapeHtml(res.failed_report_url)+'\" download><i class=\"bi bi-file-earmark-excel\"></i> Rejected CSV</a>');
                    }
                    const mEl = document.getElementById('uploadCompleteModal');
                    if (mEl && window.bootstrap && bootstrap.Modal) { new bootstrap.Modal(mEl).show(); }
                  }
                } else {
                  const okFull = res.success && rejected === 0;
                  const toastWrap = document.getElementById('uploadToastContainer');
                  if (toastWrap) {
                    const t = document.createElement('div');
                    t.className = 'toast align-items-center '+(okFull ? 'text-bg-success' : 'text-bg-warning')+' border-0';
                    t.setAttribute('role','alert'); t.setAttribute('aria-live','assertive'); t.setAttribute('aria-atomic','true');
                    t.innerHTML = '<div class=\"d-flex\">'
                      + '<div class=\"toast-body\">'+(okFull ? '<i class=\"bi bi-check-circle me-2\"></i>' : '<i class=\"bi bi-exclamation-triangle me-2\"></i>')+(okFull ? 'Upload completed successfully.' : 'Upload completed with rejections.')+'</div>'
                      + '<button type=\"button\" class=\"btn-close btn-close-white me-2 m-auto\" data-bs-dismiss=\"toast\" aria-label=\"Close\"></button>'
                      + '</div>';
                    toastWrap.appendChild(t);
                    new bootstrap.Toast(t, { delay: 4000 }).show();
                  }
                }
            } catch (err) {
                statusDiv.innerHTML = '<div class="alert alert-danger border-0 shadow-sm">Upload failed.</div>';
            }
        };

        xhr.send(fd);
    });

    document.getElementById('runSalesDupCheck')?.addEventListener('click', async function(){
      const company = document.getElementById('dup_company').value.trim();
      const email = document.getElementById('dup_email').value.trim();
      const li = document.getElementById('dup_linkedin').value.trim();
      const csrf = document.getElementById('dup_csrf').value;
      const auditAll = document.getElementById('auditAll')?.checked;
      const qs = new URLSearchParams({ company_name: company, contact_email: email, linkedin_url: li }).toString();
      const resSales = await fetch('../sales/check-duplicate?type=sales&'+qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(r=>r.json()).catch(()=>null);
      let resClients = null;
      let resLeads = null;
      if (auditAll) {
        resClients = await fetch('../sales/check-duplicate?type=client&'+qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(r=>r.json()).catch(()=>null);
        const payload = { csrf_token: csrf, first_name: '', last_name: '', email: email, company_name: company, campaign_id: 0 };
        resLeads = await fetch('../leads/check_duplicates', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials: 'same-origin', body: JSON.stringify(payload) }).then(r=>r.json()).catch(()=>null);
      }
      const body = document.getElementById('salesDupBody');
      const wrap = document.getElementById('salesDupResult');
      const empty = document.getElementById('salesDupEmpty');
      const errEl = document.getElementById('salesDupError');
      body.innerHTML = ''; wrap.style.display = 'none'; empty.style.display = 'none'; errEl.style.display = 'none';
      const rowsSales = (resSales && resSales.ok && Array.isArray(resSales.matches)) ? resSales.matches : [];
      const rowsClients = (resClients && resClients.ok && Array.isArray(resClients.matches)) ? resClients.matches : [];
      const rowsLeads = (resLeads && resLeads.ok && Array.isArray(resLeads.matches)) ? resLeads.matches : [];
      const totalRows = rowsSales.length + rowsClients.length + rowsLeads.length;
      if (!totalRows) {
        if (!(resSales && resSales.ok) && auditAll && (!resClients || !resLeads)) { errEl.style.display = 'block'; return; }
        empty.style.display = 'block'; return;
      }
      function addRow(src, d){
        const tr = document.createElement('tr');
        tr.innerHTML = '<td class=\"small text-muted\">'+escapeHtml(src)+'</td>'
          + '<td class=\"fw-semibold\">'+escapeHtml(d.company_name||d.name||'')+'</td>'
          + '<td><span class=\"badge bg-secondary\">'+escapeHtml(d.status||'')+'</span></td>'
          + '<td class=\"small text-muted\">'+escapeHtml(d.website||'')+'</td>'
          + '<td class=\"small text-muted\">'+escapeHtml(d.contact_email||d.email||'')+'</td>'
          + '<td class=\"small\"><a href=\"'+escapeHtml(d.linkedin_url||d.linkedin_link||'#')+'\" target=\"_blank\" rel=\"noopener\">'+((d.linkedin_url||d.linkedin_link)?'Open':'')+'</a></td>';
        body.appendChild(tr);
      }
      rowsSales.forEach(d => addRow('Sales', d));
      rowsClients.forEach(d => addRow('Clients', d));
      rowsLeads.forEach(d => addRow('Leads', d));
      wrap.style.display = 'block';
    });
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
