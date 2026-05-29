<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(getKnownRoles());
ensureCsrfToken();
ensureTaskManagementSchema();
ensureTeamSchema();

$conn = getDbConnection();
$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$userName = (string)($user['full_name'] ?? '');
if (function_exists('userHasPermission') && !userHasPermission('tasks.create')) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$priorityOptions = ['Critical','High','Medium','Low'];
$statusOptions = ['Not Started','Assigned','In Progress','Waiting For Input','On Hold','Under Review','Completed','Rejected','Cancelled','Overdue'];
$typeOptions = [
    'General Task',
    'Campaign Task',
    'Lead Task',
    'QA Task',
    'DBMS Task',
    'Client Task',
    'Vendor Task',
    'HR Task',
    'Payroll Task',
    'Follow-up Task',
    'Escalation Task',
];

$message = '';
$messageType = 'success';

$sanitize = function(?string $v, int $maxLen = 5000): string {
    $v = (string)$v;
    $v = str_replace(["\0", "\r"], ['', "\n"], $v);
    $v = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $v);
    $v = trim($v);
    $v = preg_replace("/[ \\t]+/", ' ', $v);
    $v = preg_replace("/\\n{3,}/", "\n\n", $v);
    if ($maxLen > 0 && strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
    return $v;
};

$departments = [];
$resDept = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> '' ORDER BY department");
if ($resDept) {
    while ($r = $resDept->fetch_assoc()) { $departments[] = (string)($r['department'] ?? ''); }
}

$internalUsers = [];
$resU = $conn->query("SELECT id, full_name, role, department FROM users WHERE is_active = 1 AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) ORDER BY full_name");
if ($resU) {
    while ($r = $resU->fetch_assoc()) { $internalUsers[] = $r; }
}

$teams = [];
$resT = $conn->query("SELECT id, team_name FROM teams ORDER BY team_name");
if ($resT) {
    while ($r = $resT->fetch_assoc()) { $teams[] = $r; }
}

$campaigns = [];
$resC = $conn->query("SELECT id, name FROM campaigns ORDER BY name");
if ($resC) {
    while ($r = $resC->fetch_assoc()) { $campaigns[] = $r; }
}

$clients = [];
$resCl = $conn->query("SELECT id, client_code, name FROM clients ORDER BY name");
if ($resCl) {
    while ($r = $resCl->fetch_assoc()) { $clients[] = $r; }
}

$vendors = [];
$resV = $conn->query("SELECT id, vendor_code, name FROM vendors WHERE is_active = 1 ORDER BY name");
if ($resV) {
    while ($r = $resV->fetch_assoc()) { $vendors[] = $r; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $title = $sanitize($_POST['title'] ?? '', 255);
        $category = $sanitize($_POST['category'] ?? '', 80);
        $department = $sanitize($_POST['department'] ?? '', 80);
        $relatedDepartment = $sanitize($_POST['related_department'] ?? '', 80);
        $priority = $sanitize($_POST['priority'] ?? 'Medium', 16);
        $status = $sanitize($_POST['status'] ?? 'Assigned', 30);
        $taskType = $sanitize($_POST['task_type'] ?? 'General Task', 40);
        $expectedHours = trim((string)($_POST['expected_hours'] ?? ''));
        $description = $sanitize($_POST['description'] ?? '', 5000);
        $instructions = $sanitize($_POST['instructions'] ?? '', 5000);
        $notes = $sanitize($_POST['notes'] ?? '', 5000);

        $startDate = $sanitize($_POST['start_date'] ?? '', 20);
        $dueDate = $sanitize($_POST['due_date'] ?? '', 20);
        $dueTime = $sanitize($_POST['due_time'] ?? '', 10);

        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $salesLeadId = (int)($_POST['sales_lead_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $teamId = (int)($_POST['team_id'] ?? 0);

        $assigneeIds = $_POST['assignee_ids'] ?? [];
        if (!is_array($assigneeIds)) $assigneeIds = [];
        $assignees = [];
        foreach ($assigneeIds as $id) {
            $id = (int)$id;
            if ($id > 0) $assignees[$id] = true;
        }

        if ($teamId > 0) {
            $stmt = $conn->prepare("SELECT user_id FROM team_members WHERE team_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $teamId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmt->close();
                foreach ($rows as $r) { $mid = (int)($r['user_id'] ?? 0); if ($mid > 0) $assignees[$mid] = true; }
            }
        }

        $assignees = array_keys($assignees);

        $checklistItems = $_POST['checklist_items'] ?? [];
        if (!is_array($checklistItems)) $checklistItems = [];
        $checklist = [];
        foreach ($checklistItems as $it) {
            $it = $sanitize($it, 255);
            if ($it !== '') $checklist[] = $it;
        }

        $startAt = null;
        if ($startDate !== '' && strtotime($startDate) !== false) $startAt = date('Y-m-d 00:00:00', strtotime($startDate));

        $dueAt = null;
        if ($dueDate !== '' && strtotime($dueDate) !== false) {
            $timePart = '23:59:00';
            if ($dueTime !== '' && preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $dueTime)) $timePart = $dueTime . ':00';
            $dueAt = date('Y-m-d', strtotime($dueDate)) . ' ' . $timePart;
        }

        $eh = null;
        if ($expectedHours !== '' && is_numeric($expectedHours)) {
            $ehVal = (float)$expectedHours;
            if ($ehVal >= 0) $eh = $ehVal;
        }

        if ($title === '' || $department === '') {
            $message = 'Task Title and Department are required.';
            $messageType = 'danger';
        } elseif (!in_array($priority, $priorityOptions, true)) {
            $message = 'Invalid priority.';
            $messageType = 'danger';
        } elseif (!in_array($status, $statusOptions, true)) {
            $message = 'Invalid status.';
            $messageType = 'danger';
        } elseif (!in_array($taskType, $typeOptions, true)) {
            $message = 'Invalid task type.';
            $messageType = 'danger';
        } elseif (empty($assignees)) {
            $message = 'Select at least one assignee or a team.';
            $messageType = 'danger';
        } else {
            $taskCode = '';
            $tries = 0;
            while ($tries < 5) {
                $tries++;
                $taskCode = generateTaskCode();
                $stmt = $conn->prepare("INSERT INTO tasks (task_code, title, category, department, related_department, priority, status, progress, task_type, description, instructions, notes, expected_hours, start_at, due_at, campaign_id, lead_id, sales_lead_id, client_id, vendor_id, team_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
                if (!$stmt) { $taskCode = ''; break; }
                $prog = 0;
                $cid = $campaignId > 0 ? $campaignId : null;
                $lid = $leadId > 0 ? $leadId : null;
                $slid = $salesLeadId > 0 ? $salesLeadId : null;
                $clid = $clientId > 0 ? $clientId : null;
                $vid = $vendorId > 0 ? $vendorId : null;
                $tid = $teamId > 0 ? $teamId : null;
                $stmt->bind_param(
                    'sssssssisisssdssiiiiiii',
                    $taskCode,
                    $title,
                    ($category !== '' ? $category : null),
                    $department,
                    ($relatedDepartment !== '' ? $relatedDepartment : null),
                    $priority,
                    $status,
                    $prog,
                    $taskType,
                    ($description !== '' ? $description : null),
                    ($instructions !== '' ? $instructions : null),
                    ($notes !== '' ? $notes : null),
                    $eh,
                    $startAt,
                    $dueAt,
                    $cid,
                    $lid,
                    $slid,
                    $clid,
                    $vid,
                    $tid,
                    $userId
                );
                $ok = @$stmt->execute();
                $taskId = (int)$stmt->insert_id;
                $stmt->close();
                if ($ok && $taskId > 0) break;
                $taskCode = '';
            }

            if ($taskCode === '') {
                $message = 'Unable to create task.';
                $messageType = 'danger';
            } else {
                foreach ($assignees as $aid) {
                    $stmt = $conn->prepare("INSERT IGNORE INTO task_assignees (task_id, user_id, assigned_by, assigned_at) VALUES (?,?,?,NOW())");
                    if ($stmt) {
                        $stmt->bind_param('iii', $taskId, $aid, $userId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $i = 0;
                foreach ($checklist as $it) {
                    $i++;
                    $stmt = $conn->prepare("INSERT INTO task_checklist_items (task_id, item_text, is_done, sort_order, created_by, created_at) VALUES (?,?,0,?,?,NOW())");
                    if ($stmt) {
                        $stmt->bind_param('isii', $taskId, $it, $i, $userId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $conn->query("UPDATE tasks SET checklist_total = (SELECT COUNT(*) FROM task_checklist_items WHERE task_id = $taskId), checklist_done = (SELECT SUM(CASE WHEN is_done=1 THEN 1 ELSE 0 END) FROM task_checklist_items WHERE task_id = $taskId) WHERE id = $taskId");

                $uploadsDir = __DIR__ . '/../../uploads/task_files';
                if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0775, true);
                $allowedExt = ['png','jpg','jpeg','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt','mp3','wav','m4a','aac','ogg','zip'];
                if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
                    $names = $_FILES['attachments']['name'];
                    $tmps = $_FILES['attachments']['tmp_name'];
                    $errs = $_FILES['attachments']['error'];
                    $sizes = $_FILES['attachments']['size'];
                    $typesF = $_FILES['attachments']['type'];
                    for ($fi = 0; $fi < count($names); $fi++) {
                        if (($errs[$fi] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                        $orig = (string)($names[$fi] ?? '');
                        $tmp = (string)($tmps[$fi] ?? '');
                        $size = (int)($sizes[$fi] ?? 0);
                        if ($tmp === '' || !is_uploaded_file($tmp)) continue;
                        if ($size <= 0 || $size > (25 * 1024 * 1024)) continue;
                        $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                        if ($ext === '' || !in_array($ext, $allowedExt, true)) continue;
                        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)pathinfo($orig, PATHINFO_FILENAME));
                        $base = $base !== '' ? $base : 'file';
                        $destName = 'T' . $taskId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $base . '.' . $ext;
                        $destAbs = $uploadsDir . '/' . $destName;
                        $rel = 'uploads/task_files/' . $destName;
                        if (!@move_uploaded_file($tmp, $destAbs)) continue;
                        $mime = (string)($typesF[$fi] ?? '');
                        $stmt = $conn->prepare("INSERT INTO task_files (task_id, user_id, file_path, original_name, file_size, mime_type, created_at) VALUES (?,?,?,?,?,?,NOW())");
                        if ($stmt) {
                            $stmt->bind_param('iissis', $taskId, $userId, $rel, $orig, $size, $mime);
                            $stmt->execute();
                            $stmt->close();
                        }
                        logTaskActivity($taskId, $userId, 'file_uploaded', ['file' => $orig]);
                    }
                }

                logTaskActivity($taskId, $userId, 'task_created', ['task_code' => $taskCode]);
                logTaskActivity($taskId, $userId, 'assigned', ['assignees' => $assignees, 'team_id' => $teamId > 0 ? $teamId : null]);
                if (!empty($checklist)) logTaskActivity($taskId, $userId, 'checklist_added', ['count' => count($checklist)]);

                $link = appBasePath() . '/modules/tasks/view?id=' . $taskId;
                notifyTaskUsers($assignees, 'task.assigned', 'New task assigned', $taskCode . ' · ' . $title, $link, [
                    'importance' => 'high',
                    'show_toast' => true,
                    'dedup_key' => 'task_assigned:' . $taskId,
                    'dedup_window_min' => 10,
                ]);

                header('Location: ' . $link);
                exit;
            }
        }
    }
}

$pageTitle = 'Create Task';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Create Task</h3>
      <div class="text-muted small">Create an enterprise-grade task with assignments, checklist, attachments and deadlines.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-light fw-semibold">Basic Information</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label small text-muted">Task Title</label>
                <input class="form-control" name="title" value="<?php echo htmlspecialchars((string)($_POST['title'] ?? '')); ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label small text-muted">Task Category</label>
                <input class="form-control" name="category" value="<?php echo htmlspecialchars((string)($_POST['category'] ?? '')); ?>" placeholder="e.g. Dashboard, Outreach, QA Fix">
              </div>
              <div class="col-md-6">
                <label class="form-label small text-muted">Department</label>
                <select class="form-select" name="department" required>
                  <option value="">Select Department</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ((string)($_POST['department'] ?? '') === $d) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small text-muted">Priority</label>
                <select class="form-select" name="priority">
                  <?php foreach ($priorityOptions as $p): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ((string)($_POST['priority'] ?? 'Medium') === $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small text-muted">Status</label>
                <select class="form-select" name="status">
                  <?php foreach ($statusOptions as $s): ?>
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ((string)($_POST['status'] ?? 'Assigned') === $s) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small text-muted">Task Type</label>
                <select class="form-select" name="task_type">
                  <?php foreach ($typeOptions as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ((string)($_POST['task_type'] ?? 'General Task') === $t) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small text-muted">Expected Completion Hours</label>
                <input class="form-control" name="expected_hours" value="<?php echo htmlspecialchars((string)($_POST['expected_hours'] ?? '')); ?>" placeholder="e.g. 4">
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-light fw-semibold">Timeline</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small text-muted">Start Date</label>
                <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars((string)($_POST['start_date'] ?? '')); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label small text-muted">Due Date</label>
                <input type="date" class="form-control" name="due_date" value="<?php echo htmlspecialchars((string)($_POST['due_date'] ?? '')); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label small text-muted">Due Time</label>
                <input type="time" class="form-control" name="due_time" value="<?php echo htmlspecialchars((string)($_POST['due_time'] ?? '')); ?>">
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-light fw-semibold">Additional Details</div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label small text-muted">Task Description</label>
              <textarea class="form-control" name="description" rows="5"><?php echo htmlspecialchars((string)($_POST['description'] ?? '')); ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label small text-muted">Instructions</label>
              <textarea class="form-control" name="instructions" rows="4"><?php echo htmlspecialchars((string)($_POST['instructions'] ?? '')); ?></textarea>
            </div>
            <div class="mb-0">
              <label class="form-label small text-muted">Notes</label>
              <textarea class="form-control" name="notes" rows="3"><?php echo htmlspecialchars((string)($_POST['notes'] ?? '')); ?></textarea>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
            <span>Checklist</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addChecklistBtn"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
          </div>
          <div class="card-body">
            <div id="checklistWrap" class="d-flex flex-column gap-2"></div>
            <div class="text-muted small mt-2">Checklist completion auto-updates task progress for assignees.</div>
          </div>
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-header bg-light fw-semibold">Attachments</div>
          <div class="card-body">
            <input type="file" class="form-control" name="attachments[]" multiple>
            <div class="text-muted small mt-2">Supports documents, images and audio recordings.</div>
          </div>
        </div>
      </div>

      <div class="col-xl-4">
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-light fw-semibold">Assignment</div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label small text-muted">Assigned By</label>
              <input class="form-control" value="<?php echo htmlspecialchars($userName); ?>" disabled>
            </div>
            <div class="mb-3">
              <label class="form-label small text-muted">Assigned To (Multiple)</label>
              <select class="form-select" name="assignee_ids[]" multiple size="10" required>
                <?php
                  $sel = $_POST['assignee_ids'] ?? [];
                  if (!is_array($sel)) $sel = [];
                  $selSet = [];
                  foreach ($sel as $x) { $selSet[(int)$x] = true; }
                ?>
                <?php foreach ($internalUsers as $u): ?>
                  <option value="<?php echo (int)$u['id']; ?>" <?php echo !empty($selSet[(int)$u['id']]) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?><?php echo ($u['department'] ?? '') !== '' ? ' · ' . htmlspecialchars((string)$u['department']) : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="text-muted small mt-1">Hold Ctrl/Command to select multiple users.</div>
            </div>
            <div class="mb-0">
              <label class="form-label small text-muted">Team Assignment (Optional)</label>
              <select class="form-select" name="team_id">
                <option value="0">None</option>
                <?php foreach ($teams as $t): ?>
                  <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)($_POST['team_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string)($t['team_name'] ?? '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="text-muted small mt-1">If selected, all team members are added as assignees.</div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-light fw-semibold">CRM Links</div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label small text-muted">Campaign (Optional)</label>
              <select class="form-select" name="campaign_id">
                <option value="0">None</option>
                <?php foreach ($campaigns as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)($_POST['campaign_id'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string)($c['name'] ?? '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small text-muted">Client (Optional)</label>
              <select class="form-select" name="client_id">
                <option value="0">None</option>
                <?php foreach ($clients as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)($_POST['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string)($c['client_code'] ?? '') . ' · ' . (string)($c['name'] ?? '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small text-muted">Vendor (Optional)</label>
              <select class="form-select" name="vendor_id">
                <option value="0">None</option>
                <?php foreach ($vendors as $v): ?>
                  <option value="<?php echo (int)$v['id']; ?>" <?php echo (int)($_POST['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string)($v['vendor_code'] ?? '') . ' · ' . (string)($v['name'] ?? '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small text-muted">Lead ID (Optional)</label>
              <input class="form-control" name="lead_id" value="<?php echo htmlspecialchars((string)($_POST['lead_id'] ?? '')); ?>" placeholder="Internal lead numeric ID">
            </div>
            <div class="mb-0">
              <label class="form-label small text-muted">Sales Prospect ID (Optional)</label>
              <input class="form-control" name="sales_lead_id" value="<?php echo htmlspecialchars((string)($_POST['sales_lead_id'] ?? '')); ?>" placeholder="Sales lead numeric ID">
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <div class="d-grid">
              <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Create Task</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
(function(){
  const wrap = document.getElementById('checklistWrap');
  const btn = document.getElementById('addChecklistBtn');
  if (!wrap || !btn) return;

  function addItem(value){
    const row = document.createElement('div');
    row.className = 'd-flex gap-2';
    row.innerHTML = '<input class="form-control" name="checklist_items[]" placeholder="Checklist item" value="">'
      + '<button type="button" class="btn btn-light border"><i class="bi bi-x"></i></button>';
    const input = row.querySelector('input');
    const del = row.querySelector('button');
    if (input && typeof value === 'string') input.value = value;
    del?.addEventListener('click', () => row.remove());
    wrap.appendChild(row);
    input?.focus();
  }

  btn.addEventListener('click', () => addItem(''));

  const preset = <?php
    $pre = $_POST['checklist_items'] ?? [];
    if (!is_array($pre)) $pre = [];
    $pre = array_values(array_filter(array_map(fn($x) => trim((string)$x), $pre), fn($x) => $x !== ''));
    echo json_encode($pre, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ?>;
  if (preset.length) preset.forEach(v => addItem(String(v)));
})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
