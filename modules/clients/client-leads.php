<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','client_admin','client_sdr']);
ensureCsrfToken();

$user = getCurrentUser();
$clientId = (int)($user['client_id'] ?? 0);
if (isAdmin()) {
    $clientId = (int)($_GET['client_id'] ?? $clientId);
}
if ($clientId <= 0 && !isAdmin()) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    $_SESSION['access_denied_context'] = [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'required_roles' => ['admin','client_admin','client_sdr'],
        'time' => time(),
    ];
    header("Location: " . appBasePath() . "/modules/auth/access-denied");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array((string)$_POST['action'], ['get_tagging','add_tag','remove_tag','edit_tag_activity'], true)) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid token']); exit;
    }

    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid lead']); exit; }

    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT l.id, l.qa_status, l.client_delivery_status
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE l.id = ? AND d.client_id = ?
        LIMIT 1
    ");
    if (!$stmt) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
    $stmt->bind_param('ii', $leadId, $clientId);
    $stmt->execute();
    $leadRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$leadRow) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

    $cds = normalizeClientDeliveryStatus((string)($leadRow['client_delivery_status'] ?? 'Pending'));
    if (!isAdmin() && $cds !== 'Delivered') {
        echo json_encode(['ok' => false, 'error' => 'Tagging allowed only for delivered leads']); exit;
    }

    $action = (string)$_POST['action'];
    $userId = (int)($user['id'] ?? 0);
    $canRemove = isAdmin() || isClientAdmin();
    $canEditAll = isAdmin() || isClientAdmin();

    if ($action === 'add_tag') {
        $tagName = trim((string)($_POST['tag_name'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $stage = trim((string)($_POST['stage'] ?? ''));
        if ($tagName === '') { echo json_encode(['ok' => false, 'error' => 'Tag is required']); exit; }
        $names = array_values(array_filter(array_map('trim', preg_split('/[,]+/', $tagName)), fn($v) => $v !== ''));
        if (empty($names)) { echo json_encode(['ok' => false, 'error' => 'Tag is required']); exit; }
        $ok = true;
        foreach ($names as $n) {
            $ok = addTagToLead($leadId, $n, $userId, $note, $stage) && $ok;
        }
        $editableId = 0;
        $stmtE = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($stmtE) { $stmtE->bind_param('ii', $leadId, $userId); $stmtE->execute(); $editableId = (int)($stmtE->get_result()->fetch_row()[0] ?? 0); $stmtE->close(); }
        echo json_encode(['ok' => $ok, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $editableId, 'can_edit_all' => $canEditAll]); exit;
    }

    if ($action === 'remove_tag') {
        if (!$canRemove) { echo json_encode(['ok' => false, 'error' => 'Not allowed']); exit; }
        $tagIdToRemove = (int)($_POST['tag_id'] ?? 0);
        if ($tagIdToRemove <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid tag']); exit; }
        $ok = removeTagFromLead($leadId, $tagIdToRemove, $userId);
        $editableId = 0;
        $stmtE = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($stmtE) { $stmtE->bind_param('ii', $leadId, $userId); $stmtE->execute(); $editableId = (int)($stmtE->get_result()->fetch_row()[0] ?? 0); $stmtE->close(); }
        echo json_encode(['ok' => $ok, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $editableId, 'can_edit_all' => $canEditAll]); exit;
    }

    if ($action === 'edit_tag_activity') {
        $activityId = (int)($_POST['activity_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $stage = trim((string)($_POST['stage'] ?? ''));
        if ($activityId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid activity']); exit; }

        $stmtA = $conn->prepare("SELECT id, actor_id, action, meta_json FROM lead_activity WHERE id = ? AND lead_id = ? LIMIT 1");
        if (!$stmtA) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmtA->bind_param('ii', $activityId, $leadId);
        $stmtA->execute();
        $aRow = $stmtA->get_result()->fetch_assoc();
        $stmtA->close();
        if (!$aRow || (string)($aRow['action'] ?? '') !== 'lead_tag_added') { echo json_encode(['ok' => false, 'error' => 'Not editable']); exit; }

        $actorId = (int)($aRow['actor_id'] ?? 0);
        if (!$canEditAll && $actorId !== $userId) { echo json_encode(['ok' => false, 'error' => 'Not allowed']); exit; }

        $latestId = 0;
        if ($canEditAll) {
            $stmtL = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' ORDER BY created_at DESC, id DESC LIMIT 1");
            if ($stmtL) { $stmtL->bind_param('i', $leadId); $stmtL->execute(); $latestId = (int)($stmtL->get_result()->fetch_row()[0] ?? 0); $stmtL->close(); }
        } else {
            $stmtL = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
            if ($stmtL) { $stmtL->bind_param('ii', $leadId, $userId); $stmtL->execute(); $latestId = (int)($stmtL->get_result()->fetch_row()[0] ?? 0); $stmtL->close(); }
        }
        if ($latestId <= 0 || $latestId !== $activityId) { echo json_encode(['ok' => false, 'error' => 'Only last tag note can be edited']); exit; }

        $meta = !empty($aRow['meta_json']) ? json_decode((string)$aRow['meta_json'], true) : [];
        if (!is_array($meta)) $meta = [];
        $meta['note'] = $note;
        $meta['stage'] = $stage;
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) $metaJson = null;

        $stmtU = $conn->prepare("UPDATE lead_activity SET meta_json = ? WHERE id = ? LIMIT 1");
        if (!$stmtU) { echo json_encode(['ok' => false, 'error' => 'Database error']); exit; }
        $stmtU->bind_param('si', $metaJson, $activityId);
        $ok = $stmtU->execute();
        $stmtU->close();
        if ($ok) {
            $tagName = (string)($meta['tag'] ?? '');
            logLeadActivity($leadId, $userId, 'lead_tag_edited', ['tag' => $tagName, 'note' => $note, 'stage' => $stage, 'activity_id' => $activityId]);
        }
        echo json_encode(['ok' => $ok, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $latestId, 'can_edit_all' => $canEditAll]); exit;
    }

    $editableId = 0;
    $stmtE = $conn->prepare("SELECT id FROM lead_activity WHERE lead_id = ? AND action = 'lead_tag_added' AND actor_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    if ($stmtE) { $stmtE->bind_param('ii', $leadId, $userId); $stmtE->execute(); $editableId = (int)($stmtE->get_result()->fetch_row()[0] ?? 0); $stmtE->close(); }
    echo json_encode(['ok' => true, 'current' => getLeadTagAssignments($leadId), 'timeline' => getLeadTagTimeline($leadId), 'can_remove' => $canRemove, 'editable_activity_id' => $editableId, 'can_edit_all' => $canEditAll]); exit;
}

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, (int)($_GET['per_page'] ?? 25));
$perPage = min(500, $perPage);
$tagId = (int)($_GET['tag_id'] ?? 0);

$filters = [];
$filters['client_id'] = $clientId;
if ($campaignId > 0) $filters['campaign_id'] = $campaignId;
if ($q !== '') $filters['search'] = $q;
$filters['client_delivery_status'] = 'Delivered';
if ($tagId > 0) $filters['tag_id'] = $tagId;

$data = getLeads($filters, $perPage, $page);
$rows = $data['leads'] ?? [];
$total = (int)($data['total'] ?? 0);
$pages = (int)($data['totalPages'] ?? 1);
$offset = ($page - 1) * $perPage;

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT c.id, c.name FROM campaigns c JOIN campaign_details d ON d.campaign_id=c.id WHERE d.client_id=? ORDER BY c.name");
$stmt->bind_param('i', $clientId);
$stmt->execute();
$campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

ensureLeadTagsSchema();
$leadTagsMap = [];
if (!empty($rows)) {
    $leadIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows), fn($v) => $v > 0));
    if (!empty($leadIds)) {
        $in = implode(',', array_fill(0, count($leadIds), '?'));
        $types = str_repeat('i', count($leadIds));
        $stmt = $conn->prepare("SELECT lt.lead_id, t.name FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id WHERE lt.lead_id IN ($in) ORDER BY lt.added_at DESC");
        if ($stmt) {
            $stmt->bind_param($types, ...$leadIds);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $lid = (int)($r['lead_id'] ?? 0);
                $name = trim((string)($r['name'] ?? ''));
                if ($lid <= 0 || $name === '') continue;
                if (!isset($leadTagsMap[$lid])) $leadTagsMap[$lid] = [];
                if (!in_array($name, $leadTagsMap[$lid], true)) $leadTagsMap[$lid][] = $name;
            }
            $stmt->close();
        }
    }
}

ensureLeadTagsSchema();
$tagOptions = [];
$stmt = $conn->prepare("SELECT DISTINCT t.id, t.name FROM tags t JOIN lead_tags lt ON lt.tag_id = t.id JOIN leads l ON l.id = lt.lead_id JOIN campaign_details d ON d.campaign_id = l.campaign_id WHERE d.client_id = ? ORDER BY t.name");
$stmt->bind_param('i', $clientId);
$stmt->execute();
$tagOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$pageTitle = 'Client Leads';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Client Leads</div>
      <div class="text-muted small">Leads visible to your account</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="client-campaigns.php"><i class="bi bi-megaphone me-1"></i>Campaigns</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <?php if (isAdmin()): ?>
          <div class="col-md-3">
            <label class="form-label small text-muted">Client ID</label>
            <input class="form-control form-control-sm" name="client_id" value="<?php echo (int)$clientId; ?>">
          </div>
        <?php endif; ?>
        <div class="col-md-4">
          <label class="form-label small text-muted">Campaign</label>
          <select class="form-select form-select-sm" name="campaign_id">
            <option value="">All</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$campaignId === (int)$c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name'] ?? ''); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">Tag</label>
          <select class="form-select form-select-sm" name="tag_id">
            <option value="">All</option>
            <?php foreach ($tagOptions as $t): ?>
              <option value="<?php echo (int)$t['id']; ?>" <?php echo ($tagId === (int)$t['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name'] ?? ''); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="ID, email, company, name">
        </div>
        <div class="col-md-1">
          <label class="form-label small text-muted">Rows</label>
          <select class="form-select form-select-sm" name="per_page">
            <?php foreach ([10,25,50,100,250,500] as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-grid">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
        <div class="col-md-1 d-grid">
          <a class="btn btn-light border btn-sm" href="client-leads.php">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
      <div class="fw-semibold">Leads</div>
      <div class="text-muted small">
        <?php
          $from = $total > 0 ? ($offset + 1) : 0;
          $to = min($total, $offset + count($rows));
        ?>
        Showing <?php echo (int)$from; ?>–<?php echo (int)$to; ?> of <?php echo (int)$total; ?>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">#</th>
              <th>Date</th>
              <th>Lead</th>
              <th>Email</th>
              <th>Company</th>
              <th>Campaign</th>
              <th>Delivery</th>
              <th>Tags</th>
              <th>Assigned SDR</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="10" class="text-center text-muted py-4">No leads found.</td></tr>
            <?php else: foreach ($rows as $idx => $r): ?>
              <?php
                $lid = (int)($r['id'] ?? 0);
                $tags = $lid > 0 ? ($leadTagsMap[$lid] ?? []) : [];
                $maxChips = 3;
                $shown = array_slice($tags, 0, $maxChips);
                $more = max(0, count($tags) - count($shown));
              ?>
              <tr>
                <td class="ps-3 text-muted small"><?php echo (int)($offset + (int)$idx + 1); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(substr((string)($r['created_at'] ?? ''), 0, 10)); ?></td>
                <td class="fw-semibold"><?php echo htmlspecialchars(((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')) ?: '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['company_name'] ?? '—'); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['campaign_name'] ?? '—'); ?></td>
                <td>
                  <span class="badge bg-success-subtle text-success border">Delivered</span>
                </td>
                <td>
                  <?php if (empty($shown)): ?>
                    <span class="text-muted small">—</span>
                  <?php else: ?>
                    <div class="d-flex flex-wrap gap-1">
                      <?php foreach ($shown as $tname): ?>
                        <span class="badge rounded-pill bg-light text-dark border"><?php echo htmlspecialchars($tname); ?></span>
                      <?php endforeach; ?>
                      <?php if ($more > 0): ?>
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary border">+<?php echo (int)$more; ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <?php
                  $assignedName = (string)($r['assigned_to_name'] ?? '');
                  $assignedRole = (string)($r['assigned_to_role'] ?? '');
                  $assignedShow = ($assignedName !== '' && ($assignedRole === 'client_sdr' || $assignedRole === 'client_admin')) ? $assignedName : '—';
                ?>
                <td class="text-muted small"><?php echo htmlspecialchars($assignedShow); ?></td>
                <td class="text-end pe-3">
                  <div class="d-flex justify-content-end gap-1">
                    <button class="btn btn-outline-primary btn-xs" type="button"
                      data-tag-lead="1"
                      data-lead-id="<?php echo (int)($r['id'] ?? 0); ?>"
                      data-company="<?php echo htmlspecialchars($r['company_name'] ?? ''); ?>"
                      data-person="<?php echo htmlspecialchars(trim(((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')))); ?>"
                      title="Tag">
                      <i class="bi bi-tags"></i>
                    </button>
                    <a class="btn btn-outline-secondary btn-xs" href="client-lead-details.php?id=<?php echo (int)($r['id'] ?? 0); ?>" title="View Lead"><i class="bi bi-eye"></i></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer bg-light d-flex justify-content-between align-items-center">
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php
            $base = '?client_id='.(int)$clientId.'&campaign_id='.(int)$campaignId.'&tag_id='.(int)$tagId.'&q='.urlencode($q).'&per_page='.(int)$perPage;
            $startPg = max(1, $page - 2);
            $endPg = min($pages, $page + 2);
          ?>
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=1">First</a></li>
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo max(1, $page-1); ?>">Prev</a></li>
          <?php for ($p = $startPg; $p <= $endPg; $p++): ?>
            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo (int)$p; ?>"><?php echo (int)$p; ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo min($pages, $page+1); ?>">Next</a></li>
          <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $base; ?>&page=<?php echo (int)$pages; ?>">Last</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<div class="modal fade" id="tagLeadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-0 py-3">
        <div>
          <div class="h5 mb-0">Lead Tags</div>
          <div class="text-muted small" id="tagLeadSubtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3">
        <div class="alert alert-danger d-none mb-3" id="tagLeadError"></div>
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-light fw-semibold py-2">Current Tags</div>
              <div class="card-body">
                <div class="input-group input-group-sm mb-3">
                  <input class="form-control" id="tagLeadInput" placeholder="Type tag name and add">
                  <button class="btn btn-primary" type="button" id="tagLeadAddBtn"><i class="bi bi-plus"></i> Add</button>
                </div>
                <div class="row g-2 mb-3">
                  <div class="col-md-5">
                    <label class="form-label small text-muted">Stage</label>
                    <select class="form-select form-select-sm" id="tagLeadStage">
                      <option value="">—</option>
                      <option>New</option>
                      <option>Contacted</option>
                      <option>Follow Up</option>
                      <option>Meeting Booked</option>
                      <option>No Response</option>
                      <option>Wrong Contact</option>
                      <option>Not Interested</option>
                    </select>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label small text-muted">Note (optional)</label>
                    <textarea class="form-control form-control-sm" id="tagLeadNote" rows="2" placeholder="Add a note for this tagging action"></textarea>
                  </div>
                </div>
                <div class="d-flex flex-wrap gap-1 mb-3" id="tagLeadPresets"></div>
                <div class="border rounded p-3 mb-3 d-none bg-light" id="tagLeadEditBox">
                  <div class="small fw-semibold mb-2">Edit Last Tag Note</div>
                  <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                      <label class="form-label small text-muted mb-1">Stage</label>
                      <select class="form-select form-select-sm" id="tagLeadEditStage">
                        <option value="">—</option>
                        <option>New</option>
                        <option>Contacted</option>
                        <option>Follow Up</option>
                        <option>Meeting Booked</option>
                        <option>No Response</option>
                        <option>Wrong Contact</option>
                        <option>Not Interested</option>
                      </select>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label small text-muted mb-1">Note</label>
                      <input class="form-control form-control-sm" id="tagLeadEditNote" placeholder="Edit note">
                    </div>
                    <div class="col-md-3 d-grid">
                      <button class="btn btn-outline-primary btn-sm" type="button" id="tagLeadEditSave"><i class="bi bi-save"></i> Save</button>
                    </div>
                  </div>
                  <div class="text-muted small mt-2">Only the last tag note can be edited.</div>
                </div>
                <div class="border rounded p-2" style="min-height: 180px; max-height: 360px; overflow:auto;" id="tagLeadCurrent"></div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-light fw-semibold py-2">Tag Timeline</div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm table-striped mb-0 align-middle">
                    <thead class="table-light">
                      <tr>
                        <th class="ps-3">When</th>
                        <th>Action</th>
                        <th>Stage</th>
                        <th>Tag</th>
                        <th>Note</th>
                        <th class="pe-3">By</th>
                      </tr>
                    </thead>
                    <tbody id="tagLeadTimeline"></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const modalEl = document.getElementById('tagLeadModal');
  if (!modalEl || !window.bootstrap) return;

  const csrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const canRemoveDefault = <?php echo json_encode((bool)(isAdmin() || isClientAdmin())); ?>;
  const subtitleEl = document.getElementById('tagLeadSubtitle');
  const errEl = document.getElementById('tagLeadError');
  const currentEl = document.getElementById('tagLeadCurrent');
  const timelineEl = document.getElementById('tagLeadTimeline');
  const inputEl = document.getElementById('tagLeadInput');
  const stageEl = document.getElementById('tagLeadStage');
  const noteEl = document.getElementById('tagLeadNote');
  const addBtn = document.getElementById('tagLeadAddBtn');
  const presetsEl = document.getElementById('tagLeadPresets');
  const editBoxEl = document.getElementById('tagLeadEditBox');
  const editStageEl = document.getElementById('tagLeadEditStage');
  const editNoteEl = document.getElementById('tagLeadEditNote');
  const editSaveEl = document.getElementById('tagLeadEditSave');

  let activeLeadId = 0;
  let canRemove = canRemoveDefault;
  let editableActivityId = 0;
  let canEditAll = false;
  const currentUserId = <?php echo json_encode((int)($user['id'] ?? 0)); ?>;

  const esc = (s) => String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const fmt = (dt) => {
    const s = String(dt || '');
    if (!s) return '—';
    return s.replace('T', ' ').slice(0, 16);
  };

  function showErr(msg) {
    errEl.textContent = msg || 'Error';
    errEl.classList.remove('d-none');
  }
  function hideErr() {
    errEl.textContent = '';
    errEl.classList.add('d-none');
  }

  function renderCurrent(rows) {
    if (!Array.isArray(rows) || !rows.length) {
      currentEl.innerHTML = '<div class="text-muted small">No tags yet.</div>';
      return;
    }
    const html = rows.map(r => {
      const name = esc(r.tag_name);
      const role = String(r.added_by_role || '');
      const by = role.startsWith('client_') ? esc(r.added_by_name || 'System') : 'TaRaj Global Solutions';
      const when = fmt(r.added_at);
      const btn = canRemove ? `<button class="btn btn-light border btn-xs ms-2" data-remove-tag="${Number(r.tag_id)}" title="Remove"><i class="bi bi-x"></i></button>` : '';
      return `<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-2">
        <div>
          <div class="fw-semibold">${name}</div>
          <div class="text-muted small">by ${by} · ${esc(when)}</div>
        </div>
        <div>${btn}</div>
      </div>`;
    }).join('');
    currentEl.innerHTML = html;
  }

  function renderTimeline(rows) {
    if (!Array.isArray(rows) || !rows.length) {
      timelineEl.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No tagging activity.</td></tr>';
      return;
    }
    timelineEl.innerHTML = rows.map(r => {
      const act = String(r.action || '');
      const label = act === 'lead_tag_added' ? 'Added' : (act === 'lead_tag_removed' ? 'Removed' : (act === 'lead_tag_edited' ? 'Edited' : act));
      const stage = esc((r.meta && r.meta.stage) || '');
      const tag = esc((r.meta && (r.meta.tag || r.meta.tag_name)) || '');
      const note = esc((r.meta && (r.meta.note || r.meta.comment)) || '');
      const ar = String(r.actor_role || '');
      const who = ar.startsWith('client_') ? esc(r.actor_name || 'System') : 'TaRaj Global Solutions';
      const canEditThis = Number(r.id || 0) === Number(editableActivityId || 0);
      const editBtn = canEditThis ? `<button class="btn btn-light border btn-xs ms-2" type="button" data-edit-tag="${Number(r.id)}" title="Edit last note"><i class="bi bi-pencil"></i></button>` : '';
      return `<tr>
        <td class="ps-3 text-muted small">${esc(fmt(r.created_at))}</td>
        <td class="small fw-semibold">${esc(label)}</td>
        <td class="small text-muted">${stage || '—'}</td>
        <td class="small">${tag || '—'}</td>
        <td class="small text-muted">${note ? `<span class="d-inline-block text-truncate" style="max-width: 220px;" title="${note}">${note}</span>` : '—'}</td>
        <td class="pe-3 text-muted small">${who}${editBtn}</td>
      </tr>`;
    }).join('');
  }

  async function api(action, extra) {
    const body = new URLSearchParams();
    body.set('csrf_token', csrf);
    body.set('action', action);
    body.set('lead_id', String(activeLeadId));
    Object.entries(extra || {}).forEach(([k,v]) => body.set(k, String(v)));
    const res = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    const json = await res.json();
    if (!json || !json.ok) throw new Error((json && json.error) ? json.error : 'Request failed');
    canRemove = !!json.can_remove;
    editableActivityId = Number(json.editable_activity_id || 0);
    canEditAll = !!json.can_edit_all;
    renderCurrent(json.current || []);
    renderTimeline(json.timeline || []);
    if (editBoxEl) {
      if (editableActivityId > 0) editBoxEl.classList.remove('d-none');
      else editBoxEl.classList.add('d-none');
    }
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-tag-lead]');
    if (!btn) return;
    hideErr();
    activeLeadId = Number(btn.getAttribute('data-lead-id') || 0);
    const company = btn.getAttribute('data-company') || '';
    const person = btn.getAttribute('data-person') || '';
    subtitleEl.textContent = [company, person].filter(Boolean).join(' · ');
    inputEl.value = '';
    if (stageEl) stageEl.value = '';
    noteEl.value = '';
    if (editBoxEl) editBoxEl.classList.add('d-none');
    currentEl.innerHTML = '<div class="text-muted small">Loading…</div>';
    timelineEl.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Loading…</td></tr>';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    try {
      await api('get_tagging');
    } catch (err) {
      showErr(err.message || 'Unable to load tags');
      currentEl.innerHTML = '';
      timelineEl.innerHTML = '';
    }
  });

  addBtn.addEventListener('click', async () => {
    hideErr();
    const name = (inputEl.value || '').trim();
    if (!name) { showErr('Enter a tag name.'); return; }
    addBtn.disabled = true;
    try {
      const note = (noteEl.value || '').trim();
      const stage = stageEl ? (stageEl.value || '').trim() : '';
      await api('add_tag', { tag_name: name, note, stage });
      inputEl.value = '';
      if (stageEl) stageEl.value = '';
      noteEl.value = '';
    } catch (err) {
      showErr(err.message || 'Unable to add tag');
    } finally {
      addBtn.disabled = false;
    }
  });

  if (presetsEl) {
    const presets = ['Interested','Follow Up','Meeting Booked','No Response','Wrong Contact','Not Interested'];
    presetsEl.innerHTML = presets.map(p => `<button type="button" class="btn btn-light border btn-xs" data-tag-preset="${esc(p)}">${esc(p)}</button>`).join('');
    presetsEl.addEventListener('click', (e) => {
      const b = e.target.closest('[data-tag-preset]');
      if (!b) return;
      const val = b.getAttribute('data-tag-preset') || '';
      inputEl.value = val;
      if (stageEl) stageEl.value = val;
      inputEl.focus();
    });
  }

  if (editSaveEl) {
    editSaveEl.addEventListener('click', async () => {
      hideErr();
      const note = (editNoteEl.value || '').trim();
      const stage = (editStageEl.value || '').trim();
      if (!editableActivityId) { showErr('No editable tag found.'); return; }
      editSaveEl.disabled = true;
      try {
        await api('edit_tag_activity', { activity_id: String(editableActivityId), note, stage });
        editNoteEl.value = '';
        editStageEl.value = '';
      } catch (err) {
        showErr(err.message || 'Unable to edit tag');
      } finally {
        editSaveEl.disabled = false;
      }
    });
  }

  timelineEl.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-edit-tag]');
    if (!btn) return;
    const id = Number(btn.getAttribute('data-edit-tag') || 0);
    if (!id || id !== editableActivityId) return;
    const row = btn.closest('tr');
    if (!row) return;
    const stageTd = row.querySelector('td:nth-child(3)');
    const noteTd = row.querySelector('td:nth-child(5)');
    const stage = stageTd ? (stageTd.textContent || '').trim() : '';
    const note = noteTd ? (noteTd.textContent || '').trim() : '';
    if (editStageEl) editStageEl.value = stage !== '—' ? stage : '';
    if (editNoteEl) editNoteEl.value = (note && note !== '—') ? note : '';
    if (editBoxEl) editBoxEl.classList.remove('d-none');
  });

  currentEl.addEventListener('click', async (e) => {
    const rm = e.target.closest('[data-remove-tag]');
    if (!rm) return;
    hideErr();
    const tagId = Number(rm.getAttribute('data-remove-tag') || 0);
    if (!tagId) return;
    try {
      await api('remove_tag', { tag_id: tagId });
    } catch (err) {
      showErr(err.message || 'Unable to remove tag');
    }
  });
})();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
