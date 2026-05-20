<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['sales_director','sales_manager','sdr','admin']);

header('Content-Type: application/json; charset=utf-8');

$payload = [
    'company_name' => $_GET['company_name'] ?? '',
    'website' => $_GET['website'] ?? '',
    'contact_email' => $_GET['contact_email'] ?? '',
    'linkedin_url' => $_GET['linkedin_url'] ?? '',
];
$excludeId = (int)($_GET['exclude_id'] ?? 0);
 $type = strtolower((string)($_GET['type'] ?? 'sales'));

try {
    if ($type === 'client') {
        $rows = findDuplicateClientsByNameOrDomain((string)$payload['company_name'], (string)$payload['website']);
        echo json_encode(['ok' => true, 'matches' => $rows], JSON_UNESCAPED_UNICODE);
    } else {
        $matches = findDuplicateSalesLeads($payload, $excludeId, 10);
        echo json_encode(['ok' => true, 'matches' => $matches], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to check duplicates'], JSON_UNESCAPED_UNICODE);
}
