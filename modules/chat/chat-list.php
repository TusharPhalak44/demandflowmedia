<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requireLogin();
ensureChatSchema();

header('Content-Type: application/json; charset=utf-8');
$user = getCurrentUser();
$uid = (int)($user['id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));

$conn = getDbConnection();

$isVendorUser = function_exists('isVendor') && isVendor();
$isClientUser = function_exists('isClient') && isClient();
$allowedUserIds = null;
if ($isClientUser) {
    $clientId = (int)($user['client_id'] ?? 0);
    $allowedUserIds = [];
    if ($clientId > 0) {
        $stmtCU = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND client_id = ? AND role LIKE 'client_%' AND id <> ?");
        $stmtCU->bind_param('ii', $clientId, $uid);
        $stmtCU->execute();
        $resCU = $stmtCU->get_result();
        while ($r = $resCU->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0) $allowedUserIds[$id] = true; }
        $stmtCU->close();

        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director')");
        if ($rsA) {
            while ($r = $rsA->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $uid) $allowedUserIds[$id] = true; }
        }
    }
    $allowedUserIds = array_keys($allowedUserIds);
} elseif ($isVendorUser) {
    $vendorId = (int)($user['vendor_id'] ?? 0);
    $allowedUserIds = [];
    if ($vendorId > 0) {
        $stmtVU = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND vendor_id = ? AND role LIKE 'vendor_%' AND id <> ?");
        $stmtVU->bind_param('ii', $vendorId, $uid);
        $stmtVU->execute();
        $resVU = $stmtVU->get_result();
        while ($r = $resVU->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0) $allowedUserIds[$id] = true; }
        $stmtVU->close();

        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director')");
        if ($rsA) { while ($r = $rsA->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $uid) $allowedUserIds[$id] = true; } }
        $allowedUserIds = array_keys($allowedUserIds);
    } else {
        $allowedUserIds = [];
    }
}

if ($allowedUserIds === null && !(isAdmin() || isDirector() || hasRole('manager_director'))) {
    $allowed = [];
    $teamIds = getUserTeamIds($uid);
    if (!empty($teamIds)) {
        $in = implode(',', array_fill(0, count($teamIds), '?'));
        $types = str_repeat('i', count($teamIds));
        $stmtT = $conn->prepare("SELECT DISTINCT user_id FROM team_members WHERE team_id IN ($in)");
        $stmtT->bind_param($types, ...$teamIds);
        $stmtT->execute();
        $resT = $stmtT->get_result();
        while ($r = $resT->fetch_assoc()) {
            $id = (int)($r['user_id'] ?? 0);
            if ($id > 0 && $id !== $uid) $allowed[$id] = true;
        }
        $stmtT->close();
    }

    $rs = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director','operations_director')");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0 && $id !== $uid) $allowed[$id] = true;
        }
    }

    $stmtH = $conn->prepare("
        SELECT DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id
        FROM chat_messages
        WHERE group_id IS NULL AND (sender_id = ? OR receiver_id = ?)
    ");
    if ($stmtH) {
        $stmtH->bind_param('iii', $uid, $uid, $uid);
        $stmtH->execute();
        $resH = $stmtH->get_result();
        while ($r = $resH->fetch_assoc()) {
            $id = (int)($r['other_id'] ?? 0);
            if ($id > 0 && $id !== $uid) $allowed[$id] = true;
        }
        $stmtH->close();
    }

    $allowedUserIds = array_keys($allowed);
}

$users = [];
if ($filter === 'all' || $filter === 'direct') {
    $where = "u.is_active = 1 AND u.id <> ?";
    if (!$isClientUser && !$isVendorUser) {
        $where .= " AND ((u.role NOT LIKE 'client_%' AND u.role NOT LIKE 'vendor_%') OR x.last_msg_at IS NOT NULL)";
    }
    if (is_array($allowedUserIds)) {
        if (empty($allowedUserIds)) {
            $users = [];
            goto groups_section;
        }
        $in = implode(',', array_fill(0, count($allowedUserIds), '?'));
        $where .= " AND u.id IN ($in)";
    }
    if ($q !== '') {
        $where .= " AND (u.full_name LIKE CONCAT('%', ?, '%') OR u.username LIKE CONCAT('%', ?, '%') OR u.email LIKE CONCAT('%', ?, '%'))";
    }

    $sql = "
        SELECT
            u.id, u.full_name, u.role, u.profile_pic, v.vendor_code,
            COALESCE(p.is_online,0) AS is_online, p.last_seen,
            x.last_msg_at,
            COALESCE(mlast.message, '') AS last_msg,
            mlast.attachment_path AS last_attachment,
            COALESCE(mlast.is_deleted, 0) AS last_is_deleted,
            (
                SELECT COUNT(*) FROM chat_messages m2
                WHERE m2.group_id IS NULL
                  AND m2.sender_id=u.id AND m2.receiver_id=? AND m2.read_at IS NULL
            ) AS unread_count
        FROM users u
        LEFT JOIN user_presence p ON p.user_id = u.id
        LEFT JOIN vendors v ON v.id = u.vendor_id
        LEFT JOIN (
            SELECT
                CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id,
                MAX(id) AS last_id,
                MAX(created_at) AS last_msg_at
            FROM chat_messages
            WHERE group_id IS NULL AND (sender_id = ? OR receiver_id = ?)
            GROUP BY other_id
        ) x ON x.other_id = u.id
        LEFT JOIN chat_messages mlast ON mlast.id = x.last_id
        WHERE {$where}
        ORDER BY (last_msg_at IS NULL) ASC, last_msg_at DESC, u.full_name ASC
        LIMIT 300
    ";

    if ($q !== '') {
        $stmt = $conn->prepare($sql);
        if (is_array($allowedUserIds)) {
            $types = 'iiiii' . str_repeat('i', count($allowedUserIds)) . 'sss';
            $params = array_merge([$uid, $uid, $uid, $uid, $uid], $allowedUserIds, [$q, $q, $q]);
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param('iiiiisss', $uid, $uid, $uid, $uid, $uid, $q, $q, $q);
        }
    } else {
        $stmt = $conn->prepare($sql);
        if (is_array($allowedUserIds)) {
            $types = 'iiiii' . str_repeat('i', count($allowedUserIds));
            $params = array_merge([$uid, $uid, $uid, $uid, $uid], $allowedUserIds);
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param('iiiii', $uid, $uid, $uid, $uid, $uid);
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $preview = '';
        if (!empty($r['last_is_deleted'])) $preview = 'This message was deleted';
        elseif (!empty($r['last_attachment'])) $preview = 'Attachment';
        else $preview = (string)($r['last_msg'] ?? '');
        $preview = trim(preg_replace('/\s+/', ' ', $preview));
        if (mb_strlen($preview) > 80) $preview = mb_substr($preview, 0, 80) . '...';
        $name = (string)($r['full_name'] ?? '');
        $maskVendor = (isQA() || isAgent() || hasRole(['operations_director','operations_manager','operations_agent','agent'])) && !empty($r['vendor_code']);
        if ($maskVendor) $name = (string)($r['vendor_code'] ?? $name);
        $users[] = [
            'type' => 'direct',
            'id' => (int)$r['id'],
            'name' => $name,
            'role' => (string)($r['role'] ?? ''),
            'profile_pic' => (string)($r['profile_pic'] ?? ''),
            'is_online' => (int)($r['is_online'] ?? 0) === 1,
            'last_seen' => $r['last_seen'] ?? null,
            'last_message_at' => $r['last_msg_at'] ?? null,
            'last_message_preview' => $preview,
            'unread_count' => (int)($r['unread_count'] ?? 0),
        ];
    }
    $stmt->close();
}

groups_section:
$groups = [];
if ($filter === 'all' || $filter === 'groups') {
    $where = "gm.user_id = ?";
    if ($q !== '') {
        $where .= " AND g.group_name LIKE CONCAT('%', ?, '%')";
    }
    $sqlG = "
        SELECT
            g.id AS group_id, g.group_name, g.created_by, g.created_at,
            gm.role AS member_role, gm.last_read_message_id,
            (
                SELECT MAX(m.id) FROM chat_messages m
                WHERE m.group_id = g.id
            ) AS last_msg_id,
            (
                SELECT MAX(m.created_at) FROM chat_messages m
                WHERE m.group_id = g.id
            ) AS last_msg_at,
            (
                SELECT m2.message FROM chat_messages m2
                WHERE m2.group_id = g.id
                ORDER BY m2.id DESC
                LIMIT 1
            ) AS last_msg,
            (
                SELECT m3.attachment_path FROM chat_messages m3
                WHERE m3.group_id = g.id
                ORDER BY m3.id DESC
                LIMIT 1
            ) AS last_attachment,
            (
                SELECT m4.is_deleted FROM chat_messages m4
                WHERE m4.group_id = g.id
                ORDER BY m4.id DESC
                LIMIT 1
            ) AS last_is_deleted,
            (
                SELECT COUNT(*) FROM chat_group_members gm2
                WHERE gm2.group_id = g.id
            ) AS member_count,
            (
                SELECT COUNT(*) FROM chat_messages mu
                WHERE mu.group_id = g.id AND mu.id > gm.last_read_message_id AND mu.sender_id <> ?
            ) AS unread_count
        FROM chat_group_members gm
        JOIN chat_groups g ON g.id = gm.group_id
        WHERE {$where}
        ORDER BY (last_msg_at IS NULL) ASC, last_msg_at DESC, g.group_name ASC
        LIMIT 300
    ";
    if ($q !== '') {
        $stmt = $conn->prepare($sqlG);
        $stmt->bind_param('iis', $uid, $uid, $q);
    } else {
        $stmt = $conn->prepare($sqlG);
        $stmt->bind_param('ii', $uid, $uid);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $preview = '';
        if (!empty($r['last_is_deleted'])) $preview = 'This message was deleted';
        elseif (!empty($r['last_attachment'])) $preview = 'Attachment';
        else $preview = (string)($r['last_msg'] ?? '');
        $preview = trim(preg_replace('/\s+/', ' ', $preview));
        if (mb_strlen($preview) > 80) $preview = mb_substr($preview, 0, 80) . '...';
        $groups[] = [
            'type' => 'group',
            'id' => (int)$r['group_id'],
            'name' => (string)$r['group_name'],
            'member_role' => (string)($r['member_role'] ?? 'member'),
            'member_count' => (int)($r['member_count'] ?? 0),
            'last_message_at' => $r['last_msg_at'] ?? null,
            'last_message_preview' => $preview,
            'unread_count' => (int)($r['unread_count'] ?? 0),
        ];
    }
    $stmt->close();
}

echo json_encode(['ok' => true, 'direct_chats' => $users, 'group_chats' => $groups]);
exit;
