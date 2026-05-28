<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$allowedRoles = function_exists('getKnownRoles') ? getKnownRoles() : ['admin'];
requireRole($allowedRoles);
ensureCsrfToken();

$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$rows = getAttendanceDashboard($date);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance_' . $date . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['date','user_id','name','role','punch_in','punch_out','state','shift_start','grace_minutes','late_minutes','break_minutes','working_minutes','status']);
foreach ($rows as $r) {
    fputcsv($out, [
        $date,
        (int)($r['user_id'] ?? 0),
        (string)($r['full_name'] ?? ''),
        (string)($r['role'] ?? ''),
        (string)($r['punch_in'] ?? ''),
        (string)($r['punch_out'] ?? ''),
        (string)($r['current_state'] ?? ''),
        (string)($r['shift_start_time'] ?? ''),
        (int)($r['grace_minutes'] ?? 15),
        (int)($r['late_minutes'] ?? 0),
        (int)($r['break_minutes'] ?? 0),
        (int)($r['working_minutes'] ?? 0),
        (string)($r['status'] ?? ''),
    ]);
}
fclose($out);
exit;
