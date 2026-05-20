<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(getKnownRoles());
ensureCsrfToken();
ensureDatabaseSchema();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['ok' => false]);
    exit;
}

$last = (int)($_SESSION['campaign_end_check_ts'] ?? 0);
if ($last <= 0 || (time() - $last) > 900) {
    $_SESSION['campaign_end_check_ts'] = time();
    if (function_exists('notifyCampaignEndWarningsForUser')) notifyCampaignEndWarningsForUser($userId);
}

$sinceId = (int)($_POST['since_id'] ?? 0);
$conn = getDbConnection();
$rows = [];
if ($sinceId > 0) {
    $stmt = $conn->prepare("SELECT id, title, body, link_url, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND show_toast = 1 AND id > ? ORDER BY id ASC LIMIT 5");
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $sinceId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT id, title, body, link_url, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND show_toast = 1 ORDER BY id DESC LIMIT 3");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    $rows = array_reverse($rows);
}

echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
