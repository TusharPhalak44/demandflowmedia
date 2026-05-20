<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requireLogin();
ensureChatSchema();
header('Content-Type: application/json');
$user = getCurrentUser();
$uid = (int)($user['id'] ?? 0);
$conn = getDbConnection();
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

        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director')");
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

        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director')");
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

$where = "u.is_active = 1 AND u.id <> ?";
if (!$isClientUser && !$isVendorUser) {
    $where .= " AND u.role NOT LIKE 'client_%' AND u.role NOT LIKE 'vendor_%'";
}
if (is_array($allowedUserIds)) {
    if (empty($allowedUserIds)) { echo json_encode(['ok'=>true,'users'=>[]]); exit; }
    $in = implode(',', array_fill(0, count($allowedUserIds), '?'));
    $where .= " AND u.id IN ($in)";
}

$sql = "SELECT 
  u.id, u.full_name, u.role, u.profile_pic, COALESCE(p.is_online,0) AS is_online, p.last_seen,
  (
    SELECT MAX(m.created_at) FROM chat_messages m 
    WHERE m.group_id IS NULL AND ((m.sender_id=u.id AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=u.id))
  ) AS last_msg_at,
  (
    SELECT COUNT(*) FROM chat_messages m2 
    WHERE m2.group_id IS NULL AND m2.sender_id=u.id AND m2.receiver_id=? AND m2.read_at IS NULL
  ) AS unread_count
FROM users u 
LEFT JOIN user_presence p ON p.user_id = u.id 
WHERE {$where}
ORDER BY (last_msg_at IS NULL) ASC, last_msg_at DESC, u.full_name ASC";
$stmt = $conn->prepare($sql);
if (is_array($allowedUserIds)) {
    $types = 'iiii' . str_repeat('i', count($allowedUserIds));
    $params = array_merge([$uid, $uid, $uid, $uid], $allowedUserIds);
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param('iiii', $uid, $uid, $uid, $uid);
}
$stmt->execute();
$res = $stmt->get_result();
$users = [];
while($row = $res->fetch_assoc()){
  $users[] = [
    'id'=>(int)$row['id'],
    'full_name'=>$row['full_name'],
    'role'=>$row['role'],
    'profile_pic'=>(string)($row['profile_pic'] ?? ''),
    'is_online'=>(int)$row['is_online']===1,
    'last_seen'=>$row['last_seen'],
    'last_msg_at'=>$row['last_msg_at'],
    'unread_count'=>(int)($row['unread_count'] ?? 0)
  ];
}
echo json_encode(['ok'=>true,'users'=>$users]);
exit;
