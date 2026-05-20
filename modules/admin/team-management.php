<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();
ensureTeamSchema();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$message = '';
$messageType = 'success';

$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editTeam = null;
$editMembers = [];

$loadEdit = function() use (&$editTeam, &$editMembers, $conn, $editId) {
    if ($editId <= 0) return;
    $stmt = $conn->prepare("SELECT * FROM teams WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editTeam = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$editTeam) return;
    $stmt = $conn->prepare("SELECT user_id, member_role FROM team_members WHERE team_id = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid > 0) $editMembers[$uid] = (string)($r['member_role'] ?? 'member');
    }
    $stmt->close();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_team') {
            $tid = (int)($_POST['team_id'] ?? 0);
            if ($tid <= 0) {
                $message = 'Invalid team.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("DELETE FROM team_campaigns WHERE team_id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("DELETE FROM team_members WHERE team_id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("DELETE FROM teams WHERE id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $message = 'Team deleted.';
                $messageType = 'success';
                $editId = 0;
            }
        } elseif ($action === 'save_team') {
            $tid = (int)($_POST['team_id'] ?? 0);
            $teamName = trim((string)($_POST['team_name'] ?? ''));
            $managerId = (int)($_POST['manager_user_id'] ?? 0);
            $memberIdsRaw = $_POST['member_user_ids'] ?? [];

            if ($teamName === '') {
                $message = 'Team name is required.';
                $messageType = 'danger';
            } elseif ($managerId <= 0) {
                $message = 'Select a team manager.';
                $messageType = 'danger';
            } else {
                if (mb_strlen($teamName) > 120) $teamName = mb_substr($teamName, 0, 120);

                if ($tid > 0) {
                    $stmt = $conn->prepare("UPDATE teams SET team_name = ?, manager_user_id = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('sii', $teamName, $managerId, $tid);
                    $ok = $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("INSERT INTO teams (team_name, manager_user_id, created_by, created_at) VALUES (?,?,?,NOW())");
                    $stmt->bind_param('sii', $teamName, $managerId, $userId);
                    $ok = $stmt->execute();
                    $tid = (int)($stmt->insert_id ?? 0);
                    $stmt->close();
                }

                if (!$ok || $tid <= 0) {
                    $message = 'Failed to save team.';
                    $messageType = 'danger';
                } else {
                    $memberIds = [];
                    if (is_array($memberIdsRaw)) {
                        foreach ($memberIdsRaw as $v) {
                            $id = (int)$v;
                            if ($id > 0) $memberIds[$id] = true;
                        }
                    }
                    $memberIds[$managerId] = true;
                    $memberIds = array_keys($memberIds);

                    $stmt = $conn->prepare("DELETE FROM team_members WHERE team_id = ?");
                    $stmt->bind_param('i', $tid);
                    $stmt->execute();
                    $stmt->close();

                    $stmtI = $conn->prepare("INSERT IGNORE INTO team_members (team_id, user_id, member_role, added_by, added_at) VALUES (?,?,?, ?, NOW())");
                    foreach ($memberIds as $mid) {
                        $role = ($mid === $managerId) ? 'manager' : 'member';
                        $stmtI->bind_param('iisi', $tid, $mid, $role, $userId);
                        $stmtI->execute();
                    }
                    $stmtI->close();

                    $message = 'Team saved.';
                    $messageType = 'success';
                    header('Location: team-management?edit_id=' . $tid);
                    exit;
                }
            }
        }
    }
}

$users = [];
$rs = $conn->query("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role NOT LIKE 'client_%' AND role NOT LIKE 'vendor_%' ORDER BY full_name");
if ($rs) $users = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$campaigns = getAllCampaignsBasic();

$teams = [];
$rs = $conn->query("
    SELECT t.id, t.team_name, t.manager_user_id, u.full_name AS manager_name,
           (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS member_count,
           (SELECT COUNT(*) FROM team_campaigns tc WHERE tc.team_id = t.id) AS campaign_count,
           t.created_at
    FROM teams t
    LEFT JOIN users u ON u.id = t.manager_user_id
    ORDER BY t.created_at DESC
");
if ($rs) $teams = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$loadEdit();

?>
<?php $pageTitle = 'Team Management'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Team Management</div>
            <div class="text-muted small">Create teams, assign managers/members, map campaigns</div>
        </div>
        <a class="btn btn-light border" href="<?php echo htmlspecialchars(appBackUrl('../dashboard/admin-dashboard.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
                    <span><?php echo $editTeam ? 'Edit Team' : 'Create Team'; ?></span>
                    <?php if ($editTeam): ?>
                        <form method="post" class="m-0" onsubmit="return confirm('Delete this team?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="team_id" value="<?php echo (int)$editTeam['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="save_team">
                        <input type="hidden" name="team_id" value="<?php echo (int)($editTeam['id'] ?? 0); ?>">

                        <div class="mb-3">
                            <label class="form-label">Team Name</label>
                            <input class="form-control" name="team_name" value="<?php echo htmlspecialchars((string)($editTeam['team_name'] ?? '')); ?>" placeholder="e.g. Operations Team A" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Team Manager</label>
                            <select class="form-select" name="manager_user_id" required>
                                <option value="">Select manager</option>
                                <?php foreach ($users as $u): ?>
                                    <?php $uid2 = (int)($u['id'] ?? 0); ?>
                                    <option value="<?php echo $uid2; ?>" <?php echo ($uid2 > 0 && (int)($editTeam['manager_user_id'] ?? 0) === $uid2) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)($u['full_name'] ?? '') . ' · ' . (string)($u['role'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Team Members</label>
                            <div class="border rounded p-2" style="max-height: 240px; overflow: auto;">
                                <?php if (empty($users)): ?>
                                    <div class="text-muted small">No users found.</div>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <?php $uid2 = (int)($u['id'] ?? 0); ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="member_user_ids[]" value="<?php echo $uid2; ?>" id="mem_<?php echo $uid2; ?>" <?php echo isset($editMembers[$uid2]) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="mem_<?php echo $uid2; ?>">
                                                <?php echo htmlspecialchars((string)($u['full_name'] ?? '') . ' · ' . (string)($u['role'] ?? '')); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-1">Manager is always included as a member automatically.</div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a class="btn btn-light border" href="team-management.php">Clear</a>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">Teams</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Team</th>
                                    <th>Manager</th>
                                    <th class="text-end">Members</th>
                                    <th class="text-end">Campaigns</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teams as $t): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($t['team_name'] ?? '')); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars((string)($t['manager_name'] ?? '')); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['member_count'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo number_format((int)($t['campaign_count'] ?? 0)); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-light border" href="team-management.php?edit_id=<?php echo (int)($t['id'] ?? 0); ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($teams)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No teams created yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
