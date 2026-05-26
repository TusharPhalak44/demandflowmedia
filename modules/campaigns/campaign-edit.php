<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','sales_manager','operations_director','operations_manager','client_admin']);
ensureCsrfToken();
$conn = getDbConnection();
$clients = [];
$rs = $conn->query("SELECT id, client_code, name FROM clients ORDER BY client_code, name");
if ($rs) { $clients = $rs->fetch_all(MYSQLI_ASSOC) ?: []; }
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id<=0){ header('Location: list'); exit; }
$row = getCampaignDetailsById($id);
if(!$row){ header('Location: list'); exit; }

if (isClientAdmin() && (int)($row['client_id'] ?? 0) !== (int)(getCurrentUser()['client_id'] ?? 0)) {
    header('Location: list'); exit;
}
$additionalFiles = [];
$stmt = $conn->prepare("SELECT id, file_title, file_path, file_type, description, created_at FROM campaign_additional_files WHERE campaign_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $id);
$stmt->execute();
$additionalFiles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();
$error='';
$formOpts = getCampaignCreateFormOptionValues();
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])&&$_POST['action']==='update'){
    $csrf=$_POST['csrf_token']??'';
    if(!hash_equals($_SESSION['csrf_token'],$csrf)){
        $error='Invalid security token.';
    } else {
        try{
            $clientId = (int)($_POST['client_id'] ?? 0);
            $clientCode = $row['client_code'] ?? null;
            if ($clientId > 0) {
                $stmt = $conn->prepare("SELECT client_code FROM clients WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $clientId);
                $stmt->execute();
                $clientCode = $stmt->get_result()->fetch_row()[0] ?? null;
                $stmt->close();
            }
            $basic=[
                'client_id'=>$clientId ?: ($row['client_id'] ?? null),
                'client_code'=>$clientCode,
                'name'=>trim($_POST['name']??$row['name']),
                'status'=>$_POST['status']??($row['status']??'Draft'),
                'start_date'=>$_POST['start_date']??($row['start_date']??null),
                'end_date'=>$_POST['end_date']??($row['end_date']??null),
                'total_leads'=>$_POST['total_leads']??($row['total_leads']??null),
                'pacing_type'=>$_POST['pacing_type']??($row['pacing_type']??null),
                'pacing_count'=>$_POST['pacing_count']??($row['pacing_count']??null),
                'cpc'=>$_POST['cpc']??($row['cpc']??null),
                'cpl'=>$_POST['cpl']??($row['cpl']??null),
                'cpl_currency'=>$_POST['cpl_currency']??($row['cpl_currency']??null),
                'campaign_type'=>$_POST['campaign_type']??($row['campaign_type']??null),
                'delivery_format'=>(($_POST['delivery_format']??'')==='Other')?trim($_POST['delivery_format_other']??''):($_POST['delivery_format']??($row['delivery_format']??null)),
                'instruction'=>$_POST['instruction']??($row['instruction']??null),
                'updated_by'=>(int)(getCurrentUser()['id'] ?? 0),
            ];
            if(!$basic['client_id'] || !$basic['client_code']){ throw new RuntimeException('Client Code is required'); }
            if (!isAdmin() && !hasRole(['director','manager_director','sales_director'])) {
                $basic['cpl'] = $row['cpl'] ?? null;
                $basic['cpl_currency'] = $row['cpl_currency'] ?? null;
            }
            $criteria=[
                'targeted_country'=>isset($_POST['targeted_country'])?array_values(array_filter((array)$_POST['targeted_country'])):($row['targeted_country']??[]),
                'job_title'=>trim($_POST['job_title']??($row['job_title']??'')),
                'departments'=>isset($_POST['departments'])?array_values(array_filter((array)$_POST['departments'])):($row['departments']??[]),
                'seniority_levels'=>isset($_POST['seniority_levels'])?array_values(array_filter((array)$_POST['seniority_levels'])):($row['seniority_levels']??[]),
                'industries'=>isset($_POST['industries'])?array_values(array_filter((array)$_POST['industries'])):($row['industries']??[]),
                'employee_sizes'=>isset($_POST['employee_sizes'])?array_values(array_filter((array)$_POST['employee_sizes'])):($row['employee_sizes']??[]),
                'revenue_sizes'=>isset($_POST['revenue_sizes'])?array_values(array_filter((array)$_POST['revenue_sizes'])):($row['revenue_sizes']??[]),
            ];
            $ov = trim($_POST['targeted_country_other'] ?? '');
            if($ov!==''){ $criteria['targeted_country'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['targeted_country'])); }
            $ov = trim($_POST['departments_other'] ?? '');
            if($ov!==''){ $criteria['departments'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['departments'])); }
            $ov = trim($_POST['seniority_levels_other'] ?? '');
            if($ov!==''){ $criteria['seniority_levels'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['seniority_levels'])); }
            $ov = trim($_POST['industries_other'] ?? '');
            if($ov!==''){ $criteria['industries'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['industries'])); }
            $ov = trim($_POST['employee_sizes_other'] ?? '');
            if($ov!==''){ $criteria['employee_sizes'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['employee_sizes'])); }
            $ov = trim($_POST['revenue_sizes_other'] ?? '');
            if($ov!==''){ $criteria['revenue_sizes'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['revenue_sizes'])); }
            $custom=[];
            if(!empty($_POST['custom_label'])&&is_array($_POST['custom_label'])){
                foreach($_POST['custom_label'] as $i=>$lbl){
                    $lbl=trim($lbl);
                    $val=trim($_POST['custom_value'][$i]??'');
                    $type=trim($_POST['custom_type'][$i]??'text');
                    $optsRaw=trim($_POST['custom_options'][$i]??'');
                    $opts = $optsRaw!=='' ? array_values(array_filter(array_map('trim', explode(',', $optsRaw)))) : [];
                    if($lbl!==''){ $custom[]=['label'=>$lbl,'type'=>$type,'value'=>$val,'options'=>$opts]; }
                }
            } else {
                $custom = is_array($row['custom_fields_json']??null) ? $row['custom_fields_json'] : [];
            }
            $files=[
                'script_file'=>$_FILES['script_file']??[],
                'tal_file'=>(isset($_POST['tal_yes'])&&$_POST['tal_yes']==='Yes')?($_FILES['tal_file']??[]):['name'=>''],
                'suppression_file'=>(isset($_POST['suppression_yes'])&&$_POST['suppression_yes']==='Yes')?($_FILES['suppression_file']??[]):['name'=>''],
            ];
            updateCampaignDetails($id,$basic,$criteria,$custom,$files);
            saveCampaignAdditionalFiles(
                $id,
                $_FILES['additional_file'] ?? [],
                $_POST['additional_title'] ?? [],
                $_POST['additional_type'] ?? [],
                $_POST['additional_description'] ?? [],
                (int)(getCurrentUser()['id'] ?? 0)
            );
            header('Location: list');
            exit;
        }catch(Throwable $e){ $error=$e->getMessage(); }
    }
}
define('INCLUDED',true);
?>
<?php $pageTitle = 'Edit Campaign'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<div class="container-fluid px-0">
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span>Edit Campaign</span>
            <a href="list" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> Back</a>
        </div>
        <div class="card-body">
            <?php if(!empty($error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="update">
                <div class="col-12">
                    <h6 class="mb-2">L: Campaign Setup</h6>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Campaign Name <span class="text-danger" data-bs-toggle="tooltip" title="This field is required">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($row['name']??''); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Client Code <span class="text-danger" data-bs-toggle="tooltip" title="This field is required">*</span></label>
                    <select name="client_id" class="form-select" required>
                        <?php if (isClientAdmin()): ?>
                            <?php 
                                $user = getCurrentUser();
                                $clientId = (int)($user['client_id'] ?? 0);
                                $stmt = $conn->prepare("SELECT id, client_code, name FROM clients WHERE id = ? LIMIT 1");
                                $stmt->bind_param('i', $clientId);
                                $stmt->execute();
                                $c = $stmt->get_result()->fetch_assoc();
                                $stmt->close();
                            ?>
                            <?php if ($c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" selected><?php echo htmlspecialchars(($c['client_code'] ?? '').' – '.($c['name'] ?? '')); ?></option>
                            <?php endif; ?>
                        <?php else: ?>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)($row['client_id'] ?? 0) === (int)$c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(isAdmin() ? (($c['client_code'] ?? '').' – '.($c['name'] ?? '')) : (string)($c['client_code'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Campaign Code : TGS-XX-XXXX</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($row['code']??''); ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach(['Draft','Active','Pause','Complete','Live'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo (($row['status']??'')===$st)?'selected':''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Start Date <span class="text-danger" data-bs-toggle="tooltip" title="This field is required">*</span></label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($row['start_date']??''); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($row['end_date']??''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Total Allocation</label>
                    <input type="number" name="total_leads" class="form-control" min="0" value="<?php echo htmlspecialchars($row['total_leads']??''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Campaign Type</label>
                    <select name="campaign_type" class="form-select">
                        <option value=""> Please Select </option>
                        <?php
                          $ct = (string)($row['campaign_type'] ?? '');
                          $ctList = [
                              'Email Marketing',
                              'Marketing Qualified Leads',
                              'BANT',
                              'Appointment Generation',
                          ];
                          if ($ct !== '' && !in_array($ct, $ctList, true)) {
                              echo '<option value="'.htmlspecialchars($ct).'" selected>'.htmlspecialchars($ct).'</option>';
                          }
                        ?>
                        <?php foreach ($ctList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $ct === (string)$opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Delivery Format</label>
                    <select name="delivery_format" class="form-select" id="deliveryFormatSel">
                        <option value=""> Please Select </option>
                        <?php
                          $df = trim((string)($row['delivery_format'] ?? ''));
                          $dfList = is_array($formOpts['delivery_format'] ?? null) ? $formOpts['delivery_format'] : ['Internal CRM','Client CRM','CSV','XLSX','Other'];
                          $dfOther = '';
                          if ($df !== '' && !in_array($df, $dfList, true)) {
                              $dfOther = $df;
                              $df = 'Other';
                          }
                        ?>
                        <?php foreach ($dfList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $df === (string)$opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control mt-2" name="delivery_format_other" id="deliveryFormatOther" placeholder="Enter format" style="display:none;" value="<?php echo htmlspecialchars($dfOther); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Pacing Type</label>
                    <select name="pacing_type" class="form-select">
                        <option value=""> Please Select </option>
                        <?php
                          $pt = (string)($row['pacing_type'] ?? '');
                          $ptList = is_array($formOpts['pacing_type'] ?? null) ? $formOpts['pacing_type'] : ['Daily','Weekly','Monthly'];
                          if ($pt !== '' && !in_array($pt, $ptList, true)) {
                              echo '<option value="'.htmlspecialchars($pt).'" selected>'.htmlspecialchars($pt).'</option>';
                          }
                          foreach ($ptList as $opt):
                        ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $pt === (string)$opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Pacing Count</label>
                    <input type="number" name="pacing_count" class="form-control" min="0" value="<?php echo htmlspecialchars($row['pacing_count']??''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">CPC (Contacts Per Company)</label>
                    <input type="number" step="1" min="0" name="cpc" class="form-control" value="<?php echo htmlspecialchars($row['cpc']??''); ?>">
                </div>
                <?php if(isAdmin() || isSalesDirector() || isSalesManager()): ?>
                <div class="col-md-4">
                    <label class="form-label">CPL</label>
                    <div class="input-group">
                        <select name="cpl_currency" class="form-select" style="max-width:120px">
                            <?php foreach(['USD'=>'$ (USD)','GBP'=>'£ (GBP)','EUR'=>'€ (EUR)','INR'=>'₹ (INR)'] as $code=>$label): ?>
                            <option value="<?php echo $code; ?>" <?php echo (($row['cpl_currency']??'')===$code)?'selected':''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" step="0.01" min="0" name="cpl" class="form-control" placeholder="Amount" value="<?php echo htmlspecialchars($row['cpl']??''); ?>">
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <hr class="my-2">
                    <h6 class="mb-2">Targeting</h6>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Geography</label>
                    <select name="targeted_country[]" class="form-select select2" multiple id="geoSel">
                        <?php
                          $geo = is_array($row['targeted_country'] ?? null) ? $row['targeted_country'] : [];
                          $geo = array_values(array_filter(array_map('trim', $geo), fn($v) => $v !== ''));
                          $geoList = is_array($formOpts['targeted_country'] ?? null) ? $formOpts['targeted_country'] : [];
                          $geoUnknown = array_values(array_filter($geo, fn($v) => !in_array($v, $geoList, true)));
                        ?>
                        <?php foreach ($geoUnknown as $v): ?>
                          <option value="<?php echo htmlspecialchars($v); ?>" selected><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($geoList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $geo, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control mt-2" name="targeted_country_other" id="geoOther" placeholder="Enter geography" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Job Title</label>
                    <input type="text" name="job_title" class="form-control" value="<?php echo htmlspecialchars($row['job_title']??''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Functions</label>
                    <select name="departments[]" class="form-select select2" multiple id="deptSel">
                        <?php
                          $deps = is_array($row['departments'] ?? null) ? $row['departments'] : [];
                          $deps = array_values(array_filter(array_map('trim', $deps), fn($v) => $v !== ''));
                          $depList = is_array($formOpts['departments'] ?? null) ? $formOpts['departments'] : [];
                          $depUnknown = array_values(array_filter($deps, fn($v) => !in_array($v, $depList, true)));
                        ?>
                        <?php foreach ($depUnknown as $v): ?>
                          <option value="<?php echo htmlspecialchars($v); ?>" selected><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($depList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $deps, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control mt-2" name="departments_other" id="deptOther" placeholder="Enter function" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Levels</label>
                    <select name="seniority_levels[]" class="form-select select2" multiple id="levelSel">
                        <?php
                          $sen = is_array($row['seniority_levels'] ?? null) ? $row['seniority_levels'] : [];
                          $sen = array_values(array_filter(array_map('trim', $sen), fn($v) => $v !== ''));
                          $senList = is_array($formOpts['seniority_levels'] ?? null) ? $formOpts['seniority_levels'] : [];
                          $senUnknown = array_values(array_filter($sen, fn($v) => !in_array($v, $senList, true)));
                        ?>
                        <?php foreach ($senUnknown as $v): ?>
                          <option value="<?php echo htmlspecialchars($v); ?>" selected><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($senList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $sen, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control mt-2" name="seniority_levels_other" id="levelOther" placeholder="Enter level" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Employee Size</label>
                    <select name="employee_sizes[]" class="form-select select2" multiple id="empSel">
                        <?php
                          $es = is_array($row['employee_sizes'] ?? null) ? $row['employee_sizes'] : [];
                          $es = array_values(array_filter(array_map('trim', $es), fn($v) => $v !== ''));
                          $esList = is_array($formOpts['employee_sizes'] ?? null) ? $formOpts['employee_sizes'] : [];
                          $esUnknown = array_values(array_filter($es, fn($v) => !in_array($v, $esList, true)));
                        ?>
                        <?php foreach ($esUnknown as $v): ?>
                          <option value="<?php echo htmlspecialchars($v); ?>" selected><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($esList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $es, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control mt-2" name="employee_sizes_other" id="empOther" placeholder="Enter employee size" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Industry</label>
                    <select name="industries[]" class="form-select select2" multiple id="indSel">
                        <?php
                          $ind = is_array($row['industries'] ?? null) ? $row['industries'] : [];
                          $ind = array_values(array_filter(array_map('trim', $ind), fn($v) => $v !== ''));
                          $indList = is_array($formOpts['industries'] ?? null) ? $formOpts['industries'] : [];
                          $indUnknown = array_values(array_filter($ind, fn($v) => !in_array($v, $indList, true)));
                        ?>
                        <?php foreach ($indUnknown as $v): ?>
                          <option value="<?php echo htmlspecialchars($v); ?>" selected><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($indList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $ind, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control mt-2" name="industries_other" id="indOther" placeholder="Enter industry" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Revenue</label>
                    <select name="revenue_sizes[]" class="form-select select2" multiple id="revSel">
                        <?php
                          $rev = is_array($row['revenue_sizes'] ?? null) ? $row['revenue_sizes'] : [];
                          $rev = array_values(array_filter(array_map('trim', $rev), fn($v) => $v !== ''));
                          $revList = is_array($formOpts['revenue_sizes'] ?? null) ? $formOpts['revenue_sizes'] : [];
                          $revUnknown = array_values(array_filter($rev, fn($v) => !in_array($v, $revList, true)));
                        ?>
                        <?php foreach ($revUnknown as $v): ?>
                          <option value="<?php echo htmlspecialchars($v); ?>" selected><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($revList as $opt): ?>
                          <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $rev, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control mt-2" name="revenue_sizes_other" id="revOther" placeholder="Enter revenue" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Script (PDF/DOC)</label>
                    <input type="file" name="script_file" class="form-control" accept=".pdf,.doc,.docx">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Suppression List</label>
                    <select name="suppression_yes" class="form-select" id="suppression_yes">
                        <option>No</option>
                        <option>Yes</option>
                    </select>
                </div>
                <div class="col-md-4" id="suppression_file_wrap" style="display:none;">
                    <label class="form-label">Suppression File</label>
                    <input type="file" name="suppression_file" class="form-control" accept=".csv,.xls,.xlsx">
                </div>
                <div class="col-md-4">
                    <label class="form-label">ABM/TAL List</label>
                    <select name="tal_yes" class="form-select" id="tal_yes">
                        <option>No</option>
                        <option>Yes</option>
                    </select>
                </div>
                <div class="col-md-4" id="tal_file_wrap" style="display:none;">
                    <label class="form-label">TAL File</label>
                    <input type="file" name="tal_file" class="form-control" accept=".csv,.xls,.xlsx">
                </div>
                <div class="col-12">
                    <hr class="my-2">
                    <h6 class="mb-2">Additional Files</h6>
                </div>
                <div class="col-12">
                    <?php if (!empty($additionalFiles)): ?>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Title</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th class="text-muted">Uploaded</th>
                                        <th>File</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($additionalFiles as $af): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($af['file_title'] ?? ''); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($af['file_type'] ?? ''); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($af['description'] ?? ''); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($af['created_at'] ?? ''); ?></td>
                                            <td><a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars('../'.($af['file_path'] ?? '')); ?>" target="_blank"><i class="bi bi-download"></i></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-12" id="additionalFiles">
                    <div class="row g-2 align-items-end mb-2 additional-file-row">
                        <div class="col-md-3">
                            <label class="form-label">File Title</label>
                            <input type="text" class="form-control" name="additional_title[]" placeholder="Title">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">File Type</label>
                            <input type="text" class="form-control" name="additional_type[]" placeholder="Tag (optional)">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" name="additional_description[]" placeholder="Description (optional)">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">File Upload</label>
                            <input type="file" class="form-control" name="additional_file[]" accept=".pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.png,.jpg,.jpeg">
                        </div>
                        <div class="col-md-1 d-grid">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-additional-file" style="display:none;"><i class="bi bi-x"></i></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addAdditionalFile"><i class="bi bi-plus"></i> Add File</button>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Instruction</label>
                    <textarea name="instruction" class="form-control" rows="3"><?php echo htmlspecialchars($row['instruction']??''); ?></textarea>
                </div>
                <div class="col-md-12">
                    <label class="form-label">** Campaigns Custom Questions / Qualifier Questions</label>
                    <div id="customFields" class="mb-2"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addField"><i class="bi bi-plus"></i> Add Question</button>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Save</button>
                    <a href="list" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.querySelectorAll('.select2').forEach(function(el){$(el).select2({width:'100%'});});
const supSel=document.getElementById('suppression_yes');
const supWrap=document.getElementById('suppression_file_wrap');
const talSel=document.getElementById('tal_yes');
const talWrap=document.getElementById('tal_file_wrap');
function toggleWrap(sel,wrap){wrap.style.display=(sel.value==='Yes')?'block':'none';}
supSel.addEventListener('change',()=>toggleWrap(supSel,supWrap));
talSel.addEventListener('change',()=>toggleWrap(talSel,talWrap));
toggleWrap(supSel,supWrap);toggleWrap(talSel,talWrap);
document.getElementById('addAdditionalFile')?.addEventListener('click', function(){
  const wrap = document.getElementById('additionalFiles');
  if (!wrap) return;
  const first = wrap.querySelector('.additional-file-row');
  if (!first) return;
  const clone = first.cloneNode(true);
  clone.querySelectorAll('input').forEach(i => { i.value=''; });
  const btn = clone.querySelector('.remove-additional-file');
  if (btn) btn.style.display = 'block';
  wrap.insertBefore(clone, document.getElementById('addAdditionalFile'));
});
document.getElementById('additionalFiles')?.addEventListener('click', function(e){
  const btn = e.target.closest('.remove-additional-file');
  if (!btn) return;
  const row = btn.closest('.additional-file-row');
  if (row) row.remove();
});
const existingCustom = <?php echo json_encode(is_array($row['custom_fields_json']??null)?$row['custom_fields_json']:[]); ?>;
const cf = document.getElementById('customFields');
existingCustom.forEach(function(item){
  const row = document.createElement('div');
  row.className='row g-2 align-items-center mb-2';
  const opts = Array.isArray(item.options)?item.options.join(', '):'';
  const type = item.type||'text';
  row.innerHTML = `<div class=\"col-md-4\"><input type=\"text\" class=\"form-control\" name=\"custom_label[]\" value=\"${item.label||''}\" placeholder=\"Question\"></div>
                   <div class=\"col-md-3\">
                       <select name=\"custom_type[]\" class=\"form-select\">
                           <option value=\"text\" ${type==='text'?'selected':''}>Text / Input</option>
                           <option value=\"dropdown\" ${type==='dropdown'?'selected':''}>Dropdown (Select One)</option>
                           <option value=\"choice\" ${type==='choice'?'selected':''}>Radio (Select One)</option>
                           <option value=\"multichoice\" ${type==='multichoice'?'selected':''}>Checkbox (Select Multiple)</option>
                       </select>
                   </div>
                   <div class=\"col-md-3\"><input type=\"text\" class=\"form-control\" name=\"custom_value[]\" value=\"${item.value||''}\" placeholder=\"Default Answer\"></div>
                   <div class=\"col-md-2\"><input type=\"text\" class=\"form-control\" name=\"custom_options[]\" value=\"${opts}\" placeholder=\"Options (comma separated)\"></div>
                   <div class=\"col-md-12 text-end\"><button type=\"button\" class=\"btn btn-outline-danger btn-sm\">Remove Question</button></div>`;
  row.querySelector('button').addEventListener('click',()=>row.remove());
  cf.appendChild(row);
});
document.getElementById('addField').addEventListener('click',function(){
  const row = document.createElement('div');
  row.className='row g-2 align-items-center mb-2';
  row.innerHTML = `<div class=\"col-md-4\"><input type=\"text\" class=\"form-control\" name=\"custom_label[]\" placeholder=\"Question\"></div>
                   <div class=\"col-md-3\">
                       <select name=\"custom_type[]\" class=\"form-select\">
                           <option value=\"text\">Text / Input</option>
                           <option value=\"dropdown\">Dropdown (Select One)</option>
                           <option value=\"choice\">Radio (Select One)</option>
                           <option value=\"multichoice\">Checkbox (Select Multiple)</option>
                       </select>
                   </div>
                   <div class=\"col-md-3\"><input type=\"text\" class=\"form-control\" name=\"custom_value[]\" placeholder=\"Default Answer\"></div>
                   <div class=\"col-md-2\"><input type=\"text\" class=\"form-control\" name=\"custom_options[]\" placeholder=\"Options (comma separated)\"></div>
                   <div class=\"col-md-12 text-end\"><button type=\"button\" class=\"btn btn-outline-danger btn-sm\">Remove Question</button></div>`;
  row.querySelector('button').addEventListener('click',()=>row.remove());
  cf.appendChild(row);
});
function handleOther(selId, inputId){
  const sel=document.getElementById(selId); const inp=document.getElementById(inputId);
  function update(){ const vals=$(sel).val()||[]; inp.style.display = Array.isArray(vals) && vals.includes('Other') ? 'block' : 'none'; }
  $(sel).on('change',update); update();
}
handleOther('geoSel','geoOther');
handleOther('deptSel','deptOther');
handleOther('levelSel','levelOther');
handleOther('empSel','empOther');
handleOther('indSel','indOther');
handleOther('revSel','revOther');
</script>
<script>
function handleOtherSimple(selId, inputId){
  const sel=document.getElementById(selId); const inp=document.getElementById(inputId);
  function update(){ inp.style.display = sel.value==='Other' ? 'block' : 'none'; }
  sel.addEventListener('change',update); update();
}
handleOtherSimple('deliveryFormatSel','deliveryFormatOther');
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
