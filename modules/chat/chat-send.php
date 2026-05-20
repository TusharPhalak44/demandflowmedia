<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
requireLogin();
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){ echo json_encode(['ok'=>false,'error'=>'Invalid method']); exit; }
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);
    exit;
}
$user = getCurrentUser();
$receiverId = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$message = trim($_POST['message'] ?? '');
$senderId = (int)($user['id'] ?? 0);
if($groupId<=0 && $receiverId<=0){ echo json_encode(['ok'=>false,'error'=>'Receiver required']); exit; }
$conn = getDbConnection();
ensureDatabaseSchema();
ensureChatSchema();
$attachmentPath = null;
$messageType = 'text';
if(!empty($_FILES['attachment']['name'])){
    $allowedExt = ['pdf','doc','docx','xls','xlsx','csv','png','jpg','jpeg','gif','mp3','wav','mp4'];
    $maxSize = 10*1024*1024;
    $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
    if(!in_array($ext,$allowedExt,true)){ echo json_encode(['ok'=>false,'error'=>'Unsupported file type']); exit; }
    if($_FILES['attachment']['size']>$maxSize){ echo json_encode(['ok'=>false,'error'=>'File too large']); exit; }
    $dir = __DIR__.'/../../uploads/chat_attachments';
    if(!is_dir($dir)){ @mkdir($dir,0775,true); }
    $name = uniqid('chat_').'.'.$ext; $target = $dir.'/'.$name;
    if(!move_uploaded_file($_FILES['attachment']['tmp_name'],$target)){ echo json_encode(['ok'=>false,'error'=>'Upload failed']); exit; }
    $attachmentPath = 'uploads/chat_attachments/'.$name;
    $messageType = 'file';
}

if ($groupId > 0) {
    $stmtM = $conn->prepare('SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $stmtM->bind_param('ii', $groupId, $senderId);
    $stmtM->execute();
    $mem = $stmtM->get_result()->fetch_assoc() ?: null;
    $stmtM->close();
    if (!$mem) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }

    $stmt = $conn->prepare('INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, attachment_path, message_type, delivered_at, created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())');
    $zero = 0;
    $stmt->bind_param('iiisss', $senderId, $zero, $groupId, $message, $attachmentPath, $messageType);
    $ok = $stmt->execute();
    $newId = (int)($stmt->insert_id ?? 0);
    $stmt->close();

    if ($ok && $senderId > 0) {
        $gStmt = $conn->prepare('SELECT group_name FROM chat_groups WHERE id = ? LIMIT 1');
        $gStmt->bind_param('i', $groupId);
        $gStmt->execute();
        $gRow = $gStmt->get_result()->fetch_assoc() ?: [];
        $gStmt->close();
        $groupName = (string)($gRow['group_name'] ?? 'Group');
        $senderName = (string)($user['full_name'] ?? 'User');
        $title = $groupName;
        $preview = $messageType === 'file' ? 'Sent an attachment' : $message;
        $preview = trim(preg_replace('/\s+/', ' ', (string)$preview));
        if (mb_strlen($preview) > 80) $preview = mb_substr($preview, 0, 80) . '...';
        $body = $senderName . ': ' . $preview;
        $link = '../chat/chat?group_id=' . (int)$groupId;

        $rs = $conn->prepare('SELECT user_id FROM chat_group_members WHERE group_id = ?');
        $rs->bind_param('i', $groupId);
        $rs->execute();
        $res = $rs->get_result();
        while ($r = $res->fetch_assoc()) {
            $to = (int)($r['user_id'] ?? 0);
            if ($to <= 0 || $to === $senderId) continue;
            createNotificationSmart($to, 'chat.group_message', $title, $body, $link, [
                'importance' => 'high',
                'show_toast' => true,
                'dedup_key' => 'chat_gm:' . $groupId . ':' . $newId,
                'dedup_window_min' => 1,
            ]);
        }
        $rs->close();
    }

    echo json_encode(['ok'=>$ok]);
    exit;
}

$isVendorUser = function_exists('isVendor') && isVendor();
$isClientUser = function_exists('isClient') && isClient();
$allowedUserIds = null;
if ($isClientUser) {
    $clientId = (int)($user['client_id'] ?? 0);
    $allowed = [];
    if ($clientId > 0) {
        $stmtCU = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND client_id = ? AND role LIKE 'client_%'");
        $stmtCU->bind_param('i', $clientId);
        $stmtCU->execute();
        $resCU = $stmtCU->get_result();
        while ($r = $resCU->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $senderId) $allowed[$id] = true; }
        $stmtCU->close();
        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director')");
        if ($rsA) { while ($r = $rsA->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $senderId) $allowed[$id] = true; } }
    }
    $allowedUserIds = array_keys($allowed);
} elseif ($isVendorUser) {
    $vendorId = (int)($user['vendor_id'] ?? 0);
    $allowed = [];
    if ($vendorId > 0) {
        $stmtVU = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND vendor_id = ? AND role LIKE 'vendor_%'");
        $stmtVU->bind_param('i', $vendorId);
        $stmtVU->execute();
        $resVU = $stmtVU->get_result();
        while ($r = $resVU->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $senderId) $allowed[$id] = true; }
        $stmtVU->close();
        $rsA = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director')");
        if ($rsA) { while ($r = $rsA->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $senderId) $allowed[$id] = true; } }
    }
    $allowedUserIds = array_keys($allowed);
} elseif (!(isAdmin() || isDirector() || hasRole('manager_director'))) {
    $allowed = [];
    $teamIds = getUserTeamIds($senderId);
    if (!empty($teamIds)) {
        $in = implode(',', array_fill(0, count($teamIds), '?'));
        $types = str_repeat('i', count($teamIds));
        $stmtT = $conn->prepare("SELECT DISTINCT user_id FROM team_members WHERE team_id IN ($in)");
        $stmtT->bind_param($types, ...$teamIds);
        $stmtT->execute();
        $resT = $stmtT->get_result();
        while ($r = $resT->fetch_assoc()) { $id = (int)($r['user_id'] ?? 0); if ($id > 0 && $id !== $senderId) $allowed[$id] = true; }
        $stmtT->close();
    }
    $rs = $conn->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','director','manager_director','operations_director')");
    if ($rs) { while ($r = $rs->fetch_assoc()) { $id = (int)($r['id'] ?? 0); if ($id > 0 && $id !== $senderId) $allowed[$id] = true; } }

    $stmtH = $conn->prepare("
        SELECT DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id
        FROM chat_messages
        WHERE group_id IS NULL AND (sender_id = ? OR receiver_id = ?)
    ");
    if ($stmtH) {
        $stmtH->bind_param('iii', $senderId, $senderId, $senderId);
        $stmtH->execute();
        $resH = $stmtH->get_result();
        while ($r = $resH->fetch_assoc()) {
            $id = (int)($r['other_id'] ?? 0);
            if ($id > 0 && $id !== $senderId) $allowed[$id] = true;
        }
        $stmtH->close();
    }
    $allowedUserIds = array_keys($allowed);
}

if ($receiverId > 0 && is_array($allowedUserIds) && !in_array($receiverId, $allowedUserIds, true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Access denied: you are not allowed to chat with this user']);
    exit;
}

$isInternalUser = !$isClientUser && !$isVendorUser;
$isLeadership = isAdmin() || isDirector() || hasRole('manager_director');
if ($receiverId > 0 && $isInternalUser && !$isLeadership) {
    $stmtR = $conn->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    if ($stmtR) {
        $stmtR->bind_param('i', $receiverId);
        $stmtR->execute();
        $rr = $stmtR->get_result()->fetch_assoc() ?: [];
        $stmtR->close();
        $rRole = strtolower((string)($rr['role'] ?? ''));
        if (str_starts_with($rRole, 'client_') || str_starts_with($rRole, 'vendor_')) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Access denied: only Admin/Directors can message clients/vendors']);
            exit;
        }
    }
}

$stmt = $conn->prepare('INSERT INTO chat_messages (sender_id, receiver_id, message, attachment_path, message_type, delivered_at, created_at) VALUES (?,?,?,?,?,NOW(),NOW())');
$stmt->bind_param('iisss', $senderId, $receiverId, $message, $attachmentPath, $messageType);
$ok = $stmt->execute();
$stmt->close();
if ($ok && $senderId > 0 && $receiverId > 0 && $senderId !== $receiverId) {
    $senderName = (string)($user['full_name'] ?? 'User');
    $title = 'New message';
    $preview = $messageType === 'file' ? 'Sent an attachment' : $message;
    $preview = trim(preg_replace('/\s+/', ' ', (string)$preview));
    if (mb_strlen($preview) > 80) $preview = mb_substr($preview, 0, 80) . '...';
    $body = $senderName . ': ' . $preview;
    $link = '../chat/chat?user_id=' . $senderId;
    createNotificationSmart($receiverId, 'chat.message', $title, $body, $link, ['importance'=>'high','show_toast'=>true]);
}
echo json_encode(['ok'=>$ok]);
exit;
