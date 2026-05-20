<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requireLogin();
ensureChatSchema();

header('Content-Type: application/json; charset=utf-8');
$user = getCurrentUser();
$uid = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : (int)($_POST['group_id'] ?? 0);
if ($groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group']);
    exit;
}

$stmtM = $conn->prepare('SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
$stmtM->bind_param('ii', $groupId, $uid);
$stmtM->execute();
$mem = $stmtM->get_result()->fetch_assoc() ?: null;
$stmtM->close();
if (!$mem) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}
$myRole = strtolower((string)($mem['role'] ?? 'member'));
$isAdmin = $myRole === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("
        SELECT gm.user_id, gm.role, gm.added_at, u.full_name, u.role AS user_role, u.profile_pic
        FROM chat_group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY (gm.role = 'admin') DESC, u.full_name ASC
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'user_id' => (int)$r['user_id'],
            'full_name' => (string)($r['full_name'] ?? ''),
            'profile_pic' => (string)($r['profile_pic'] ?? ''),
            'role' => (string)($r['role'] ?? 'member'),
            'user_role' => (string)($r['user_role'] ?? ''),
            'added_at' => $r['added_at'] ?? null,
        ];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'members' => $out, 'my_role' => $myRole]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = (string)($_POST['action'] ?? '');
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}

if ($action === 'add') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }

    $isVendorUser = function_exists('isVendor') && isVendor();
    $isClientUser = function_exists('isClient') && isClient();
    $allowedUserIds = null;
    if ($isClientUser) {
        $clientId = (int)($user['client_id'] ?? 0);
        $allowed = [];
        if ($clientId > 0) {
            $stmtCU = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND client_id = ? AND role LIKE 'client_%' AND id <> ?");
            $stmtCU->bind_param('ii', $clientId, $uid);
            $stmtCU->execute();
            $resCU = $stmtCU->get_result();
            while ($r = $resCU->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0) $allowed[$id] = true; }
            $stmtCU->close();

            $stmtO = $conn->prepare('SELECT owner_id, manager_id FROM sales_client_ownership WHERE client_id = ? LIMIT 1');
            $stmtO->bind_param('i', $clientId);
            $stmtO->execute();
            $own = $stmtO->get_result()->fetch_assoc() ?: [];
            $stmtO->close();
            foreach (['owner_id','manager_id'] as $k) { $id = (int)($own[$k] ?? 0); if ($id > 0) $allowed[$id] = true; }

            $campIds = [];
            $stmtC = $conn->prepare('SELECT campaign_id FROM campaign_details WHERE client_id = ?');
            $stmtC->bind_param('i', $clientId);
            $stmtC->execute();
            $res = $stmtC->get_result();
            while ($r = $res->fetch_assoc()) $campIds[] = (int)($r['campaign_id'] ?? 0);
            $stmtC->close();
            $campIds = array_values(array_filter($campIds, fn($v) => $v > 0));
            if (!empty($campIds)) {
                $in = implode(',', array_fill(0, count($campIds), '?'));
                $types = str_repeat('i', count($campIds));
                $sqlU = "
                    SELECT DISTINCT cu.user_id
                    FROM campaign_user_assignments cu
                    JOIN users u ON u.id = cu.user_id
                    WHERE cu.campaign_id IN ($in) AND u.is_active = 1 AND u.role IN ('sdr','sales_manager','sales_director','admin','director','manager_director','operations_director')
                ";
                $stmtU = $conn->prepare($sqlU);
                $stmtU->bind_param($types, ...$campIds);
                $stmtU->execute();
                $res2 = $stmtU->get_result();
                while ($r = $res2->fetch_assoc()) { $id = (int)($r['user_id'] ?? 0); if ($id > 0) $allowed[$id] = true; }
                $stmtU->close();
            }
        }
        $allowedUserIds = array_keys($allowed);
    } elseif ($isVendorUser) {
        $vendorId = (int)($user['vendor_id'] ?? 0);
        $allowed = [];
        if ($vendorId > 0) {
            $stmtVU = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND vendor_id = ? AND role LIKE 'vendor_%' AND id <> ?");
            $stmtVU->bind_param('ii', $vendorId, $uid);
            $stmtVU->execute();
            $resVU = $stmtVU->get_result();
            while ($r = $resVU->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0) $allowed[$id] = true; }
            $stmtVU->close();
        }
        $allowedUserIds = array_keys($allowed);
    } elseif (!(isAdmin() || isDirector() || hasRole('manager_director'))) {
        $allowed = [];
        $teamIds = getUserTeamIds($uid);
        if (!empty($teamIds)) {
            $in = implode(',', array_fill(0, count($teamIds), '?'));
            $types = str_repeat('i', count($teamIds));
            $stmtT = $conn->prepare("SELECT DISTINCT user_id FROM team_members WHERE team_id IN ($in)");
            $stmtT->bind_param($types, ...$teamIds);
            $stmtT->execute();
            $resT = $stmtT->get_result();
            while ($r = $resT->fetch_assoc()) { $id = (int)($r['user_id'] ?? 0); if ($id > 0 && $id !== $uid) $allowed[$id] = true; }
            $stmtT->close();
        }
        $rs = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director','operations_director')");
        if ($rs) { while ($r = $rs->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $uid) $allowed[$id] = true; } }
        $allowedUserIds = array_keys($allowed);
    }

    if (is_array($allowedUserIds) && !in_array($userId, $allowedUserIds, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed']);
        exit;
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, added_by, added_at) VALUES (?, ?, 'member', ?, NOW())");
    $stmt->bind_param('iii', $groupId, $userId, $uid);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $who = (string)($user['full_name'] ?? 'User');
        $nStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $nStmt->bind_param('i', $userId);
        $nStmt->execute();
        $nr = $nStmt->get_result()->fetch_assoc() ?: [];
        $nStmt->close();
        $target = (string)($nr['full_name'] ?? 'User');
        $sys = $who . ' added ' . $target;
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $sys);
        $stmtSys->execute();
        $stmtSys->close();
        createNotificationSmart($userId, 'chat.added_to_group', 'Added to group', $sys, '../chat/chat?group_id=' . $groupId, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'chat_added:' . $groupId . ':' . $userId,
            'dedup_window_min' => 10,
        ]);
    }
    echo json_encode(['ok' => (bool)$ok]);
    exit;
}

if ($action === 'remove') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0 || $userId === $uid) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }
    $stmt = $conn->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $groupId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $who = (string)($user['full_name'] ?? 'User');
        $nStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $nStmt->bind_param('i', $userId);
        $nStmt->execute();
        $nr = $nStmt->get_result()->fetch_assoc() ?: [];
        $nStmt->close();
        $target = (string)($nr['full_name'] ?? 'User');
        $sys = $who . ' removed ' . $target;
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $sys);
        $stmtSys->execute();
        $stmtSys->close();
    }
    echo json_encode(['ok' => (bool)$ok]);
    exit;
}

if ($action === 'set_role') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $role = strtolower(trim((string)($_POST['role'] ?? 'member')));
    if ($userId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit; }
    if (!in_array($role, ['admin','member'], true)) { echo json_encode(['ok' => false, 'error' => 'Invalid role']); exit; }
    $stmt = $conn->prepare("UPDATE chat_group_members SET role = ? WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param('sii', $role, $groupId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $who = (string)($user['full_name'] ?? 'User');
        $nStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $nStmt->bind_param('i', $userId);
        $nStmt->execute();
        $nr = $nStmt->get_result()->fetch_assoc() ?: [];
        $nStmt->close();
        $target = (string)($nr['full_name'] ?? 'User');
        $sys = $who . ' set ' . $target . ' as ' . ($role === 'admin' ? 'Admin' : 'Member');
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $sys);
        $stmtSys->execute();
        $stmtSys->close();
    }
    echo json_encode(['ok' => (bool)$ok]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
