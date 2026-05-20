<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();

$user = getCurrentUser();
$isAdmin = in_array((string)($user['role'] ?? ''), ['admin'], true);

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : '';
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];

$requestedAgentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$agentId = $isAdmin ? $requestedAgentId : (int)($user['id'] ?? 0);

$agents = [];
if ($agentId > 0) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) { http_response_code(500); echo 'Database error'; exit; }
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { http_response_code(404); echo 'Not found'; exit; }
    $agents[] = ['id' => (int)$row['id'], 'name' => (string)$row['full_name']];
} else {
    if (!$isAdmin) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    $targets = getMonthlyTargets($year, $month);
    foreach ($targets as $t) {
        $agents[] = ['id' => (int)($t['agent_id'] ?? 0), 'name' => (string)($t['agent_name'] ?? '')];
    }
}

$fileName = 'productivity_' . $monthStr . ($agentId > 0 ? ('_agent_' . $agentId) : '') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'month',
    'agent_id',
    'agent_name',
    'date',
    'daily_target_mql',
    'email_count',
    'mql_count',
    'bant_count',
    'appointment_count',
    'achieved_mql',
    'daily_percent',
    'met_daily_target',
    'base_incentive',
    'extra_incentive',
    'daily_incentive',
    'month_total_mql',
    'month_overall_percent',
    'month_incentive',
    'month_daily_incentives',
    'month_total_incentives',
]);

foreach ($agents as $a) {
    $aid = (int)($a['id'] ?? 0);
    if ($aid <= 0) continue;
    $stats = getAgentMonthlyStats($aid, $year, $month);
    $target = is_array($stats['target'] ?? null) ? $stats['target'] : [];
    $dailyTarget = (int)($target['daily_target'] ?? 0);

    $monthTotalMql = (float)($stats['stats']['total_mql'] ?? 0);
    $monthPct = (float)($stats['stats']['overall_percent'] ?? 0);
    $monthInc = (int)($stats['incentives']['monthly_bonus'] ?? 0);
    $monthDailyInc = (int)($stats['incentives']['daily_total'] ?? 0);
    $monthTotalInc = (int)($stats['incentives']['total'] ?? 0);

    foreach (($stats['daily'] ?? []) as $row) {
        $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
        $email = (int)($counts['Email Marketing'] ?? 0);
        $mql = (int)($counts['Marketing Qualified Leads'] ?? 0);
        $bant = (int)($counts['BANT'] ?? 0);
        $appt = (int)($counts['Appointment Generation'] ?? 0);
        fputcsv($out, [
            $monthStr,
            $aid,
            (string)($a['name'] ?? ''),
            (string)($row['date'] ?? ''),
            $dailyTarget,
            $email,
            $mql,
            $bant,
            $appt,
            number_format((float)($row['achieved_mql'] ?? 0), 2, '.', ''),
            number_format((float)($row['daily_percent'] ?? 0), 1, '.', ''),
            !empty($row['met_daily_target']) ? 1 : 0,
            (int)($row['base_incentive'] ?? 0),
            (int)($row['extra_incentive'] ?? 0),
            (int)($row['daily_incentive'] ?? 0),
            number_format($monthTotalMql, 2, '.', ''),
            number_format($monthPct, 1, '.', ''),
            $monthInc,
            $monthDailyInc,
            $monthTotalInc,
        ]);
    }
}
fclose($out);
exit;

