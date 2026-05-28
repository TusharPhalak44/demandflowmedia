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

$lastCampaign = (int)($_SESSION['notif_scheduler_campaign_ts'] ?? 0);
if ($lastCampaign <= 0 || (time() - $lastCampaign) > 900) {
    $_SESSION['notif_scheduler_campaign_ts'] = time();
    if (function_exists('notifyCampaignEndWarningsForUser')) notifyCampaignEndWarningsForUser($userId);
    if (function_exists('notifyCampaignPacingRiskForUser')) notifyCampaignPacingRiskForUser($userId);
}

$lastSales = (int)($_SESSION['notif_scheduler_sales_ts'] ?? 0);
if ($lastSales <= 0 || (time() - $lastSales) > 60) {
    $_SESSION['notif_scheduler_sales_ts'] = time();
    if (function_exists('notifySalesFollowupRemindersForUser')) notifySalesFollowupRemindersForUser($userId);
}

$sinceId = (int)($_POST['since_id'] ?? 0);
$conn = getDbConnection();
$rows = [];
$filter = buildCampaignAccessSqlFilterForNotifications($userId, 'campaign_id');
if ($sinceId > 0) {
    $sql = "SELECT id, title, body, link_url, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND show_toast = 1 AND id > ? AND " . $filter['sql'] . " ORDER BY id ASC LIMIT 5";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = 'ii' . (string)($filter['types'] ?? '');
        $params = array_merge([(int)$userId, (int)$sinceId], (array)($filter['params'] ?? []));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} else {
    $sql = "SELECT id, title, body, link_url, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND show_toast = 1 AND " . $filter['sql'] . " ORDER BY id DESC LIMIT 3";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = 'i' . (string)($filter['types'] ?? '');
        $params = array_merge([(int)$userId], (array)($filter['params'] ?? []));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    $rows = array_reverse($rows);
}

echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
