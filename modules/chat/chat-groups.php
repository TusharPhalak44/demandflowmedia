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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    $where = "gm.user_id = ?";
    if ($q !== '') $where .= " AND g.group_name LIKE CONCAT('%', ?, '%')";
    $sql = "
        SELECT g.id, g.group_name, g.created_by, g.created_at, gm.role,
               (SELECT COUNT(*) FROM chat_group_members m2 WHERE m2.group_id = g.id) AS member_count
        FROM chat_group_members gm
        JOIN chat_groups g ON g.id = gm.group_id
        WHERE {$where}
        ORDER BY g.created_at DESC
        LIMIT 300
    ";
    if ($q !== '') {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $uid, $q);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $uid);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'id' => (int)$r['id'],
            'group_name' => (string)$r['group_name'],
            'role' => (string)($r['role'] ?? 'member'),
            'member_count' => (int)($r['member_count'] ?? 0),
        ];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'groups' => $out]);
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
if ($action !== 'create') {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$name = trim((string)($_POST['group_name'] ?? ''));
if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Group name required']);
    exit;
}
if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

$memberIdsRaw = $_POST['member_ids'] ?? [];
if (!is_array($memberIdsRaw)) $memberIdsRaw = [];
$memberIds = [];
foreach ($memberIdsRaw as $v) {
    $id = (int)$v;
    if ($id > 0 && $id !== $uid) $memberIds[$id] = true;
}
$memberIds = array_keys($memberIds);

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

        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director','operations_director')");
        if ($rsA) { while ($r = $rsA->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $uid) $allowed[$id] = true; } }
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

        $campIds = [];
        $stmtC = $conn->prepare('SELECT DISTINCT campaign_id, assigned_by FROM vendor_campaign_map WHERE vendor_id = ?');
        $stmtC->bind_param('i', $vendorId);
        $stmtC->execute();
        $res = $stmtC->get_result();
        while ($r = $res->fetch_assoc()) {
            $cid = (int)($r['campaign_id'] ?? 0);
            if ($cid > 0) $campIds[] = $cid;
            $ab = (int)($r['assigned_by'] ?? 0);
            if ($ab > 0) $allowed[$ab] = true;
        }
        $stmtC->close();
        $campIds = array_values(array_unique(array_filter($campIds, fn($v) => $v > 0)));
        if (!empty($campIds)) {
            $in = implode(',', array_fill(0, count($campIds), '?'));
            $types = str_repeat('i', count($campIds));
            foreach ([
                "SELECT DISTINCT user_id FROM operations_campaign_assignments WHERE campaign_id IN ($in)",
                "SELECT DISTINCT user_id FROM qa_campaign_assignments WHERE campaign_id IN ($in)",
                "SELECT DISTINCT user_id FROM campaign_user_assignments WHERE campaign_id IN ($in)",
            ] as $sqlX) {
                $stmtX = $conn->prepare($sqlX);
                $stmtX->bind_param($types, ...$campIds);
                $stmtX->execute();
                $resX = $stmtX->get_result();
                while ($r = $resX->fetch_assoc()) { $id = (int)($r['user_id'] ?? 0); if ($id > 0) $allowed[$id] = true; }
                $stmtX->close();
            }
        }

        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director','operations_director')");
        if ($rsA) { while ($r = $rsA->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $uid) $allowed[$id] = true; } }
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

if (is_array($allowedUserIds)) {
    $allowedSet = array_flip($allowedUserIds);
    $memberIds = array_values(array_filter($memberIds, fn($id) => isset($allowedSet[$id])));
}

$stmt = $conn->prepare('INSERT INTO chat_groups (group_name, created_by, created_at) VALUES (?, ?, NOW())');
$stmt->bind_param('si', $name, $uid);
$ok = $stmt->execute();
$groupId = (int)($stmt->insert_id ?? 0);
$stmt->close();
if (!$ok || $groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create group']);
    exit;
}

$stmtM = $conn->prepare("INSERT INTO chat_group_members (group_id, user_id, role, added_by, added_at) VALUES (?, ?, 'admin', ?, NOW())");
$stmtM->bind_param('iii', $groupId, $uid, $uid);
$stmtM->execute();
$stmtM->close();

if (!empty($memberIds)) {
    $ins = $conn->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, added_by, added_at) VALUES (?, ?, 'member', ?, NOW())");
    foreach ($memberIds as $mid) {
        $ins->bind_param('iii', $groupId, $mid, $uid);
        $ins->execute();
    }
    $ins->close();

    $senderName = (string)($user['full_name'] ?? 'User');
    $rs = $conn->prepare('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_map('intval', $memberIds)) . ')');
    $rs->execute();
    $res = $rs->get_result();
    $membersById = [];
    while ($r = $res->fetch_assoc()) $membersById[(int)$r['id']] = (string)($r['full_name'] ?? '');
    $rs->close();

    foreach ($memberIds as $mid) {
        $targetName = $membersById[$mid] ?? 'User';
        $msg = $senderName . ' added ' . $targetName;
        $stmtSys = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, message_type, delivered_at, created_at) VALUES (0, 0, ?, ?, 'system', NOW(), NOW())");
        $stmtSys->bind_param('is', $groupId, $msg);
        $stmtSys->execute();
        $stmtSys->close();

        createNotificationSmart($mid, 'chat.added_to_group', 'Added to group', $name, '../chat/chat?group_id=' . $groupId, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'chat_added:' . $groupId . ':' . $mid,
            'dedup_window_min' => 10,
        ]);
    }
}

echo json_encode(['ok' => true, 'group' => ['id' => $groupId, 'group_name' => $name]]);
exit;
