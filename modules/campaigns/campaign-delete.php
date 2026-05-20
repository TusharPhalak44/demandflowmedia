<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','sales_manager','operations_director']);
header('Content-Type: application/json');
ensureCsrfToken();
$csrf = $_POST['csrf_token'] ?? '';
if(!hash_equals($_SESSION['csrf_token'],$csrf)){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);
    exit;
}
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if($id<=0){ echo json_encode(['ok'=>false,'error'=>'Invalid ID']); exit; }
$ok = deleteCampaignHard($id);
echo json_encode(['ok'=>$ok]);
exit;
