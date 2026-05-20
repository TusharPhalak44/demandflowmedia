<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr', 'admin']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$isSdr = isSDR();
$isManager = isSalesManager();
$isDirector = isSalesDirector();

$statuses = ['All','New','Contacted','Follow-up Required','Meeting Scheduled','Proposal Sent','Negotiation','Closed Won','Closed Lost'];
$priorities = ['All','Low','Normal','High','Urgent'];
$dueFilters = ['All','Overdue','Due Today','Due 7 Days','No Follow-up'];

$status = trim((string)($_GET['status'] ?? 'All'));
if (!in_array($status, $statuses, true)) $status = 'All';
$priority = trim((string)($_GET['priority'] ?? 'All'));
if (!in_array($priority, $priorities, true)) $priority = 'All';
$due = trim((string)($_GET['due'] ?? 'All'));
if (!in_array($due, $dueFilters, true)) $due = 'All';

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
$types = '';

if ($isSdr) {
    $where[] = "sl.owner_id = ?";
    $params[] = $userId;
    $types .= 'i';
} elseif ($isManager && !$isDirector) {
    $where[] = "(sl.owner_id = ? OR sl.owner_id IN (SELECT sdr_user_id FROM sales_manager_sdr_map WHERE manager_user_id = ?))";
    $params[] = $userId;
    $params[] = $userId;
    $types .= 'ii';
}

if ($status !== 'All') {
    $where[] = "sl.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($priority !== 'All') {
    $where[] = "sl.priority = ?";
    $params[] = $priority;
    $types .= 's';
}

if ($q !== '') {
    $where[] = "(sl.company_name LIKE ? OR sl.website LIKE ? OR sl.contact_name LIKE ? OR sl.contact_email LIKE ? OR sl.linkedin_url LIKE ?)";
    $like = '%'.$q.'%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$closedSql = "(sl.status = 'Closed Won' OR sl.status = 'Closed Lost')";
if ($due === 'Overdue') {
    $where[] = "sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at < NOW() AND NOT $closedSql";
} elseif ($due === 'Due Today') {
    $where[] = "sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at >= ? AND sl.next_follow_up_at <= ? AND NOT $closedSql";
    $params[] = date('Y-m-d 00:00:00');
    $params[] = date('Y-m-d 23:59:59');
    $types .= 'ss';
} elseif ($due === 'Due 7 Days') {
    $where[] = "sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at >= ? AND sl.next_follow_up_at <= ? AND NOT $closedSql";
    $params[] = date('Y-m-d 00:00:00');
    $params[] = date('Y-m-d 23:59:59', strtotime('+7 days'));
    $types .= 'ss';
} elseif ($due === 'No Follow-up') {
    $where[] = "sl.next_follow_up_at IS NULL AND NOT $closedSql";
}

$whereSql = implode(' AND ', $where);

$cntStmt = $conn->prepare("SELECT COUNT(*) FROM sales_leads sl WHERE $whereSql");
if ($types) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int)$cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

$sql = "SELECT sl.*,
            o.full_name AS owner_name, o.role AS owner_role,
            m.full_name AS manager_name,
            la.id AS last_activity_id, la.comment AS last_comment, la.created_at AS last_comment_at,
            ub.full_name AS updated_by_name, ub.role AS updated_by_role
        FROM sales_leads sl
        LEFT JOIN users o ON o.id = sl.owner_id
        LEFT JOIN users m ON m.id = sl.sales_manager_id
        LEFT JOIN (
            SELECT a.*
            FROM sales_lead_activities a
            INNER JOIN (
                SELECT sales_lead_id, MAX(id) AS max_id
                FROM sales_lead_activities
                GROUP BY sales_lead_id
            ) x ON x.max_id = a.id
        ) la ON la.sales_lead_id = sl.id
        LEFT JOIN users ub ON ub.id = sl.updated_by
        WHERE $whereSql
        ORDER BY sl.updated_at DESC, sl.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$p = $params;
$p[] = $perPage;
$p[] = $offset;
$stmt->bind_param($types.'ii', ...$p);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$statusOptions = array_values(array_filter($statuses, fn($s) => $s !== 'All'));
?>

<?php $pageTitle = 'Sales Pipeline'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Sales Pipeline</h3>
      <div class="text-muted small"><?php echo $isSdr ? 'Your prospects only' : 'Team prospects'; ?></div>
    </div>
    <a href="lead-create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Prospect</a>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2" method="get">
        <div class="col-lg-4">
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search company, website, contact, email, LinkedIn">
        </div>
        <div class="col-lg-3">
          <select class="form-select form-select-sm" name="status">
            <?php foreach ($statuses as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <select class="form-select form-select-sm" name="priority">
            <?php foreach ($priorities as $p): ?>
              <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $priority === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <select class="form-select form-select-sm" name="due">
            <?php foreach ($dueFilters as $df): ?>
              <option value="<?php echo htmlspecialchars($df); ?>" <?php echo $due === $df ? 'selected' : ''; ?>><?php echo htmlspecialchars($df); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-1 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-filter me-1"></i>Filter</button>
        </div>
      </form>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Company</th>
            <th>Status</th>
            <th>Priority</th>
            <th>Owner</th>
            <th>Manager</th>
            <th>Next Follow-up</th>
            <th class="text-muted">Last Touch</th>
            <th>Last Note</th>
            <th class="text-muted">Updated</th>
            <th class="text-end">Quick</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No prospects found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $st = (string)($r['status'] ?? '');
                $badge = 'bg-secondary';
                if ($st === 'Closed Won') $badge = 'bg-success';
                elseif ($st === 'Closed Lost') $badge = 'bg-danger';
                elseif ($st === 'Negotiation') $badge = 'bg-warning text-dark';
                elseif ($st === 'Proposal Sent') $badge = 'bg-primary';
                elseif ($st === 'Meeting Scheduled') $badge = 'bg-info text-dark';
                elseif ($st === 'Follow-up Required') $badge = 'bg-warning text-dark';
                $nfa = (string)($r['next_follow_up_at'] ?? '');
                $lta = (string)($r['last_activity_at'] ?? '');
                $lastActId = (int)($r['last_activity_id'] ?? 0);
                $lastComment = trim((string)($r['last_comment'] ?? ''));
                $isClosed = in_array($st, ['Closed Won','Closed Lost'], true);
                $isOverdue = (!$isClosed && $nfa !== '' && strtotime($nfa) !== false && strtotime($nfa) < time());
                $isToday = (!$isClosed && $nfa !== '' && date('Y-m-d', strtotime($nfa)) === date('Y-m-d'));
                $updatedByLabel = trim((string)($r['updated_by_name'] ?? ''));
                $updatedByRole = trim((string)($r['updated_by_role'] ?? ''));
              ?>
              <tr data-lead-id="<?php echo (int)$r['id']; ?>"
                  data-status="<?php echo htmlspecialchars($st); ?>"
                  data-priority="<?php echo htmlspecialchars((string)($r['priority'] ?? '')); ?>"
                  data-next-follow-up-at="<?php echo htmlspecialchars($nfa); ?>"
                  data-last-activity-at="<?php echo htmlspecialchars($lta); ?>"
                  data-last-activity-id="<?php echo (int)$lastActId; ?>"
                  data-last-comment="<?php echo htmlspecialchars($lastComment); ?>">
                <td>
                  <div class="fw-semibold">
                    <a class="text-decoration-none" href="lead-view.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['company_name'] ?? ''); ?></a>
                  </div>
                  <div class="text-muted small">
                    <?php echo htmlspecialchars($r['website_domain'] ?? ($r['website'] ?? '')); ?>
                    <?php if (!empty($r['contact_name'])): ?> · <?php echo htmlspecialchars($r['contact_name']); ?><?php endif; ?>
                  </div>
                </td>
                <td><span class="badge <?php echo htmlspecialchars($badge); ?>"><?php echo htmlspecialchars($st); ?></span></td>
                <td><?php echo htmlspecialchars($r['priority'] ?? ''); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($r['owner_name'] ?? ''), ($r['owner_role'] ?? ''))); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($r['manager_name'] ?? ''); ?></td>
                <td class="text-muted small">
                  <?php if ($nfa !== ''): ?>
                    <span class="badge border <?php echo $isOverdue ? 'bg-danger-subtle text-danger' : ($isToday ? 'bg-warning-subtle text-warning' : 'bg-light text-dark'); ?>">
                      <?php echo htmlspecialchars(date('d M, H:i', strtotime($nfa))); ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted small"><?php echo $lta ? htmlspecialchars(date('d M, H:i', strtotime($lta))) : '<span class="text-muted">—</span>'; ?></td>
                <td class="small">
                  <?php if ($lastComment !== ''): ?>
                    <span class="d-inline-block text-truncate" style="max-width: 260px;" title="<?php echo htmlspecialchars($lastComment); ?>">
                      <?php echo htmlspecialchars($lastComment); ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted small">
                  <?php
                    $ts = $r['updated_at'] ?: $r['created_at'];
                    $label = $ts ? date('d M, H:i', strtotime($ts)) : '';
                    $by = $updatedByLabel !== '' ? formatUserNameWithRole($updatedByLabel, $updatedByRole) : '';
                    if ($by !== '') {
                      echo '<span title="'.htmlspecialchars('Updated by: '.$by).'">'.htmlspecialchars($label).'</span>';
                    } else {
                      echo $label ? htmlspecialchars($label) : '';
                    }
                  ?>
                </td>
                <td class="text-end text-nowrap">
                  <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-light border" data-quick="add" title="Quick log">
                      <i class="bi bi-chat-left-text"></i>
                    </button>
                    <button type="button" class="btn btn-light border" data-quick="edit" title="Edit last note" <?php echo $lastActId > 0 ? '' : 'disabled'; ?>>
                      <i class="bi bi-pencil"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="modal fade" id="quickLogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div class="modal-title fw-semibold" id="quickLogTitle">Quick Update</div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex flex-wrap gap-2 mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="called"><i class="bi bi-telephone me-1"></i>Called</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="emailed"><i class="bi bi-envelope me-1"></i>Emailed</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="linkedin"><i class="bi bi-linkedin me-1"></i>LinkedIn</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="voicemail"><i class="bi bi-voicemail me-1"></i>No Answer</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="meeting"><i class="bi bi-calendar-event me-1"></i>Meeting</button>
          </div>
          <form id="quickLogForm" class="row g-2">
            <input type="hidden" name="mode" value="add">
            <input type="hidden" name="lead_id" value="">
            <input type="hidden" name="activity_id" value="">
            <div class="col-12">
              <label class="form-label">Status</label>
              <select class="form-select form-select-sm" name="status" required>
                <?php foreach ($statusOptions as $s): ?>
                  <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Next Follow-up (optional)</label>
              <input class="form-control form-control-sm" type="datetime-local" name="next_follow_up_at">
            </div>
            <div class="col-12">
              <label class="form-label">Comment</label>
              <textarea class="form-control form-control-sm" rows="4" name="comment" required></textarea>
            </div>
          </form>
          <div class="alert alert-danger d-none mt-2" id="quickLogError"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary btn-sm" id="quickLogSave">Save</button>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex align-items-center justify-content-between">
    <div class="text-muted small">Showing <?php echo count($rows); ?> of <?php echo (int)$total; ?></div>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php
          $base = 'leads.php?'.http_build_query(['q'=>$q,'status'=>$status,'priority'=>$priority,'due'=>$due]);
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
        ?>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo $base.'&page='.$prev; ?>">Prev</a>
        </li>
        <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo $base.'&page='.$next; ?>">Next</a>
        </li>
      </ul>
    </nav>
  </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

<script>
const quickLogToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
const quickLogModalEl = document.getElementById('quickLogModal');
const quickLogForm = document.getElementById('quickLogForm');
const quickLogTitle = document.getElementById('quickLogTitle');
const quickLogError = document.getElementById('quickLogError');
const quickLogSave = document.getElementById('quickLogSave');

const presetButtons = Array.from(document.querySelectorAll('[data-preset]'));

function parseIsoToLocalInput(value) {
  if (!value) return '';
  const d = new Date(value.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toLocalInputFromDate(d) {
  if (!(d instanceof Date) || isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function statusBadgeClass(st) {
  if (st === 'Closed Won') return 'bg-success';
  if (st === 'Closed Lost') return 'bg-danger';
  if (st === 'Negotiation') return 'bg-warning text-dark';
  if (st === 'Proposal Sent') return 'bg-primary';
  if (st === 'Meeting Scheduled') return 'bg-info text-dark';
  if (st === 'Follow-up Required') return 'bg-warning text-dark';
  return 'bg-secondary';
}

function formatDt(ts) {
  if (!ts) return '';
  const d = new Date(ts.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '';
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getDate())} ${months[d.getMonth()]}, ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function openQuickModal(mode, tr) {
  quickLogError.classList.add('d-none');
  quickLogError.textContent = '';
  quickLogForm.mode.value = mode;
  quickLogForm.lead_id.value = tr.dataset.leadId || '';
  quickLogForm.activity_id.value = mode === 'edit' ? (tr.dataset.lastActivityId || '') : '';
  quickLogForm.status.value = tr.dataset.status || 'New';
  quickLogForm.next_follow_up_at.value = parseIsoToLocalInput(tr.dataset.nextFollowUpAt || '');
  quickLogForm.comment.value = mode === 'edit' ? (tr.dataset.lastComment || '') : '';
  quickLogTitle.textContent = mode === 'edit' ? 'Edit Last Note' : 'Quick Log';
  bootstrap.Modal.getOrCreateInstance(quickLogModalEl).show();
}

function applyPreset(presetKey) {
  const presets = {
    called: {
      status: 'Contacted',
      followUpMinutes: 24 * 60,
      comment: 'Called prospect. '
    },
    emailed: {
      status: 'Follow-up Required',
      followUpMinutes: 2 * 24 * 60,
      comment: 'Sent email. '
    },
    linkedin: {
      status: 'Contacted',
      followUpMinutes: 2 * 24 * 60,
      comment: 'Sent LinkedIn message. '
    },
    voicemail: {
      status: 'Follow-up Required',
      followUpMinutes: 24 * 60,
      comment: 'No answer. Left voicemail. '
    },
    meeting: {
      status: 'Meeting Scheduled',
      followUpMinutes: null,
      comment: 'Meeting scheduled. '
    }
  };
  const p = presets[presetKey];
  if (!p) return;

  if (p.status) quickLogForm.status.value = p.status;

  if (p.followUpMinutes !== null) {
    const d = new Date();
    d.setMinutes(d.getMinutes() + Number(p.followUpMinutes || 0));
    quickLogForm.next_follow_up_at.value = toLocalInputFromDate(d);
  }

  const existing = quickLogForm.comment.value.trim();
  if (existing === '' || quickLogForm.mode.value === 'add') {
    quickLogForm.comment.value = p.comment;
  } else {
    quickLogForm.comment.value = p.comment + existing;
  }
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-preset]');
  if (!btn) return;
  applyPreset(btn.getAttribute('data-preset'));
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-quick]');
  if (!btn) return;
  const tr = btn.closest('tr[data-lead-id]');
  if (!tr) return;
  const mode = btn.getAttribute('data-quick');
  if (mode === 'edit' && (!tr.dataset.lastActivityId || tr.dataset.lastActivityId === '0')) return;
  openQuickModal(mode, tr);
});

quickLogSave.addEventListener('click', async () => {
  quickLogError.classList.add('d-none');
  const mode = quickLogForm.mode.value;
  const leadId = quickLogForm.lead_id.value;
  const activityId = quickLogForm.activity_id.value;
  const status = quickLogForm.status.value;
  const comment = quickLogForm.comment.value.trim();
  const nextFollowUpAt = quickLogForm.next_follow_up_at.value;

  if (!leadId) return;
  if (!status) return;
  if (!comment) {
    quickLogError.textContent = 'Comment is required.';
    quickLogError.classList.remove('d-none');
    return;
  }

  const payload = new URLSearchParams();
  payload.set('csrf_token', quickLogToken);
  payload.set('action', mode === 'edit' ? 'edit_activity' : 'add_activity');
  payload.set('lead_id', leadId);
  payload.set('status', status);
  payload.set('comment', comment);
  if (nextFollowUpAt) payload.set('next_follow_up_at', nextFollowUpAt);
  if (mode === 'edit') payload.set('activity_id', activityId);

  quickLogSave.disabled = true;
  try {
    const res = await fetch('activity', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: payload.toString()
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) {
      throw new Error((data && data.error) ? data.error : 'Failed to save');
    }
    const row = document.querySelector(`tr[data-lead-id="${data.data.lead_id}"]`);
    if (row) {
      row.dataset.status = data.data.status || '';
      row.dataset.nextFollowUpAt = data.data.next_follow_up_at || '';
      row.dataset.lastActivityAt = data.data.last_activity_at || '';
      row.dataset.lastActivityId = data.data.last_activity_id || '';
      row.dataset.lastComment = data.data.last_comment || '';

      const badge = row.querySelector('td:nth-child(2) .badge');
      if (badge) {
        badge.className = 'badge ' + statusBadgeClass(data.data.status || '');
        badge.textContent = data.data.status || '';
      }

      const nextTd = row.querySelector('td:nth-child(6)');
      if (nextTd) {
        if (data.data.next_follow_up_at) {
          const nfa = data.data.next_follow_up_at;
          const d = new Date(nfa.replace(' ', 'T'));
          const isClosed = (data.data.status === 'Closed Won' || data.data.status === 'Closed Lost');
          const isOverdue = !isClosed && !isNaN(d.getTime()) && d.getTime() < Date.now();
          const isToday = !isClosed && !isNaN(d.getTime()) && d.toDateString() === (new Date()).toDateString();
          const cls = isOverdue ? 'bg-danger-subtle text-danger' : (isToday ? 'bg-warning-subtle text-warning' : 'bg-light text-dark');
          nextTd.innerHTML = `<span class="badge border ${cls}">${formatDt(nfa)}</span>`;
        } else {
          nextTd.innerHTML = '<span class="text-muted">—</span>';
        }
      }

      const touchTd = row.querySelector('td:nth-child(7)');
      if (touchTd) {
        touchTd.innerHTML = data.data.last_activity_at ? formatDt(data.data.last_activity_at) : '<span class="text-muted">—</span>';
      }

      const noteTd = row.querySelector('td:nth-child(8)');
      if (noteTd) {
        const lc = (data.data.last_comment || '').trim();
        if (lc) {
          const esc = lc.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
          noteTd.innerHTML = `<span class="d-inline-block text-truncate" style="max-width: 260px;" title="${esc}">${esc}</span>`;
        } else {
          noteTd.innerHTML = '<span class="text-muted">—</span>';
        }
      }

      const updTd = row.querySelector('td:nth-child(9)');
      if (updTd) {
        updTd.textContent = data.data.updated_at ? formatDt(data.data.updated_at) : '';
      }

      const editBtn = row.querySelector('button[data-quick="edit"]');
      if (editBtn) editBtn.disabled = !(row.dataset.lastActivityId && row.dataset.lastActivityId !== '0');
    }
    bootstrap.Modal.getOrCreateInstance(quickLogModalEl).hide();
  } catch (err) {
    quickLogError.textContent = err.message || 'Failed to save';
    quickLogError.classList.remove('d-none');
  } finally {
    quickLogSave.disabled = false;
  }
});
</script>
