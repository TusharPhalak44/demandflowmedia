<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(getKnownRoles());
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$to = trim((string)($_GET['to'] ?? ''));
$token = (string)($_GET['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo 'Invalid token';
    exit;
}

if ($id > 0 && $userId > 0) {
    markNotificationRead($userId, $id);
}

if ($to !== '') {
    header('Location: ' . $to);
    exit;
}

header('Location: ../users/profile');
exit;
