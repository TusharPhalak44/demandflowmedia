<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requireLogin();
header('Content-Type: application/json');
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$peerId = isset($_GET['peer_id']) ? (int)$_GET['peer_id'] : 0;
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$conn = getDbConnection();
ensureChatSchema();

if ($groupId > 0) {
    $stmtM = $conn->prepare('SELECT role, last_read_message_id FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $stmtM->bind_param('ii', $groupId, $userId);
    $stmtM->execute();
    $mem = $stmtM->get_result()->fetch_assoc() ?: null;
    $stmtM->close();
    if (!$mem) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }

    $stmt = $conn->prepare('
        SELECT m.id, m.sender_id, m.receiver_id, m.group_id, m.message, m.attachment_path, m.message_type,
               m.delivered_at, m.read_at, m.is_deleted, m.deleted_by, m.deleted_at, m.created_at,
               u.full_name AS sender_name, u.profile_pic AS sender_profile_pic
        FROM chat_messages m
        LEFT JOIN users u ON u.id = m.sender_id
        WHERE m.group_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 200
    ');
    $stmt->bind_param('ii', $groupId, $sinceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    $maxId = 0;
    while($row = $res->fetch_assoc()){
        $maxId = max($maxId, (int)$row['id']);
        if (!empty($row['is_deleted'])) {
            $row['message'] = 'This message was deleted';
            $row['attachment_path'] = null;
        }
        $messages[] = $row;
    }
    $stmt->close();
    if ($maxId > 0) {
        $stmtU = $conn->prepare('UPDATE chat_group_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE group_id = ? AND user_id = ?');
        $stmtU->bind_param('iii', $maxId, $groupId, $userId);
        $stmtU->execute();
        $stmtU->close();
    }
    echo json_encode(['ok'=>true,'messages'=>$messages]);
    exit;
}

if($peerId<=0){ echo json_encode(['ok'=>false,'error'=>'Peer required']); exit; }

$peer = null;
$stmtP = $conn->prepare('SELECT id, role, client_id, vendor_id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
if ($stmtP) {
    $stmtP->bind_param('i', $peerId);
    $stmtP->execute();
    $peer = $stmtP->get_result()->fetch_assoc() ?: null;
    $stmtP->close();
}
if (!$peer) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Peer not found']);
    exit;
}

$meRole = strtolower((string)($user['role'] ?? ''));
$peerRole = strtolower((string)($peer['role'] ?? ''));
$isLeadership = isAdmin() || isDirector() || hasRole('manager_director');

if (str_starts_with($meRole, 'client_')) {
    $myClientId = (int)($user['client_id'] ?? 0);
    $peerClientId = (int)($peer['client_id'] ?? 0);
    $peerOk = ($peerClientId > 0 && $peerClientId === $myClientId && str_starts_with($peerRole, 'client_'))
        || in_array($peerRole, ['admin','director','manager_director'], true);
    if (!$peerOk) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Not allowed']);
        exit;
    }
} elseif (str_starts_with($meRole, 'vendor_')) {
    $myVendorId = (int)($user['vendor_id'] ?? 0);
    $peerVendorId = (int)($peer['vendor_id'] ?? 0);
    $peerOk = ($peerVendorId > 0 && $peerVendorId === $myVendorId && str_starts_with($peerRole, 'vendor_'))
        || in_array($peerRole, ['admin','director','manager_director'], true);
    if (!$peerOk) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Not allowed']);
        exit;
    }
} else {
    if ((str_starts_with($peerRole, 'client_') || str_starts_with($peerRole, 'vendor_')) && !$isLeadership) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Not allowed']);
        exit;
    }
}

$stmt = $conn->prepare('
    SELECT m.id, m.sender_id, m.receiver_id, m.group_id, m.message, m.attachment_path, m.message_type,
           m.delivered_at, m.read_at, m.is_deleted, m.deleted_by, m.deleted_at, m.created_at,
           u.full_name AS sender_name, u.profile_pic AS sender_profile_pic
    FROM chat_messages m
    LEFT JOIN users u ON u.id = m.sender_id
    WHERE m.group_id IS NULL
      AND ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))
      AND m.id>?
    ORDER BY m.id ASC
    LIMIT 200
');
$stmt->bind_param('iiiii', $userId, $peerId, $peerId, $userId, $sinceId);
$stmt->execute();
$res = $stmt->get_result();
$messages = [];
$toMark = [];
while($row = $res->fetch_assoc()){
    if (!empty($row['is_deleted'])) {
        $row['message'] = 'This message was deleted';
        $row['attachment_path'] = null;
    }
    $messages[] = $row;
    if ((int)$row['receiver_id'] === $userId && empty($row['read_at'])) { $toMark[] = (int)$row['id']; }
}
if (!empty($toMark)) {
    $ids = implode(',', array_map('intval',$toMark));
    @$conn->query("UPDATE chat_messages SET read_at = NOW() WHERE id IN ($ids)");
}
echo json_encode(['ok'=>true,'messages'=>$messages]);
exit;
