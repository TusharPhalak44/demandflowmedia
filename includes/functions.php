<?php
/**
 * DemandFlow Bridge CRM - Core Functions
 * Version: 1.0.1 (Stable)
 */
/**
 * Helper Functions
 * 
 * Contains utility functions used throughout the application
 */

// Include database connection and constants/helpers
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/constants.php';
// Optional Google Sheets webhook config
// If present, used to forward leads per-campaign
if (file_exists(__DIR__ . '/../config/google_sheets.php')) {
    require_once __DIR__ . '/../config/google_sheets.php';
}

/**
 * Sanitize output for HTML
 */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize input (XSS protection)
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
    } else {
        $data = trim((string)$data);
        $data = strip_tags($data);
    }
    return $data;
}

/**
 * Compute working days (Mon-Fri) for a given month and year
 */
function getWorkingDays($year, $month) {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    $holidaySet = [];
    foreach (getHolidaysForMonth((int)$year, (int)$month, 'US') as $h) {
        $d = (string)($h['holiday_date'] ?? '');
        if ($d !== '') $holidaySet[$d] = true;
    }
    $working = 0;
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $ts = mktime(0, 0, 0, (int)$month, $day, (int)$year);
        $weekday = (int)date('N', $ts);
        if ($weekday > 5) { continue; }
        $date = date('Y-m-d', $ts);
        if (isset($holidaySet[$date])) { continue; }
        $working++;
    }
    return $working;
}

function holidaysTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'holidays' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    if (!$exists) {
        ensureDatabaseSchema();
        $rs2 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'holidays' LIMIT 1");
        $exists = (bool)($rs2 && $rs2->fetch_assoc());
    }
    return $exists;
}

function ensureUsFederalHolidaysForYear(int $year): void {
    if ($year < 1970 || $year > 2100) return;
    if (!holidaysTableExists()) return;
    $conn = getDbConnection();
    $country = 'US';

    $fixed = [
        ['name' => "New Year's Day", 'm' => 1, 'd' => 1],
        ['name' => "Juneteenth National Independence Day", 'm' => 6, 'd' => 19],
        ['name' => "Independence Day", 'm' => 7, 'd' => 4],
        ['name' => "Veterans Day", 'm' => 11, 'd' => 11],
        ['name' => "Christmas Day", 'm' => 12, 'd' => 25],
    ];

    $dates = [];
    foreach ($fixed as $h) {
        $actual = sprintf('%04d-%02d-%02d', $year, $h['m'], $h['d']);
        $ts = strtotime($actual);
        if ($ts === false) continue;
        $weekday = (int)date('N', $ts);
        $observed = $actual;
        $suffix = '';
        if ($weekday === 6) { $observed = date('Y-m-d', strtotime($actual . ' -1 day')); $suffix = ' (Observed)'; }
        elseif ($weekday === 7) { $observed = date('Y-m-d', strtotime($actual . ' +1 day')); $suffix = ' (Observed)'; }
        $dates[] = ['date' => $observed, 'name' => $h['name'] . $suffix];
    }

    $dates[] = ['date' => usHolidayNthWeekday($year, 1, 1, 3), 'name' => 'Martin Luther King Jr. Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 2, 1, 3), 'name' => "Washington's Birthday"];
    $dates[] = ['date' => usHolidayLastWeekday($year, 5, 1), 'name' => 'Memorial Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 9, 1, 1), 'name' => 'Labor Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 10, 1, 2), 'name' => 'Columbus Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 11, 4, 4), 'name' => 'Thanksgiving Day'];

    $sql = "INSERT INTO holidays (country_code, holiday_date, name) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    foreach ($dates as $d) {
        $date = (string)($d['date'] ?? '');
        $name = (string)($d['name'] ?? '');
        if ($date === '' || $name === '') continue;
        $stmt->bind_param('sss', $country, $date, $name);
        $stmt->execute();
    }
    $stmt->close();
}

function usHolidayNthWeekday(int $year, int $month, int $weekdayMonIs1, int $n): string {
    $first = strtotime(sprintf('%04d-%02d-01', $year, $month));
    if ($first === false) return '';
    $firstWeekday = (int)date('N', $first);
    $delta = ($weekdayMonIs1 - $firstWeekday + 7) % 7;
    $day = 1 + $delta + (7 * ($n - 1));
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function usHolidayLastWeekday(int $year, int $month, int $weekdayMonIs1): string {
    $last = strtotime(sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year)));
    if ($last === false) return '';
    $lastWeekday = (int)date('N', $last);
    $delta = ($lastWeekday - $weekdayMonIs1 + 7) % 7;
    $day = (int)date('j', $last) - $delta;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function appBasePath(): string {
    $uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $uriPath = preg_replace('#/+#', '/', str_replace('\\', '/', $uriPath));

    $projectRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $projectRoot = preg_replace('#/+#', '/', str_replace('\\', '/', (string)$projectRoot));
    $projectSlug = trim((string)basename($projectRoot), '/');
    $needle = $projectSlug !== '' ? ('/' . $projectSlug) : '';

    $pickBaseFromPath = function (string $path) use ($needle): string {
        $path = preg_replace('#/+#', '/', str_replace('\\', '/', $path));
        if ($needle === '') return '';

        $pos = stripos($path, $needle);
        if ($pos === false) return '';

        $beforeOk = $pos === 0 || ($path[$pos - 1] ?? '') === '/';
        $afterChar = $path[$pos + strlen($needle)] ?? '';
        $afterOk = $afterChar === '' || $afterChar === '/';
        if (!$beforeOk || !$afterOk) return '';

        $candidate = substr($path, 0, $pos + strlen($needle));
        if (preg_match('#^/[A-Za-z]:/#', $candidate) || strpos($candidate, ':/') !== false) return $needle;
        return $candidate;
    };

    if ($needle !== '') {
        $b = $pickBaseFromPath($uriPath);
        if ($b !== '') return $b;
    }

    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $script = preg_replace('#/+#', '/', str_replace('\\', '/', $script));
    if ($needle !== '') {
        $b = $pickBaseFromPath($script);
        if ($b !== '') return $b;
    }

    if ($script === '' || $script[0] !== '/') return '';

    $pos = strpos($script, '/modules/');
    if ($pos !== false) {
        $base = substr($script, 0, $pos);
        if ($needle !== '') {
            $b = $pickBaseFromPath($base);
            if ($b !== '') return $b;
        }
        if (preg_match('#^/[A-Za-z]:/#', $base) || strpos($base, ':/') !== false) return $needle !== '' ? $needle : '';
        return $base !== '' ? $base : '';
    }
    $pos2 = strpos($script, '/includes/');
    if ($pos2 !== false) {
        $base = substr($script, 0, $pos2);
        if ($needle !== '') {
            $b = $pickBaseFromPath($base);
            if ($b !== '') return $b;
        }
        if (preg_match('#^/[A-Za-z]:/#', $base) || strpos($base, ':/') !== false) return $needle !== '' ? $needle : '';
        return $base !== '' ? $base : '';
    }

    if (preg_match('#^/[A-Za-z]:/#', $script) || strpos($script, ':/') !== false) return $needle !== '' ? $needle : '';
    $parts = explode('/', trim($script, '/'));
    if (count($parts) <= 0) return '';
    $first = (string)($parts[0] ?? '');
    if (preg_match('#^[A-Za-z]:$#', $first)) return $needle !== '' ? $needle : '';
    return '/' . $first;
}

function appSafeInternalUrl(string $url): string {
    $u = trim($url);
    if ($u === '') return '';
    if (stripos($u, 'javascript:') === 0) return '';
    $parts = parse_url($u);
    if ($parts === false) return '';

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (isset($parts['host']) && $host !== '' && strcasecmp((string)$parts['host'], $host) !== 0) return '';

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || $path[0] !== '/') return '';
    if (strpos($path, '..') !== false) return '';

    $base = appBasePath();
    if ($base !== '/' && strpos($path, $base . '/') !== 0) return '';

    $qs = isset($parts['query']) && (string)$parts['query'] !== '' ? ('?' . (string)$parts['query']) : '';
    return $path . $qs;
}

function appBackUrl(string $fallback = ''): string {
    $back = isset($_GET['back']) ? (string)$_GET['back'] : '';
    $safe = appSafeInternalUrl($back);
    if ($safe !== '') return $safe;

    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $safe2 = appSafeInternalUrl($ref);
    if ($safe2 !== '') return $safe2;

    return $fallback;
}

function getHolidaysForMonth(int $year, int $month, string $countryCode = 'US'): array {
    if ($year < 1970 || $year > 2100) return [];
    if (!holidaysTableExists()) return [];
    ensureUsFederalHolidaysForYear($year - 1);
    ensureUsFederalHolidaysForYear($year);
    ensureUsFederalHolidaysForYear($year + 1);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT holiday_date, name FROM holidays WHERE country_code = ? AND holiday_date >= ? AND holiday_date <= ? ORDER BY holiday_date ASC");
    if (!$stmt) return [];
    $stmt->bind_param('sss', $countryCode, $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getUpcomingHolidays(string $fromDate, int $limit = 5, string $countryCode = 'US'): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) return [];
    $limit = max(1, min(50, (int)$limit));
    $year = (int)substr($fromDate, 0, 4);
    if ($year < 1970 || $year > 2100) return [];
    if (!holidaysTableExists()) return [];
    ensureUsFederalHolidaysForYear($year - 1);
    ensureUsFederalHolidaysForYear($year);
    ensureUsFederalHolidaysForYear($year + 1);
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT holiday_date, name FROM holidays WHERE country_code = ? AND holiday_date >= ? ORDER BY holiday_date ASC LIMIT $limit");
    if (!$stmt) return [];
    $stmt->bind_param('ss', $countryCode, $fromDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getInternalPayrollUsers(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, role, job_title, department, employee_id FROM users WHERE (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) ORDER BY full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function getUserBankDetails(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT bank_name, account_number, account_type, ifsc_code, pan_number FROM user_bank_details WHERE user_id = ? LIMIT 1");
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function getUserPersonalDetails(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT personal_email, emergency_contact_number, date_of_birth FROM user_personal_details WHERE user_id = ? LIMIT 1");
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function maskAccountNumber(string $acct): string {
    $acct = preg_replace('/\s+/', '', $acct);
    $len = strlen($acct);
    if ($len <= 4) return $acct;
    return str_repeat('X', max(0, $len - 4)) . substr($acct, -4);
}

function ensureDefaultShiftExists(): void {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id FROM hr_shifts WHERE active = 1 ORDER BY id ASC LIMIT 1");
    $row = $rs ? ($rs->fetch_assoc() ?: null) : null;
    if ($row) return;
    $stmt = $conn->prepare("INSERT INTO hr_shifts (name, start_time, end_time, grace_minutes, active) VALUES ('General', '09:30:00', '18:30:00', 15, 1)");
    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    }
}

function getUserShiftForDate(int $userId, string $workDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    ensureDefaultShiftExists();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT s.*
        FROM hr_user_shift_assignments a
        JOIN hr_shifts s ON s.id = a.shift_id
        WHERE a.user_id = ? AND a.effective_date <= ? AND s.active = 1
        ORDER BY a.effective_date DESC, a.id DESC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $workDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) return $row;
    }
    $rs = $conn->query("SELECT * FROM hr_shifts WHERE active = 1 ORDER BY id ASC LIMIT 1");
    return $rs ? ($rs->fetch_assoc() ?: null) : null;
}

function setAttendanceDayShiftMeta(int $attendanceDayId, int $userId, string $workDate): void {
    $attendanceDayId = (int)$attendanceDayId;
    $userId = (int)$userId;
    if ($attendanceDayId <= 0 || $userId <= 0) return;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return;
    $shift = getUserShiftForDate($userId, $workDate);
    if (!$shift) return;
    $conn = getDbConnection();
    $shiftId = (int)($shift['id'] ?? 0);
    $start = (string)($shift['start_time'] ?? '');
    $grace = (int)($shift['grace_minutes'] ?? 15);
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET shift_id = COALESCE(shift_id, ?), shift_start_time = COALESCE(shift_start_time, ?), grace_minutes = CASE WHEN grace_minutes IS NULL THEN ? ELSE grace_minutes END WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('isii', $shiftId, $start, $grace, $attendanceDayId);
        $stmt->execute();
        $stmt->close();
    }
}

function getAttendanceDay(int $userId, string $workDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_attendance_days WHERE user_id = ? AND work_date = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $workDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function ensureAttendanceDay(int $userId, string $workDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT IGNORE INTO hr_attendance_days (user_id, work_date, status) VALUES (?, ?, 'Absent')");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $workDate);
        $stmt->execute();
        $stmt->close();
    }
    $day = getAttendanceDay($userId, $workDate);
    if ($day) setAttendanceDayShiftMeta((int)$day['id'], $userId, $workDate);
    return getAttendanceDay($userId, $workDate);
}

function getOpenAttendanceDayForUser(int $userId, ?string $at = null, int $lookbackDays = 2): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    $at = $at ?: hrNow();
    $lookbackDays = max(1, (int)$lookbackDays);
    $minDate = date('Y-m-d', strtotime($at . ' -' . $lookbackDays . ' day'));
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT *
        FROM hr_attendance_days
        WHERE user_id = ?
          AND work_date >= ?
          AND punch_in IS NOT NULL
          AND punch_in <> ''
          AND (punch_out IS NULL OR punch_out = '')
        ORDER BY work_date DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $minDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getOpenAttendanceState(int $attendanceDayId): ?array {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_attendance_states WHERE attendance_day_id = ? AND end_at IS NULL ORDER BY start_at DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $attendanceDayId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function hrNow(): string {
    return date('Y-m-d H:i:s');
}

function punchIn(int $userId, ?string $at = null): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    $at = $at ?: hrNow();
    $workDateToday = date('Y-m-d', strtotime($at));
    $workDate = $workDateToday;
    $tNow = date('H:i:s', strtotime($at));
    $yesterday = date('Y-m-d', strtotime($workDateToday . ' -1 day'));

    $shiftToday = getUserShiftForDate($userId, $workDateToday);
    $shiftYesterday = getUserShiftForDate($userId, $yesterday);
    $isOvernightEarly = function(?array $shift) use ($tNow): bool {
        if (!$shift) return false;
        $start = substr((string)($shift['start_time'] ?? ''), 0, 8);
        $end = substr((string)($shift['end_time'] ?? ''), 0, 8);
        return ($start !== '' && $end !== '' && $end <= $start && $tNow < $end);
    };
    if ($isOvernightEarly($shiftToday) || $isOvernightEarly($shiftYesterday)) {
        $workDate = $yesterday;
    }
    $day = ensureAttendanceDay($userId, $workDate);
    if (!$day) return false;
    setAttendanceDayShiftMeta((int)$day['id'], $userId, $workDate);
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_in = COALESCE(punch_in, ?), status = CASE WHEN punch_in IS NULL THEN 'In Progress' ELSE status END, updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    $dayId = (int)$day['id'];
    $stmt->bind_param('si', $at, $dayId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) recomputeAttendanceDay($dayId);
    return $ok;
}

function punchOut(int $userId, ?string $at = null): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    $at = $at ?: hrNow();
    $openDay = getOpenAttendanceDayForUser($userId, $at);
    $workDate = $openDay ? (string)($openDay['work_date'] ?? '') : date('Y-m-d', strtotime($at));
    $day = $openDay ?: ensureAttendanceDay($userId, $workDate);
    if (!$day) return false;
    $dayId = (int)($day['id'] ?? 0);
    if ($dayId <= 0) return false;
    setAttendanceDayShiftMeta($dayId, $userId, $workDate);
    $open = getOpenAttendanceState($dayId);
    if ($open) {
        endAttendanceStateByDayId($dayId, $at);
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_out = ?, current_state = 'Off', updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('si', $at, $dayId);
    $ok = $stmt->execute();
    $stmt->close();
    recomputeAttendanceDay($dayId);
    return $ok;
}

function startAttendanceState(int $userId, string $state, ?string $at = null): array {
    $allowed = ['Working','Break1','Break2','Lunch','Meeting'];
    $state = trim($state);
    if (!in_array($state, $allowed, true)) return ['ok' => false, 'error' => 'Invalid state'];
    $userId = (int)$userId;
    if ($userId <= 0) return ['ok' => false, 'error' => 'Invalid user'];
    $at = $at ?: hrNow();
    $openDay = getOpenAttendanceDayForUser($userId, $at);
    $workDate = $openDay ? (string)($openDay['work_date'] ?? '') : date('Y-m-d', strtotime($at));
    $day = $openDay ?: ensureAttendanceDay($userId, $workDate);
    if (!$day) return ['ok' => false, 'error' => 'Attendance record missing'];
    if (empty($day['punch_in'])) {
        punchIn($userId, $at);
        $day = getAttendanceDay($userId, $workDate) ?: $day;
    }
    if (!empty($day['punch_in']) && !empty($day['punch_out']) && (string)($day['status'] ?? '') === 'Absent') {
        return ['ok' => false, 'error' => 'Marked Absent due to late policy'];
    }
    $dayId = (int)($day['id'] ?? 0);
    if ($dayId <= 0) return ['ok' => false, 'error' => 'Attendance record missing'];
    setAttendanceDayShiftMeta($dayId, $userId, $workDate);

    $open = getOpenAttendanceState($dayId);
    if ($open) {
        if ((string)($open['state'] ?? '') === $state) return ['ok' => true];
        endAttendanceStateByDayId($dayId, $at);
    }

    if (in_array($state, ['Break1','Break2','Lunch'], true)) {
        $limits = ['Break1' => 15, 'Break2' => 15, 'Lunch' => 45];
        $used = getBreakMinutesUsed($dayId, $state);
        if ($used >= (int)$limits[$state]) return ['ok' => false, 'error' => 'Break limit reached'];
    }

    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO hr_attendance_states (attendance_day_id, state, start_at) VALUES (?, ?, ?)");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error'];
    $stmt->bind_param('iss', $dayId, $state, $at);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $stmt2 = $conn->prepare("UPDATE hr_attendance_days SET current_state = ?, status = CASE WHEN status IN ('Full Day','Half Day','Absent','Paid Leave','Unpaid Leave') THEN status ELSE 'In Progress' END, updated_at = NOW() WHERE id = ?");
        if ($stmt2) {
            $stmt2->bind_param('si', $state, $dayId);
            $stmt2->execute();
            $stmt2->close();
        }
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Database error'];
}

function endAttendanceState(int $userId, ?string $at = null): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    $at = $at ?: hrNow();
    $openDay = getOpenAttendanceDayForUser($userId, $at);
    if ($openDay) {
        $dayId = (int)($openDay['id'] ?? 0);
        return $dayId > 0 ? endAttendanceStateByDayId($dayId, $at) : false;
    }
    $workDate = date('Y-m-d', strtotime($at));
    $day = getAttendanceDay($userId, $workDate);
    if (!$day) return false;
    return endAttendanceStateByDayId((int)$day['id'], $at);
}

function endAttendanceStateByDayId(int $attendanceDayId, string $at): bool {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return false;
    $conn = getDbConnection();
    $open = getOpenAttendanceState($attendanceDayId);
    if (!$open) return true;
    $start = (string)($open['start_at'] ?? '');
    $state = (string)($open['state'] ?? '');
    $startTs = strtotime($start);
    $endTs = strtotime($at);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) $endTs = $startTs;
    $minutes = (int)floor(($endTs - $startTs) / 60);

    if (in_array($state, ['Break1','Break2','Lunch'], true)) {
        $limits = ['Break1' => 15, 'Break2' => 15, 'Lunch' => 45];
        $used = getBreakMinutesUsed($attendanceDayId, $state);
        $remaining = max(0, (int)$limits[$state] - $used);
        if ($minutes > $remaining) {
            $minutes = $remaining;
            $endTs = $startTs + ($minutes * 60);
            $at = date('Y-m-d H:i:s', $endTs);
        }
    }

    $stmt = $conn->prepare("UPDATE hr_attendance_states SET end_at = ?, minutes = ? WHERE id = ?");
    if (!$stmt) return false;
    $id = (int)$open['id'];
    $stmt->bind_param('sii', $at, $minutes, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $stmt2 = $conn->prepare("UPDATE hr_attendance_days SET current_state = 'Working', updated_at = NOW() WHERE id = ?");
    if ($stmt2) {
        $stmt2->bind_param('i', $attendanceDayId);
        $stmt2->execute();
        $stmt2->close();
    }
    recomputeAttendanceDay($attendanceDayId);
    return $ok;
}

function getBreakMinutesUsed(int $attendanceDayId, string $breakState): int {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return 0;
    $breakState = trim($breakState);
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COALESCE(SUM(minutes),0) AS m FROM hr_attendance_states WHERE attendance_day_id = ? AND state = ? AND minutes IS NOT NULL");
    if (!$stmt) return 0;
    $stmt->bind_param('is', $attendanceDayId, $breakState);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['m' => 0];
    $stmt->close();
    return (int)($row['m'] ?? 0);
}

function recomputeAttendanceDay(int $attendanceDayId): bool {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return false;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT user_id, work_date, punch_in, punch_out, shift_start_time, grace_minutes, status FROM hr_attendance_days WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $attendanceDayId);
    $stmt->execute();
    $day = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$day) return false;

    $userId = (int)($day['user_id'] ?? 0);
    $workDate = (string)($day['work_date'] ?? '');
    if ($userId > 0 && $workDate !== '') {
        setAttendanceDayShiftMeta($attendanceDayId, $userId, $workDate);
        $stmt = $conn->prepare("SELECT punch_in, punch_out, shift_start_time, grace_minutes FROM hr_attendance_days WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $attendanceDayId);
            $stmt->execute();
            $day2 = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($day2) $day = array_merge($day, $day2);
        }
    }

    $pIn = (string)($day['punch_in'] ?? '');
    $pOut = (string)($day['punch_out'] ?? '');
    $currentStatus = (string)($day['status'] ?? '');
    $lateMinutes = 0;
    $shiftStart = (string)($day['shift_start_time'] ?? '');
    $policy = getAttendancePolicySettings();
    $grace = isset($day['grace_minutes']) && $day['grace_minutes'] !== null ? (int)$day['grace_minutes'] : (int)($policy['grace_minutes'] ?? 10);
    if ($grace < 0) $grace = 0;
    if ($grace > 120) $grace = 120;
    if ($pIn !== '' && $shiftStart !== '' && $workDate !== '') {
        $inTs = strtotime($pIn);
        $shiftTs = strtotime($workDate . ' ' . $shiftStart);
        if ($inTs !== false && $shiftTs !== false) {
            $allowed = $shiftTs + (max(0, $grace) * 60);
            if ($inTs > $allowed) $lateMinutes = (int)floor(($inTs - $allowed) / 60);
        }
    }
    if ($pIn === '') {
        $isLeave = in_array($currentStatus, ['Paid Leave','Unpaid Leave','paid_leave','unpaid_leave'], true);
        $keepStatus = $isLeave ? ($currentStatus === 'paid_leave' ? 'Paid Leave' : ($currentStatus === 'unpaid_leave' ? 'Unpaid Leave' : $currentStatus)) : 'Absent';
        $stmt = $conn->prepare("UPDATE hr_attendance_days SET current_state = 'Off', break_minutes = 0, working_minutes = 0, late_minutes = 0, status = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('si', $keepStatus, $attendanceDayId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $breakMinutes = 0;
    foreach (['Break1' => 15, 'Break2' => 15, 'Lunch' => 45] as $k => $limit) {
        $m = getBreakMinutesUsed($attendanceDayId, $k);
        $breakMinutes += min((int)$m, (int)$limit);
    }

    $workMinutes = 0;
    if ($pOut !== '') {
        $inTs = strtotime($pIn);
        $outTs = strtotime($pOut);
        if ($inTs !== false && $outTs !== false) {
            if ($outTs <= $inTs) $outTs += 86400;
        }
        if ($inTs !== false && $outTs !== false && $outTs > $inTs) {
            $workMinutes = (int)floor(($outTs - $inTs) / 60) - $breakMinutes;
            if ($workMinutes < 0) $workMinutes = 0;
        }
    }

    $status = 'In Progress';
    if ($pOut !== '') {
        $status = ($workMinutes >= (7 * 60)) ? 'Full Day' : 'Half Day';
        if ($workMinutes <= 0) $status = 'Half Day';
    }
    $isLeave = in_array($currentStatus, ['Paid Leave','Unpaid Leave','paid_leave','unpaid_leave'], true);
    $forceCloseAbsent = false;
    if (!$isLeave && $lateMinutes > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
        $halfAt = (int)($policy['late_halfday_at'] ?? 3);
        $absentAt = (int)($policy['late_absent_at'] ?? 4);
        $yy = (int)substr($workDate, 0, 4);
        $mo = (int)substr($workDate, 5, 2);
        if ($yy > 0 && $mo > 0) {
            $monthStart = sprintf('%04d-%02d-01', $yy, $mo);
            $monthEnd = sprintf('%04d-%02d-%02d', $yy, $mo, cal_days_in_month(CAL_GREGORIAN, $mo, $yy));
            $prevLateCount = 0;
            $stmtL = $conn->prepare("
                SELECT COUNT(*) AS cnt
                FROM hr_attendance_days
                WHERE user_id = ?
                  AND work_date >= ?
                  AND work_date <= ?
                  AND id <> ?
                  AND COALESCE(late_minutes, 0) > 0
                  AND status NOT IN ('Paid Leave','Unpaid Leave','paid_leave','unpaid_leave')
            ");
            if ($stmtL) {
                $stmtL->bind_param('issi', $userId, $monthStart, $monthEnd, $attendanceDayId);
                $stmtL->execute();
                $rowL = $stmtL->get_result()->fetch_assoc() ?: ['cnt' => 0];
                $stmtL->close();
                $prevLateCount = (int)($rowL['cnt'] ?? 0);
            }
            $lateCount = $prevLateCount + 1;
            if ($lateCount >= $absentAt) {
                $status = 'Absent';
                $breakMinutes = 0;
                $workMinutes = 0;
                $forceCloseAbsent = true;
            } elseif ($lateCount >= $halfAt) {
                if ($status !== 'Absent') $status = 'Half Day';
            }
        }
    }

    $stmt = $conn->prepare("UPDATE hr_attendance_days SET break_minutes = ?, working_minutes = ?, late_minutes = ?, status = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('iiisi', $breakMinutes, $workMinutes, $lateMinutes, $status, $attendanceDayId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok && $forceCloseAbsent) {
        $stmt2 = $conn->prepare("UPDATE hr_attendance_days SET punch_out = COALESCE(punch_out, punch_in), current_state = 'Off', updated_at = NOW() WHERE id = ?");
        if ($stmt2) {
            $stmt2->bind_param('i', $attendanceDayId);
            $stmt2->execute();
            $stmt2->close();
        }
        $open = getOpenAttendanceState($attendanceDayId);
        if ($open) {
            $at = hrNow();
            $start = (string)($open['start_at'] ?? '');
            $state = (string)($open['state'] ?? '');
            $startTs = strtotime($start);
            $endTs = strtotime($at);
            if ($startTs === false || $endTs === false || $endTs <= $startTs) $endTs = $startTs;
            $minutes = (int)floor(($endTs - $startTs) / 60);
            if (in_array($state, ['Break1','Break2','Lunch'], true)) {
                $limits = ['Break1' => 15, 'Break2' => 15, 'Lunch' => 45];
                $used = getBreakMinutesUsed($attendanceDayId, $state);
                $remaining = max(0, (int)$limits[$state] - $used);
                if ($minutes > $remaining) {
                    $minutes = $remaining;
                    $endTs = $startTs + ($minutes * 60);
                    $at = date('Y-m-d H:i:s', $endTs);
                }
            }
            $stmtS = $conn->prepare("UPDATE hr_attendance_states SET end_at = ?, minutes = ? WHERE id = ?");
            if ($stmtS) {
                $sid = (int)($open['id'] ?? 0);
                $stmtS->bind_param('sii', $at, $minutes, $sid);
                $stmtS->execute();
                $stmtS->close();
            }
        }
    }
    return $ok;
}

function hrRepairOvernightOrphanPunchOut(string $workDate): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return;
    ensureDatabaseSchema();
    $nextDate = date('Y-m-d', strtotime($workDate . ' +1 day'));
    $conn = getDbConnection();
    $pairs = [];
    $stmt = $conn->prepare("
        SELECT d1.id AS d1_id, d2.id AS d2_id
        FROM hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = ?
        WHERE d1.work_date = ?
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $nextDate, $workDate);
        $stmt->execute();
        $pairs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    if (empty($pairs)) return;

    $conn->query("
        UPDATE hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = '{$nextDate}'
        SET
            d1.punch_out = d2.punch_out,
            d1.current_state = 'Off',
            d2.punch_out = NULL,
            d2.current_state = 'Off',
            d2.break_minutes = 0,
            d2.working_minutes = 0,
            d2.late_minutes = 0,
            d2.status = 'Absent',
            d2.updated_at = NOW()
        WHERE d1.work_date = '{$workDate}'
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");

    $seen = [];
    foreach ($pairs as $p) {
        $d1 = (int)($p['d1_id'] ?? 0);
        $d2 = (int)($p['d2_id'] ?? 0);
        if ($d1 > 0 && !isset($seen[$d1])) { $seen[$d1] = true; recomputeAttendanceDay($d1); }
        if ($d2 > 0 && !isset($seen[$d2])) { $seen[$d2] = true; recomputeAttendanceDay($d2); }
    }
}

function getAttendanceDashboard(string $workDate): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return [];
    ensureDatabaseSchema();
    hrRepairOvernightOrphanPunchOut($workDate);
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT u.id AS user_id, u.full_name, u.job_title, u.role, d.punch_in, d.punch_out, d.current_state, d.break_minutes, d.working_minutes, d.late_minutes, d.shift_start_time, d.grace_minutes, d.status
        FROM users u
        LEFT JOIN hr_attendance_days d ON d.user_id = u.id AND d.work_date = ?
        WHERE (u.client_id IS NULL OR u.client_id = 0) AND (u.vendor_id IS NULL OR u.vendor_id = 0)
        ORDER BY u.full_name
    ");
    if (!$stmt) return [];
    $stmt->bind_param('s', $workDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getAttendanceMonthSummary(int $userId, int $year, int $month): array {
    $userId = (int)$userId;
    if ($userId <= 0) return ['present_days' => 0, 'half_days' => 0, 'absent_days' => 0, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'working_days' => 0, 'late_days' => 0, 'late_minutes' => 0];
    $workingDays = getWorkingDays($year, $month);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    hrRepairOvernightOrphanPunchOutForUserRange($userId, $start, $end);
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN status IN ('Full Day','Present','present') THEN 1 ELSE 0 END) AS full_days,
            SUM(CASE WHEN status IN ('Half Day','half_day') THEN 1 ELSE 0 END) AS half_days,
            SUM(CASE WHEN status IN ('Paid Leave','paid_leave') THEN 1 ELSE 0 END) AS paid_leave_days,
            SUM(CASE WHEN status IN ('Unpaid Leave','unpaid_leave') THEN 1 ELSE 0 END) AS unpaid_leave_days,
            SUM(CASE WHEN COALESCE(late_minutes, 0) > 0 THEN 1 ELSE 0 END) AS late_days,
            SUM(COALESCE(late_minutes, 0)) AS late_minutes
        FROM hr_attendance_days
        WHERE user_id = ? AND work_date >= ? AND work_date <= ?
    ");
    if (!$stmt) return ['present_days' => 0, 'half_days' => 0, 'absent_days' => $workingDays, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'working_days' => $workingDays, 'late_days' => 0, 'late_minutes' => 0];
    $stmt->bind_param('iss', $userId, $start, $end);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $presentDays = (int)($r['full_days'] ?? 0);
    $halfDays = (int)($r['half_days'] ?? 0);
    $paidLeaveDays = (int)($r['paid_leave_days'] ?? 0);
    $unpaidLeaveDays = (int)($r['unpaid_leave_days'] ?? 0);
    $lateDays = (int)($r['late_days'] ?? 0);
    $lateMinutes = (int)($r['late_minutes'] ?? 0);
    $absent = max(0, $workingDays - ($presentDays + $halfDays + $paidLeaveDays + $unpaidLeaveDays));
    return [
        'present_days' => $presentDays,
        'half_days' => $halfDays,
        'absent_days' => $absent,
        'paid_leave_days' => $paidLeaveDays,
        'unpaid_leave_days' => $unpaidLeaveDays,
        'working_days' => $workingDays,
        'late_days' => $lateDays,
        'late_minutes' => $lateMinutes,
    ];
}

function hrRepairOvernightOrphanPunchOutForUserRange(int $userId, string $startDate, string $endDate): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $pairs = [];
    $stmt = $conn->prepare("
        SELECT d1.id AS d1_id, d2.id AS d2_id, d1.work_date AS d1_date
        FROM hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = DATE_ADD(d1.work_date, INTERVAL 1 DAY)
        WHERE d1.user_id = ?
          AND d1.work_date >= ?
          AND d1.work_date <= ?
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $startDate, $endDate);
        $stmt->execute();
        $pairs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    if (empty($pairs)) return;

    $stmt2 = $conn->prepare("
        UPDATE hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = DATE_ADD(d1.work_date, INTERVAL 1 DAY)
        SET
            d1.punch_out = d2.punch_out,
            d1.current_state = 'Off',
            d2.punch_out = NULL,
            d2.current_state = 'Off',
            d2.break_minutes = 0,
            d2.working_minutes = 0,
            d2.late_minutes = 0,
            d2.status = 'Absent',
            d2.updated_at = NOW()
        WHERE d1.user_id = ?
          AND d1.work_date >= ?
          AND d1.work_date <= ?
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");
    if ($stmt2) {
        $stmt2->bind_param('iss', $userId, $startDate, $endDate);
        $stmt2->execute();
        $stmt2->close();
    }

    $seen = [];
    foreach ($pairs as $p) {
        $d1 = (int)($p['d1_id'] ?? 0);
        $d2 = (int)($p['d2_id'] ?? 0);
        if ($d1 > 0 && !isset($seen[$d1])) { $seen[$d1] = true; recomputeAttendanceDay($d1); }
        if ($d2 > 0 && !isset($seen[$d2])) { $seen[$d2] = true; recomputeAttendanceDay($d2); }
    }
}

function getAttendanceMonthlyReport(int $year, int $month): array {
    $year = (int)$year;
    $month = (int)$month;
    if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) return [];
    $users = getInternalPayrollUsers();
    $rows = [];
    foreach ($users as $u) {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) continue;
        $sum = getAttendanceMonthSummary($uid, $year, $month);
        $workingDays = (int)($sum['working_days'] ?? 0);
        $present = (int)($sum['present_days'] ?? 0);
        $half = (int)($sum['half_days'] ?? 0);
        $absent = (int)($sum['absent_days'] ?? 0);
        $paidLeave = (int)($sum['paid_leave_days'] ?? 0);
        $unpaidLeave = (int)($sum['unpaid_leave_days'] ?? 0);
        $lateDays = (int)($sum['late_days'] ?? 0);
        $lateMinutes = (int)($sum['late_minutes'] ?? 0);
        $paidDays = $present + $paidLeave + ($half * 0.5);
        $pct = $workingDays > 0 ? round((($paidDays / $workingDays) * 100.0), 1) : 0.0;
        $rows[] = [
            'user_id' => $uid,
            'name' => (string)($u['full_name'] ?? ''),
            'job_title' => (string)($u['job_title'] ?? ''),
            'department' => (string)($u['department'] ?? ''),
            'working_days' => $workingDays,
            'present_days' => $present,
            'half_days' => $half,
            'absent_days' => $absent,
            'paid_leave_days' => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave,
            'late_days' => $lateDays,
            'late_minutes' => $lateMinutes,
            'paid_days' => $paidDays,
            'attendance_percent' => $pct,
        ];
    }
    return $rows;
}

function getSalarySettingForMonth(int $userId, int $year, int $month): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    ensureDatabaseSchema();
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_salary_settings WHERE user_id = ? AND effective_date <= ? ORDER BY effective_date DESC, id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function normalizeSalaryStructureType(?string $t): string {
    $t = trim((string)$t);
    $map = [
        'Standard (Balanced)' => 'Standard',
        'Standard' => 'Standard',
        'High Take-Home' => 'High Take-Home',
        'High Take Home' => 'High Take-Home',
        'Compliance Heavy' => 'Compliance Heavy',
        'Compliance-Heavy' => 'Compliance Heavy',
    ];
    return $map[$t] ?? 'Standard';
}

function computeMonthlyTdsEstimate(float $monthlySalary, float $pfMonthly, float $ptMonthly): float {
    $monthlySalary = max(0.0, $monthlySalary);
    $annual = $monthlySalary * 12.0;
    $stdDed = 50000.0;
    $taxable = max(0.0, $annual - $stdDed - ($pfMonthly * 12.0) - ($ptMonthly * 12.0));
    $tax = 0.0;
    $slabs = [
        [0.0, 300000.0, 0.0],
        [300000.0, 600000.0, 0.05],
        [600000.0, 900000.0, 0.10],
        [900000.0, 1200000.0, 0.15],
        [1200000.0, 1500000.0, 0.20],
        [1500000.0, 1e18, 0.30],
    ];
    foreach ($slabs as [$from, $to, $rate]) {
        if ($taxable <= $from) continue;
        $amt = min($taxable, $to) - $from;
        if ($amt > 0) $tax += $amt * $rate;
    }
    $tax *= 1.04;
    return round($tax / 12.0, 2);
}

function computeSalaryStructure(float $monthlySalary, string $type): array {
    $monthlySalary = round(max(0.0, $monthlySalary), 2);
    $type = normalizeSalaryStructureType($type);
    $convey = 1600.0;
    $medical = 1250.0;

    if ($type === 'High Take-Home') {
        $basic = round($monthlySalary * 0.36, 2);
        $hra = round($basic * 0.40, 2);
    } elseif ($type === 'Compliance Heavy') {
        $basic = round($monthlySalary * 0.50, 2);
        $hra = round($basic * 0.50, 2);
    } else {
        $basic = round($monthlySalary * 0.44, 2);
        $hra = round($basic * 0.50, 2);
    }

    $special = round($monthlySalary - ($basic + $hra + $convey + $medical), 2);
    if ($special < 0) {
        $special = 0.0;
        $convey = 0.0;
        $medical = 0.0;
        $hra = round(min($hra, max(0.0, $monthlySalary - $basic)), 2);
    }

    $other = 0.0;
    $pf = round($basic * 0.12, 2);
    $pt = 200.0;
    $tds = computeMonthlyTdsEstimate($monthlySalary, $pf, $pt);

    return [
        'structure_type' => $type,
        'total_salary' => $monthlySalary,
        'basic' => $basic,
        'hra' => $hra,
        'conveyance' => $convey,
        'medical' => $medical,
        'special_allowance' => $special,
        'other_allowance' => $other,
        'pf' => $pf,
        'professional_tax' => $pt,
        'tds' => $tds,
    ];
}

function getSalaryStructureForMonth(int $userId, int $year, int $month): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    ensureDatabaseSchema();
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_salary_structures WHERE user_id = ? AND effective_date <= ? ORDER BY effective_date DESC, id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getBonusesForMonth(int $userId, int $year, int $month): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_bonuses WHERE user_id = ? AND year = ? AND month = ? ORDER BY id DESC");
    if (!$stmt) return [];
    $stmt->bind_param('iii', $userId, $year, $month);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getActiveLoans(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_loans WHERE user_id = ? AND active = 1 ORDER BY start_date ASC, id ASC");
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLoanDeductionForMonth(int $loanId, int $year, int $month): ?array {
    $loanId = (int)$loanId;
    if ($loanId <= 0) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_loan_deductions WHERE loan_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $loanId, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function finalizeLoanDeductionsForMonth(int $userId, int $year, int $month): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    ensureDatabaseSchema();
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $conn = getDbConnection();

    foreach (getActiveLoans($userId) as $loan) {
        $loanId = (int)($loan['id'] ?? 0);
        if ($loanId <= 0) continue;
        $startDate = (string)($loan['start_date'] ?? '');
        if ($startDate !== '' && $startDate > $end) continue;
        $existing = getLoanDeductionForMonth($loanId, $year, $month);
        if ($existing) continue;
        $rem = (float)($loan['remaining_amount'] ?? 0);
        $emi = (float)($loan['emi_amount'] ?? 0);
        if ($rem <= 0 || $emi <= 0) continue;
        $amt = min($emi, $rem);
        $stmt = $conn->prepare("INSERT INTO hr_loan_deductions (loan_id, user_id, year, month, amount) VALUES (?,?,?,?,?)");
        if (!$stmt) continue;
        $stmt->bind_param('iiiid', $loanId, $userId, $year, $month, $amt);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) continue;
        $stmt2 = $conn->prepare("UPDATE hr_loans SET remaining_amount = GREATEST(0, remaining_amount - ?) WHERE id = ?");
        if ($stmt2) {
            $stmt2->bind_param('di', $amt, $loanId);
            $stmt2->execute();
            $stmt2->close();
        }
        $stmt3 = $conn->prepare("UPDATE hr_loans SET active = 0 WHERE id = ? AND remaining_amount <= 0");
        if ($stmt3) {
            $stmt3->bind_param('i', $loanId);
            $stmt3->execute();
            $stmt3->close();
        }
    }
}

function hrIsPayrollLocked(int $year, int $month): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT 1 FROM hr_payroll_month_locks WHERE year = ? AND month = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $ok = (bool)($stmt->get_result()->fetch_row());
    $stmt->close();
    return $ok;
}

function hrLockPayrollMonth(int $year, int $month, int $lockedBy): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO hr_payroll_month_locks (year, month, locked_by, locked_at) VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), locked_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iii', $year, $month, $lockedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function hrUnlockPayrollMonth(int $year, int $month): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM hr_payroll_month_locks WHERE year = ? AND month = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function computePayrollForUserMonth(int $userId, int $year, int $month): ?array {
    $structure = getSalaryStructureForMonth($userId, $year, $month);
    $salaryLegacy = $structure ? null : getSalarySettingForMonth($userId, $year, $month);
    if (!$structure && !$salaryLegacy) return null;
    $attendance = getAttendanceMonthSummary($userId, $year, $month);
    $workingDays = (int)($attendance['working_days'] ?? getWorkingDays($year, $month));
    $present = (int)($attendance['present_days'] ?? 0);
    $half = (int)($attendance['half_days'] ?? 0);
    $paidLeave = (int)($attendance['paid_leave_days'] ?? 0);
    $unpaidLeave = (int)($attendance['unpaid_leave_days'] ?? 0);
    $paidDays = $present + $paidLeave + ($half * 0.5);
    $attendanceFactor = $workingDays > 0 ? ($paidDays / $workingDays) : 0;

    if ($structure) {
        $basic = (float)($structure['basic'] ?? 0);
        $hra = (float)($structure['hra'] ?? 0);
        $convey = (float)($structure['conveyance'] ?? 0);
        $medical = (float)($structure['medical'] ?? 0);
        $special = (float)($structure['special_allowance'] ?? 0);
        $otherAllow = (float)($structure['other_allowance'] ?? 0);
        $monthlySalary = (float)($structure['total_salary'] ?? ($basic + $hra + $convey + $medical + $special + $otherAllow));
        $pfMonthly = (float)($structure['pf'] ?? round($basic * 0.12, 2));
        $ptMonthly = (float)($structure['professional_tax'] ?? 200);
        $tdsMonthly = (float)($structure['tds'] ?? 0);
        $structureType = (string)($structure['structure_type'] ?? 'Standard');
        $locked = (int)($structure['locked'] ?? 0) === 1;
    } else {
        $basic = (float)($salaryLegacy['basic_salary'] ?? 0);
        $hra = 0.0;
        $convey = 0.0;
        $medical = 0.0;
        $special = 0.0;
        $otherAllow = (float)($salaryLegacy['allowances'] ?? 0);
        $monthlySalary = $basic + $otherAllow;
        $pfMonthly = (float)($salaryLegacy['pf'] ?? 0);
        $ptMonthly = 0.0;
        $tdsMonthly = (float)($salaryLegacy['tax'] ?? 0);
        $structureType = 'Legacy';
        $locked = false;
    }

    $salaryProrated = $monthlySalary * $attendanceFactor;
    $basicPr = $basic * $attendanceFactor;
    $hraPr = $hra * $attendanceFactor;
    $conveyPr = $convey * $attendanceFactor;
    $medicalPr = $medical * $attendanceFactor;
    $specialPr = $special * $attendanceFactor;
    $otherAllowPr = $otherAllow * $attendanceFactor;

    $prod = getAgentMonthlyStats($userId, $year, $month);
    $incentives = (float)($prod['incentives']['total'] ?? 0);

    $bonusesRows = getBonusesForMonth($userId, $year, $month);
    $bonus = 0.0;
    foreach ($bonusesRows as $b) $bonus += (float)($b['amount'] ?? 0);

    $loanDed = 0.0;
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    foreach (getActiveLoans($userId) as $loan) {
        $startDate = (string)($loan['start_date'] ?? '');
        if ($startDate !== '' && $startDate > $end) continue;
        $rem = (float)($loan['remaining_amount'] ?? 0);
        $emi = (float)($loan['emi_amount'] ?? 0);
        if ($rem <= 0 || $emi <= 0) continue;
        $loanId = (int)($loan['id'] ?? 0);
        $existing = $loanId > 0 ? getLoanDeductionForMonth($loanId, $year, $month) : null;
        if ($existing) {
            $loanDed += (float)($existing['amount'] ?? 0);
        } else {
            $loanDed += min($emi, $rem);
        }
    }

    $pfPr = $pfMonthly * $attendanceFactor;
    $ptPr = $paidDays > 0 ? $ptMonthly : 0.0;
    $tdsPr = $tdsMonthly * $attendanceFactor;
    $otherDed = 0.0;
    $gross = $salaryProrated + $incentives + $bonus;
    $deductions = $pfPr + $ptPr + $tdsPr + $loanDed + $otherDed;
    $net = $gross - $deductions;

    return [
        'attendance' => [
            'working_days' => $workingDays,
            'present_days' => $present,
            'half_days' => $half,
            'absent_days' => (int)($attendance['absent_days'] ?? 0),
            'paid_leave_days' => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave,
            'paid_days' => $paidDays,
            'attendance_factor' => round($attendanceFactor, 4),
        ],
        'salary_structure' => [
            'type' => $structureType,
            'locked' => $locked,
            'monthly_salary' => round($monthlySalary, 2),
            'components' => [
                'basic' => round($basic, 2),
                'hra' => round($hra, 2),
                'conveyance' => round($convey, 2),
                'medical' => round($medical, 2),
                'special_allowance' => round($special, 2),
                'other_allowance' => round($otherAllow, 2),
            ],
            'deductions' => [
                'pf' => round($pfMonthly, 2),
                'professional_tax' => round($ptMonthly, 2),
                'tds' => round($tdsMonthly, 2),
            ],
        ],
        'earnings' => [
            'monthly_salary' => round($monthlySalary, 2),
            'salary_prorated' => round($salaryProrated, 2),
            'basic' => round($basicPr, 2),
            'hra' => round($hraPr, 2),
            'conveyance' => round($conveyPr, 2),
            'medical' => round($medicalPr, 2),
            'special_allowance' => round($specialPr, 2),
            'other_allowance' => round($otherAllowPr, 2),
            'incentives' => round($incentives, 2),
            'bonus' => round($bonus, 2),
            'gross' => round($gross, 2),
        ],
        'deductions' => [
            'pf' => round($pfPr, 2),
            'professional_tax' => round($ptPr, 2),
            'tds' => round($tdsPr, 2),
            'other' => round($otherDed, 2),
            'loan_emi' => round($loanDed, 2),
            'total' => round($deductions, 2),
        ],
        'net_salary' => round($net, 2),
    ];
}

function getPayrollSummaryRows(int $year, int $month): array {
    $year = (int)$year;
    $month = (int)$month;
    if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) return [];
    ensureDatabaseSchema();
    $users = getInternalPayrollUsers();
    $rows = [];
    foreach ($users as $u) {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) continue;
        $p = getPayslip($uid, $year, $month);
        $data = $p ? json_decode((string)($p['salary_data'] ?? ''), true) : null;
        if (!is_array($data)) {
            $calc = computePayrollForUserMonth($uid, $year, $month);
            if (!$calc) continue;
            $data = [
                'user' => [
                    'id' => $uid,
                    'name' => (string)($u['full_name'] ?? ''),
                    'role' => (string)($u['role'] ?? ''),
                    'department' => (string)($u['department'] ?? ''),
                ],
                'attendance' => $calc['attendance'] ?? [],
                'earnings' => $calc['earnings'] ?? [],
                'deductions' => $calc['deductions'] ?? [],
                'net_salary' => $calc['net_salary'] ?? 0,
            ];
        }
        $uu = is_array($data['user'] ?? null) ? $data['user'] : [];
        $att = is_array($data['attendance'] ?? null) ? $data['attendance'] : [];
        $earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
        $ded = is_array($data['deductions'] ?? null) ? $data['deductions'] : [];
        $allowances = (float)($earn['allowances'] ?? 0);
        if ($allowances === 0.0) {
            $allowances = (float)($earn['hra'] ?? 0) + (float)($earn['conveyance'] ?? 0) + (float)($earn['medical'] ?? 0) + (float)($earn['special_allowance'] ?? 0) + (float)($earn['other_allowance'] ?? 0);
        }
        $basePr = (float)($earn['base_prorated'] ?? 0);
        if ($basePr === 0.0) $basePr = (float)($earn['salary_prorated'] ?? 0);
        $rows[] = [
            'user_id' => (int)($uu['id'] ?? $uid),
            'name' => (string)($uu['name'] ?? ($u['full_name'] ?? '')),
            'role' => (string)($uu['role'] ?? ($u['role'] ?? '')),
            'department' => (string)($uu['department'] ?? ($u['department'] ?? '')),
            'working_days' => (float)($att['working_days'] ?? 0),
            'present_days' => (float)($att['present_days'] ?? 0),
            'half_days' => (float)($att['half_days'] ?? 0),
            'absent_days' => (float)($att['absent_days'] ?? 0),
            'paid_leave_days' => (float)($att['paid_leave_days'] ?? 0),
            'unpaid_leave_days' => (float)($att['unpaid_leave_days'] ?? 0),
            'paid_days' => (float)($att['paid_days'] ?? 0),
            'basic' => (float)($earn['basic'] ?? 0),
            'allowances' => $allowances,
            'base_prorated' => $basePr,
            'incentives' => (float)($earn['incentives'] ?? 0),
            'bonus' => (float)($earn['bonus'] ?? 0),
            'gross' => (float)($earn['gross'] ?? 0),
            'pf' => (float)($ded['pf'] ?? 0),
            'professional_tax' => (float)($ded['professional_tax'] ?? 0),
            'tds' => (float)($ded['tds'] ?? ($ded['tax'] ?? 0)),
            'loan_emi' => (float)($ded['loan_emi'] ?? 0),
            'other' => (float)($ded['other'] ?? 0),
            'deductions_total' => (float)($ded['total'] ?? 0),
            'net_salary' => (float)($data['net_salary'] ?? 0),
        ];
    }
    return $rows;
}

function upsertPayslip(int $userId, int $year, int $month, array $data, int $actorUserId): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("
        INSERT INTO hr_payslips (user_id, year, month, salary_data, generated_at, generated_by)
        VALUES (?,?,?,?,NOW(),?)
        ON DUPLICATE KEY UPDATE salary_data = VALUES(salary_data), generated_at = NOW(), generated_by = VALUES(generated_by)
    ");
    if (!$stmt) return false;
    $stmt->bind_param('iiisi', $userId, $year, $month, $json, $actorUserId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getPayslip(int $userId, int $year, int $month): ?array {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_payslips WHERE user_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $userId, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Upsert monthly productivity target for an agent
 */
function setAgentMonthlyTarget($agentId, $year, $month, $dailyTarget, $assignedBy) {
    if (!productivityTargetsTableExists()) return false;
    $conn = getDbConnection();
    $workingDays = getWorkingDays($year, $month);
    $monthlyTarget = (int)$dailyTarget * (int)$workingDays;
    $minimumTarget = 0;

    $sql = "INSERT INTO productivity_targets (agent_id, year, month, working_days, daily_target, monthly_target, minimum_target, assigned_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE working_days = VALUES(working_days), daily_target = VALUES(daily_target), monthly_target = VALUES(monthly_target), minimum_target = VALUES(minimum_target), assigned_by = VALUES(assigned_by)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return false; }
    $stmt->bind_param('iiiiiiii', $agentId, $year, $month, $workingDays, $dailyTarget, $monthlyTarget, $minimumTarget, $assignedBy);
    return $stmt->execute();
}

/**
 * Get monthly target for an agent
 */
function getAgentMonthlyTarget($agentId, $year, $month) {
    if (!productivityTargetsTableExists()) return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM productivity_targets WHERE agent_id = ? AND year = ? AND month = ?");
    if (!$stmt) { return null; }
    $stmt->bind_param('iii', $agentId, $year, $month);
    if (!$stmt->execute()) { return null; }
    $res = $stmt->get_result();
    return $res->fetch_assoc();
}

/**
 * Get all targets for a month (joined with agent names)
 */
function getMonthlyTargets($year, $month) {
    if (!productivityTargetsTableExists()) return [];
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT pt.*, u.full_name AS agent_name FROM productivity_targets pt JOIN users u ON pt.agent_id = u.id WHERE pt.year = ? AND pt.month = ? ORDER BY u.full_name");
    if (!$stmt) { return []; }
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    }
    $stmt->close();
    return $rows;
}

/**
 * Count qualified leads for an agent on a specific date
 */
function countAgentLeadsByDate($agentId, $date) {
    $filters = [
        'agent_id' => (int)$agentId,
        'date_from' => $date,
        'date_to' => $date,
        'qa_status' => 'Qualified',
    ];
    return (int)getLeadsCount($filters);
}

/**
 * Count total generated leads (all QA statuses) for an agent on a specific date
 */
function countAgentLeadsByDateAll($agentId, $date) {
    $filters = [
        'agent_id' => (int)$agentId,
        'date_from' => $date,
        'date_to' => $date,
    ];
    return (int)getLeadsCount($filters);
}

function normalizeProductivityCampaignType(?string $campaignType): string {
    $t = strtolower(trim((string)$campaignType));
    if ($t === 'email marketing') return 'Email Marketing';
    if ($t === 'marketing qualified leads' || $t === 'mql' || $t === 'marketing qualified lead') return 'Marketing Qualified Leads';
    if ($t === 'bant') return 'BANT';
    if ($t === 'appointment generation') return 'Appointment Generation';
    return '';
}

function campaignTypeMqlFactor(string $normalizedCampaignType): float {
    if ($normalizedCampaignType === 'Marketing Qualified Leads') return 1.0;
    if ($normalizedCampaignType === 'BANT') return 2.5;
    if ($normalizedCampaignType === 'Appointment Generation') return 10.0;
    if ($normalizedCampaignType === 'Email Marketing') return (10.0 / 60.0);
    return 0.0;
}

function extraLeadBonusAmount(string $normalizedCampaignType): int {
    if ($normalizedCampaignType === 'Marketing Qualified Leads') return 100;
    if ($normalizedCampaignType === 'BANT') return 250;
    if ($normalizedCampaignType === 'Appointment Generation') return 1000;
    return 0;
}

function productivityLocksTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_locks' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    if (!$exists) {
        ensureDatabaseSchema();
        $rs2 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_locks' LIMIT 1");
        $exists = (bool)($rs2 && $rs2->fetch_assoc());
    }
    return $exists;
}

function productivitySnapshotsTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_snapshots' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    if (!$exists) {
        ensureDatabaseSchema();
        $rs2 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_snapshots' LIMIT 1");
        $exists = (bool)($rs2 && $rs2->fetch_assoc());
    }
    return $exists;
}

function productivityTargetsTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_targets' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    return $exists;
}

function isProductivityMonthLocked(int $year, int $month): bool {
    if (!productivityLocksTableExists()) return false;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT 1 FROM productivity_month_locks WHERE year = ? AND month = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $ok = (bool)($stmt->get_result()->fetch_row());
    $stmt->close();
    return $ok;
}

function getProductivitySnapshot(int $agentId, int $year, int $month): ?array {
    if (!productivitySnapshotsTableExists()) return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM productivity_month_snapshots WHERE agent_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $agentId, $year, $month);
    $stmt->execute();
    $snap = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$snap) return null;

    $stmt = $conn->prepare("SELECT * FROM productivity_day_snapshots WHERE agent_id = ? AND year = ? AND month = ? ORDER BY work_date ASC");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $agentId, $year, $month);
    $stmt->execute();
    $days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $daily = [];
    foreach ($days as $d) {
        $counts = json_decode((string)($d['counts_json'] ?? ''), true);
        $extraCounts = json_decode((string)($d['extra_counts_json'] ?? ''), true);
        $daily[] = [
            'date' => (string)($d['work_date'] ?? ''),
            'counts' => is_array($counts) ? $counts : [],
            'total_leads' => (int)($d['total_leads'] ?? 0),
            'achieved_mql' => (float)($d['achieved_mql'] ?? 0),
            'daily_percent' => (float)($d['daily_percent'] ?? 0),
            'met_daily_target' => (int)($d['met_daily_target'] ?? 0) === 1,
            'daily_incentive' => (int)($d['daily_incentive'] ?? 0),
            'base_incentive' => (int)($d['base_incentive'] ?? 0),
            'extra_incentive' => (int)($d['extra_incentive'] ?? 0),
            'extra_counts' => is_array($extraCounts) ? $extraCounts : [],
        ];
    }

    return [
        'target' => [
            'daily_target' => (int)($snap['daily_target_mql'] ?? 0),
            'monthly_target' => (int)($snap['monthly_target_mql'] ?? 0),
            'minimum_target' => null,
            'working_days' => (int)($snap['working_days'] ?? 0),
        ],
        'period' => [
            'year' => (int)$year,
            'month' => (int)$month,
            'start' => (string)($snap['period_start'] ?? ''),
            'end' => (string)($snap['period_end'] ?? ''),
            'working_days' => (int)($snap['working_days'] ?? 0),
            'days_elapsed' => (int)($snap['days_elapsed'] ?? 0),
        ],
        'stats' => [
            'total_leads' => (int)($snap['total_leads'] ?? 0),
            'qualified_total' => (float)($snap['total_mql'] ?? 0),
            'total_calls' => (float)($snap['total_mql'] ?? 0),
            'days_met_daily' => (int)($snap['days_met_daily'] ?? 0),
            'met_monthly' => (int)($snap['met_monthly'] ?? 0) === 1,
            'status' => ((int)($snap['met_monthly'] ?? 0) === 1) ? 'achieved' : 'in_progress',
            'total_mql' => (float)($snap['total_mql'] ?? 0),
            'overall_percent' => (float)($snap['overall_percent'] ?? 0),
            'locked' => true,
        ],
        'daily' => $daily,
        'incentives' => [
            'daily_total' => (int)($snap['daily_incentives'] ?? 0),
            'monthly_bonus' => (int)($snap['monthly_incentive'] ?? 0),
            'total' => (int)($snap['total_incentives'] ?? 0),
        ],
    ];
}

function saveProductivitySnapshot(int $agentId, int $year, int $month, array $stats, int $lockedBy): bool {
    if (!productivitySnapshotsTableExists()) return false;
    $conn = getDbConnection();
    $target = is_array($stats['target'] ?? null) ? $stats['target'] : [];
    $period = is_array($stats['period'] ?? null) ? $stats['period'] : [];
    $s = is_array($stats['stats'] ?? null) ? $stats['stats'] : [];
    $inc = is_array($stats['incentives'] ?? null) ? $stats['incentives'] : [];

    $stmt = $conn->prepare("
        INSERT INTO productivity_month_snapshots (
            agent_id, year, month, daily_target_mql, monthly_target_mql, working_days, days_elapsed,
            period_start, period_end, total_leads, total_mql, overall_percent, days_met_daily, met_monthly,
            daily_incentives, monthly_incentive, total_incentives, locked_by, locked_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            daily_target_mql = VALUES(daily_target_mql),
            monthly_target_mql = VALUES(monthly_target_mql),
            working_days = VALUES(working_days),
            days_elapsed = VALUES(days_elapsed),
            period_start = VALUES(period_start),
            period_end = VALUES(period_end),
            total_leads = VALUES(total_leads),
            total_mql = VALUES(total_mql),
            overall_percent = VALUES(overall_percent),
            days_met_daily = VALUES(days_met_daily),
            met_monthly = VALUES(met_monthly),
            daily_incentives = VALUES(daily_incentives),
            monthly_incentive = VALUES(monthly_incentive),
            total_incentives = VALUES(total_incentives),
            locked_by = VALUES(locked_by),
            locked_at = NOW()
    ");
    if (!$stmt) return false;
    $dailyTarget = (int)($target['daily_target'] ?? 0);
    $monthlyTarget = (int)($target['monthly_target'] ?? 0);
    $workingDays = (int)($period['working_days'] ?? 0);
    $daysElapsed = (int)($period['days_elapsed'] ?? 0);
    $start = (string)($period['start'] ?? '');
    $end = (string)($period['end'] ?? '');
    $totalLeads = (int)($s['total_leads'] ?? 0);
    $totalMql = (float)($s['total_mql'] ?? ($s['qualified_total'] ?? 0));
    $overallPercent = (float)($s['overall_percent'] ?? 0);
    $daysMet = (int)($s['days_met_daily'] ?? 0);
    $metMonthly = !empty($s['met_monthly']) ? 1 : 0;
    $dailyInc = (int)($inc['daily_total'] ?? 0);
    $monthlyInc = (int)($inc['monthly_bonus'] ?? 0);
    $totalInc = (int)($inc['total'] ?? 0);
    $stmt->bind_param(
        'iiiiiiissiddiiiiii',
        $agentId, $year, $month,
        $dailyTarget, $monthlyTarget, $workingDays, $daysElapsed,
        $start, $end,
        $totalLeads, $totalMql, $overallPercent, $daysMet, $metMonthly,
        $dailyInc, $monthlyInc, $totalInc,
        $lockedBy
    );
    $ok = $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM productivity_day_snapshots WHERE agent_id = ? AND year = ? AND month = ?");
    if ($stmt) {
        $stmt->bind_param('iii', $agentId, $year, $month);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("
        INSERT INTO productivity_day_snapshots (
            agent_id, year, month, work_date, counts_json, total_leads, achieved_mql, daily_percent, met_daily_target,
            base_incentive, extra_incentive, extra_counts_json, daily_incentive
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) return $ok;
    foreach (($stats['daily'] ?? []) as $d) {
        $workDate = (string)($d['date'] ?? '');
        if ($workDate === '') continue;
        $countsJson = json_encode($d['counts'] ?? [], JSON_UNESCAPED_UNICODE);
        $extraCountsJson = json_encode($d['extra_counts'] ?? [], JSON_UNESCAPED_UNICODE);
        $totalLeadsDay = (int)($d['total_leads'] ?? 0);
        $achieved = (float)($d['achieved_mql'] ?? 0);
        $pct = (float)($d['daily_percent'] ?? 0);
        $met = !empty($d['met_daily_target']) ? 1 : 0;
        $base = (int)($d['base_incentive'] ?? 0);
        $extra = (int)($d['extra_incentive'] ?? 0);
        $dailyInc2 = (int)($d['daily_incentive'] ?? 0);
        $stmt->bind_param(
            'iiissiddiiisi',
            $agentId, $year, $month, $workDate,
            $countsJson, $totalLeadsDay, $achieved, $pct, $met,
            $base, $extra, $extraCountsJson, $dailyInc2
        );
        $stmt->execute();
    }
    $stmt->close();
    return $ok;
}

function lockProductivityMonth(int $year, int $month, int $lockedBy): bool {
    if (!productivityLocksTableExists() || !productivitySnapshotsTableExists() || !productivityTargetsTableExists()) return false;
    ensureUsFederalHolidaysForYear($year - 1);
    ensureUsFederalHolidaysForYear($year);
    ensureUsFederalHolidaysForYear($year + 1);
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO productivity_month_locks (year, month, locked_by, locked_at) VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), locked_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iii', $year, $month, $lockedBy);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return false;

    $targets = getMonthlyTargets($year, $month);
    foreach ($targets as $t) {
        $aid = (int)($t['agent_id'] ?? 0);
        if ($aid <= 0) continue;
        $stats = computeAgentMonthlyStatsLive($aid, $year, $month);
        saveProductivitySnapshot($aid, $year, $month, $stats, $lockedBy);
    }
    return true;
}

function unlockProductivityMonth(int $year, int $month): bool {
    if (!productivityLocksTableExists()) return true;
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM productivity_month_locks WHERE year = ? AND month = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function computeAgentMonthlyStatsLive(int $agentId, int $year, int $month): array {
    $target = getAgentMonthlyTarget($agentId, (int)$year, (int)$month);
    $dailyTarget = (int)($target['daily_target'] ?? 0);
    $workingDays = getWorkingDays($year, $month);
    $att = getAttendanceMonthSummary($agentId, (int)$year, (int)$month);
    $effectiveWorkingDays = max(0, (int)($att['present_days'] ?? 0) + (int)($att['half_days'] ?? 0));
    if ($effectiveWorkingDays <= 0) $effectiveWorkingDays = (int)$workingDays;
    if ($effectiveWorkingDays > (int)$workingDays) $effectiveWorkingDays = (int)$workingDays;
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
    $monthlyTarget = (int)($dailyTarget * (int)$effectiveWorkingDays);

    if (!$target || $dailyTarget <= 0) {
        return [
            'target' => [],
            'period' => [
                'year' => (int)$year,
                'month' => (int)$month,
                'start' => $startDate,
                'end' => $endDate,
                'working_days' => (int)$workingDays,
                'effective_working_days' => (int)$effectiveWorkingDays,
                'days_elapsed' => 0,
            ],
            'stats' => [
                'total_leads' => 0,
                'qualified_total' => 0,
                'total_calls' => 0,
                'days_met_daily' => 0,
                'met_monthly' => false,
                'status' => 'no_target',
                'total_mql' => 0,
                'overall_percent' => 0,
            ],
            'daily' => [],
            'incentives' => [
                'daily_total' => 0,
                'monthly_bonus' => 0,
                'total' => 0,
            ],
        ];
    }

    $holidaySet = [];
    foreach (getHolidaysForMonth((int)$year, (int)$month, 'US') as $h) {
        $d = (string)($h['holiday_date'] ?? '');
        if ($d !== '') $holidaySet[$d] = true;
    }

    ensureLeadsTrackingColumns();
    $conn = getDbConnection();
    $startTs = $startDate . ' 00:00:00';
    $endTs = $endDate . ' 23:59:59';
    $stmt = $conn->prepare("
        SELECT l.id, COALESCE(l.qa_updated_at, l.created_at) AS delivered_at, d.campaign_type
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE l.agent_id = ?
          AND l.client_delivery_status = 'Delivered'
          AND COALESCE(l.qa_updated_at, l.created_at) >= ?
          AND COALESCE(l.qa_updated_at, l.created_at) <= ?
        ORDER BY delivered_at ASC, l.id ASC
    ");
    $all = [];
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $startTs, $endTs);
        $stmt->execute();
        $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    $byDay = [];
    foreach ($all as $r) {
        $dt = (string)($r['delivered_at'] ?? '');
        if ($dt === '') continue;
        $day = date('Y-m-d', strtotime($dt));
        if (isset($holidaySet[$day])) continue;
        $ts = strtotime($day);
        $weekday = (int)date('N', $ts);
        if ($weekday > 5) continue;
        $ct = normalizeProductivityCampaignType((string)($r['campaign_type'] ?? ''));
        if ($ct === '') continue;
        if (!isset($byDay[$day])) $byDay[$day] = [];
        $byDay[$day][] = $ct;
    }

    $daily = [];
    $daysElapsed = 0;
    $daysMetDaily = 0;
    $totalLeadsMonth = 0;
    $totalMqlMonth = 0.0;
    $dailyIncentives = 0;

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $ts = mktime(0, 0, 0, (int)$month, $day, (int)$year);
        $weekday = (int)date('N', $ts);
        if ($weekday > 5) { continue; }
        $date = date('Y-m-d', $ts);
        if (isset($holidaySet[$date])) { continue; }
        $daysElapsed++;
        $leads = $byDay[$date] ?? [];

        $counts = [
            'Email Marketing' => 0,
            'Marketing Qualified Leads' => 0,
            'BANT' => 0,
            'Appointment Generation' => 0,
        ];
        $achievedMql = 0.0;
        foreach ($leads as $ct) {
            if (isset($counts[$ct])) $counts[$ct]++;
            $achievedMql += campaignTypeMqlFactor($ct);
        }

        $met = $dailyTarget > 0 && $achievedMql >= (float)$dailyTarget;
        if ($met) $daysMetDaily++;

        $extraCounts = [
            'Email Marketing' => 0,
            'Marketing Qualified Leads' => 0,
            'BANT' => 0,
            'Appointment Generation' => 0,
        ];
        $extraBonus = 0;
        if ($met) {
            $cum = 0.0;
            $reached = false;
            foreach ($leads as $ct) {
                $cum += campaignTypeMqlFactor($ct);
                if (!$reached && $cum >= (float)$dailyTarget) {
                    $reached = true;
                    continue;
                }
                if ($reached && isset($extraCounts[$ct])) $extraCounts[$ct]++;
            }
            foreach ($extraCounts as $ct => $c) {
                $extraBonus += (int)$c * extraLeadBonusAmount($ct);
            }
        }

        $baseBonus = $met ? (int)DAILY_INCENTIVE_AMOUNT : 0;
        $dailyIncentive = $baseBonus + $extraBonus;
        $dailyIncentives += $dailyIncentive;

        $dailyPercent = $dailyTarget > 0 ? round((($achievedMql / (float)$dailyTarget) * 100.0), 1) : 0.0;
        $totalLeadsDay = array_sum($counts);
        $totalLeadsMonth += $totalLeadsDay;
        $totalMqlMonth += $achievedMql;

        $daily[] = [
            'date' => $date,
            'counts' => $counts,
            'total_leads' => $totalLeadsDay,
            'achieved_mql' => $achievedMql,
            'daily_percent' => $dailyPercent,
            'met_daily_target' => $met,
            'daily_incentive' => $dailyIncentive,
            'base_incentive' => $baseBonus,
            'extra_incentive' => $extraBonus,
            'extra_counts' => $extraCounts,
        ];
    }

    $overallPercent = ($dailyTarget > 0 && $workingDays > 0) ? round((($totalMqlMonth / ((float)$dailyTarget * (float)$workingDays)) * 100.0), 1) : 0.0;
    if ($dailyTarget > 0 && $effectiveWorkingDays > 0) {
        $overallPercent = round((($totalMqlMonth / ((float)$dailyTarget * (float)$effectiveWorkingDays)) * 100.0), 1);
    }
    $metMonthly = $overallPercent >= 90.0;
    $monthlyBonusTotal = $metMonthly ? (int)MONTHLY_BONUS_AMOUNT : 0;
    $totalIncentives = $dailyIncentives + $monthlyBonusTotal;

    return [
        'target' => [
            'daily_target' => $dailyTarget,
            'monthly_target' => $monthlyTarget,
            'minimum_target' => null,
            'working_days' => (int)$workingDays,
            'effective_working_days' => (int)$effectiveWorkingDays,
        ],
        'period' => [
            'year' => (int)$year,
            'month' => (int)$month,
            'start' => $startDate,
            'end' => $endDate,
            'working_days' => (int)$workingDays,
            'effective_working_days' => (int)$effectiveWorkingDays,
            'days_elapsed' => $daysElapsed,
        ],
        'stats' => [
            'total_leads' => $totalLeadsMonth,
            'qualified_total' => $totalMqlMonth,
            'total_calls' => $totalMqlMonth,
            'days_met_daily' => $daysMetDaily,
            'met_monthly' => $metMonthly,
            'status' => $metMonthly ? 'achieved' : 'in_progress',
            'total_mql' => $totalMqlMonth,
            'overall_percent' => $overallPercent,
        ],
        'daily' => $daily,
        'incentives' => [
            'daily_total' => $dailyIncentives,
            'monthly_bonus' => $monthlyBonusTotal,
            'total' => $totalIncentives,
        ],
    ];
}

/**
 * Compute monthly stats and incentives for an agent
 */
function getAgentMonthlyStats($agentId, $year, $month) {
    $y = (int)$year;
    $m = (int)$month;
    if (isProductivityMonthLocked($y, $m)) {
        $snap = getProductivitySnapshot((int)$agentId, $y, $m);
        if ($snap) return $snap;
    }
    return computeAgentMonthlyStatsLive((int)$agentId, $y, $m);
}


/**
 * Ensure CSRF token exists in session for forms
 */
function ensureCsrfToken(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Session is handled by auth.php; this is a safeguard
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function ensureLeadsTrackingColumns(): void {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' LIMIT 1");
    if (!$rs || !$rs->fetch_assoc()) {
        ensureDatabaseSchema();
    }
    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };

    if (!$hasColumn('leads', 'created_by')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN created_by INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add created_by: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'updated_by')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN updated_by INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add updated_by: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status VARCHAR(30) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status_comment')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status_comment TEXT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status_comment: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status_updated_by')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status_updated_by INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status_updated_by: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status_updated_at')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status_updated_at DATETIME NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status_updated_at: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'client_id')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN client_id INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add client_id: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'vendor_id')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN vendor_id INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add vendor_id: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'lead_source')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN lead_source VARCHAR(20) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add lead_source: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'assigned_to_user')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN assigned_to_user INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add assigned_to_user: ' . ($conn->error ?? 'unknown'));
        }
    }

    if (!$hasColumn('leads', 'qa_client_comment')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN qa_client_comment TEXT NULL AFTER qa_comment")) {
            error_log('ensureLeadsTrackingColumns: failed to add qa_client_comment: ' . ($conn->error ?? 'unknown'));
        }
    }

    if (!$hasColumn('leads', 'client_delivery_status')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending'")) {
            error_log('ensureLeadsTrackingColumns: failed to add client_delivery_status: ' . ($conn->error ?? 'unknown'));
        } else {
            if ($hasColumn('leads', 'qa_status')) {
                @$conn->query("UPDATE leads SET client_delivery_status = 'Delivered' WHERE qa_status = 'Delivered'");
            }
        }
    }

    if (!$hasColumn('leads', 'company_domain')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN company_domain VARCHAR(255) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add company_domain: ' . ($conn->error ?? 'unknown'));
        } else {
            @$conn->query("UPDATE leads SET company_domain = LOWER(SUBSTRING_INDEX(email,'@',-1)) WHERE (company_domain IS NULL OR company_domain = '') AND email LIKE '%@%'");
        }
    }
}

/**
 * Get all campaigns
 * 
 * @return array Array of campaigns
 */
function getCampaigns() {
    $conn = getDbConnection();
    $result = $conn->query("SELECT * FROM campaigns WHERE active = 1 ORDER BY name");
    
    $campaigns = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $campaigns[] = $row;
        }
    }
    
    return $campaigns;
}

function getScopedVisibleCampaignIdsForUser(int $userId, string $role): ?array {
    $userId = (int)$userId;
    $role = (string)$role;
    if ($userId <= 0) return [];
    if (isAdmin() || isDirector()) return null;
    
    $visible = [];
    $isOps = false;
    
    if (str_starts_with($role, 'qa') || $role === 'qa') {
        $qaVisible = getQaVisibleCampaignIdsForUser($userId, $role);
        if ($qaVisible === null) return null;
        $visible = $qaVisible ?: [];
    } elseif (str_starts_with($role, 'operations') || in_array($role, ['agent','email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler','vendor_admin','vendor_user'], true)) {
        $visible = getOpsVisibleCampaignIdsForUser($userId, $role) ?: [];
        $isOps = true;
    } elseif (isSDR() || isSales()) {
        $visible = getUserAssignedCampaignIds($userId) ?: [];
    } else {
        $visible = getUserAssignedCampaignIds($userId) ?: [];
    }
    
    // Add team-based visibility (Union, not Intersection)
    $teamVisible = getTeamVisibleCampaignIdsForUser($userId) ?: [];
    if ($teamVisible === null) return null; // Should not happen for non-admin
    
    foreach ($teamVisible as $cid => $v) {
        $visible[(int)$cid] = true;
    }
    
    return empty($visible) ? [] : $visible;
}

/**
 * Get all campaigns (Basic info)
 */
function getCampaignsList(array $filters = [], int $perPage = 25, int $page = 1): array {
    $conn = getDbConnection();
    $where = ['c.active = 1'];
    $params = [];
    $types = '';

    if (!empty($filters['status'])) {
        $where[] = 'd.status = ?';
        $params[] = (string)$filters['status'];
        $types .= 's';
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'd.start_date >= ?';
        $params[] = (string)$filters['date_from'];
        $types .= 's';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'd.end_date <= ?';
        $params[] = (string)$filters['date_to'];
        $types .= 's';
    }
    if (!empty($filters['search'])) {
        $where[] = '(c.name LIKE ? OR d.code LIKE ?)';
        $q = '%'.(string)$filters['search'].'%';
        $params[] = $q;
        $params[] = $q;
        $types .= 'ss';
    }
    if (!empty($filters['campaign_ids']) && is_array($filters['campaign_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['campaign_ids']), fn($v) => $v > 0));
        if (empty($ids)) return ['campaigns' => [], 'total' => 0, 'totalPages' => 1];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "c.id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }

    $whereSql = implode(' AND ', $where);
    $offset = max(0, ($page - 1) * $perPage);

    $cntSql = "SELECT COUNT(*) FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE $whereSql";
    $cntStmt = $conn->prepare($cntSql);
    if ($types) $cntStmt->bind_param($types, ...$params);
    $cntStmt->execute();
    $total = (int)($cntStmt->get_result()->fetch_row()[0] ?? 0);
    $cntStmt->close();

    $sql = "
        SELECT c.id, c.name, d.code, d.status, d.start_date, d.end_date, d.total_leads
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE $whereSql
        ORDER BY d.created_at DESC, c.name ASC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $params2 = $params;
    $params2[] = $perPage;
    $params2[] = $offset;
    $stmt->bind_param($types.'ii', ...$params2);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    return ['campaigns' => $rows, 'total' => $total, 'totalPages' => max(1, (int)ceil($total / max(1, $perPage)))];
}

function getLiveCampaignsForLeadEntry(): array {
    $conn = getDbConnection();
    $sql = "SELECT c.id, c.name
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE d.status = 'Live'
            ORDER BY c.name";
    $rs = $conn->query($sql);
    $rows = [];
    if ($rs) {
        while ($r = $rs->fetch_assoc()) $rows[] = $r;
    }
    return $rows;
}

/**
 * Get all agents (users with agent role)
 * 
 * @return array Array of agents
 */
function getAgents() {
    $conn = getDbConnection();
    $result = $conn->query("SELECT id, username, full_name FROM users WHERE role = 'agent' AND is_active = 1 ORDER BY full_name");
    
    $agents = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $agents[] = $row;
        }
    }
    
    return $agents;
}

function getCampaignById(int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function normalizeFieldKey(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s, '_');
    if ($s === '') return '';
    if (preg_match('/^[0-9]/', $s)) $s = 'f_' . $s;
    if (strlen($s) > 64) $s = substr($s, 0, 64);
    return $s;
}

function getCampaignCode(int $campaignId): ?string {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT code FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $code = trim((string)($row['code'] ?? ''));
    return $code !== '' ? $code : null;
}

function getCampaignClientId(int $campaignId): ?int {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT client_id FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cid = (int)($row['client_id'] ?? 0);
    return $cid > 0 ? $cid : null;
}

function getCampaignLeadTableName(int $campaignId): ?string {
    $code = getCampaignCode($campaignId);
    if ($code === null) return null;
    $safe = preg_replace('/[^A-Za-z0-9]+/', '_', $code);
    $safe = preg_replace('/_+/', '_', $safe);
    $safe = trim($safe, '_');
    if ($safe === '') return null;
    $name = 'leads_' . $safe;
    if (strlen($name) > 64) $name = substr($name, 0, 64);
    return $name;
}

function campaignLeadTableExists(string $tableName): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
    $stmt->close();
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function campaignLeadColumnExists(string $tableName, string $columnName): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
    $stmt->close();
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function ensureCampaignLeadTable(int $campaignId): ?string {
    $table = getCampaignLeadTableName($campaignId);
    if ($table === null) return null;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return null;

    $conn = getDbConnection();
    if (!campaignLeadTableExists($table)) {
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id INT NOT NULL PRIMARY KEY,
            campaign_id INT NOT NULL,
            campaign_code VARCHAR(32) NOT NULL,
            client_id INT NULL,
            lead_code VARCHAR(40) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NULL,
            qa_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            qa_reviewed_by INT NULL,
            qa_reviewed_at DATETIME NULL,
            client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            lead_status VARCHAR(20) NOT NULL DEFAULT 'New',
            ip_address VARCHAR(45) NULL,
            recording_file_path VARCHAR(255) NULL,
            vendor_id INT NULL,
            lead_source VARCHAR(20) NULL,
            assigned_to_user INT NULL,
            agent_id INT NULL,
            agent_name VARCHAR(100) NULL,
            first_name VARCHAR(120) NULL,
            last_name VARCHAR(120) NULL,
            job_title VARCHAR(180) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(60) NULL,
            linkedin_profile VARCHAR(255) NULL,
            company_name VARCHAR(255) NULL,
            company_website VARCHAR(255) NULL,
            industry VARCHAR(180) NULL,
            employee_size VARCHAR(60) NULL,
            country VARCHAR(120) NULL,
            company_linkedin VARCHAR(255) NULL,
            lead_comment TEXT NULL,
            software_implementation_timeline VARCHAR(255) NULL,
            INDEX idx_campaign (campaign_id),
            INDEX idx_created_at (created_at),
            INDEX idx_qa (qa_status),
            INDEX idx_delivery (client_delivery_status),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($sql);
    }

    if (!campaignLeadColumnExists($table, 'client_delivery_status')) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER qa_reviewed_at");
        $conn->query("UPDATE `$table` SET client_delivery_status = 'Delivered' WHERE qa_status = 'Delivered'");
    }

    if (!campaignLeadColumnExists($table, 'vendor_id')) { $conn->query("ALTER TABLE `$table` ADD COLUMN vendor_id INT NULL"); }
    if (!campaignLeadColumnExists($table, 'lead_source')) { $conn->query("ALTER TABLE `$table` ADD COLUMN lead_source VARCHAR(20) NULL"); }
    if (!campaignLeadColumnExists($table, 'assigned_to_user')) { $conn->query("ALTER TABLE `$table` ADD COLUMN assigned_to_user INT NULL"); }
    if (!campaignLeadColumnExists($table, 'agent_id')) { $conn->query("ALTER TABLE `$table` ADD COLUMN agent_id INT NULL"); }
    if (!campaignLeadColumnExists($table, 'agent_name')) { $conn->query("ALTER TABLE `$table` ADD COLUMN agent_name VARCHAR(100) NULL"); }

    $form = getFormForCampaign($campaignId);
    $fields = (array)($form['schema']['fields'] ?? []);
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $key = normalizeFieldKey((string)($f['key'] ?? ''));
        if ($key === '') continue;
        if (in_array($key, [
            'id','campaign_id','campaign_code','client_id','lead_code',
            'created_by','created_at','updated_by','updated_at',
            'qa_status','qa_reviewed_by','qa_reviewed_at','client_delivery_status','lead_status','ip_address','recording_file_path',
            'vendor_id','lead_source','assigned_to_user','agent_id','agent_name',
            'first_name','last_name','job_title','email','phone','linkedin_profile',
            'company_name','company_website','industry','employee_size','country','company_linkedin'
        ], true)) {
            continue;
        }
        if (!campaignLeadColumnExists($table, $key)) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$key` TEXT NULL");
        }
    }

    return $table;
}

function upsertCampaignLeadRow(int $campaignId, int $leadId, array $rowData): bool {
    $table = ensureCampaignLeadTable($campaignId);
    if ($table === null) return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $conn = getDbConnection();

    $cols = [];
    $placeholders = [];
    $types = '';
    $vals = [];

    $rowData['id'] = $leadId;
    foreach ($rowData as $k => $v) {
        $col = normalizeFieldKey((string)$k);
        if ($col === '') continue;
        if (!campaignLeadColumnExists($table, $col)) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` TEXT NULL");
        }
        $cols[] = "`$col`";
        $placeholders[] = '?';
        if (is_int($v) || is_bool($v)) {
            $types .= 'i';
            $vals[] = (int)$v;
        } else {
            $types .= 's';
            $vals[] = ($v === null) ? null : (string)$v;
        }
    }
    if (empty($cols)) return false;

    $updates = [];
    foreach ($cols as $c) {
        if ($c === '`id`') continue;
        $updates[] = "$c = VALUES($c)";
    }
    $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")
            ON DUPLICATE KEY UPDATE " . implode(',', $updates);
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getCampaignLeadTableStats(int $campaignId): array {
    if ($campaignId <= 0) {
        return ['total' => 0, 'pending_qa' => 0, 'approved' => 0, 'rejected' => 0, 'client_delivered' => 0, 'last_submitted_at' => null];
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') OR qa_status IS NULL THEN 1 ELSE 0 END) AS pending_qa,
            SUM(CASE WHEN qa_status='Qualified' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN qa_status='Disqualified' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN client_delivery_status='Delivered' THEN 1 ELSE 0 END) AS client_delivered,
            MAX(created_at) AS last_submitted_at
        FROM leads
        WHERE campaign_id = ?
    ");
    if (!$stmt) {
        return ['total' => 0, 'pending_qa' => 0, 'approved' => 0, 'rejected' => 0, 'client_delivered' => 0, 'last_submitted_at' => null];
    }
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'total' => (int)($r['total'] ?? 0),
        'pending_qa' => (int)($r['pending_qa'] ?? 0),
        'approved' => (int)($r['approved'] ?? 0),
        'rejected' => (int)($r['rejected'] ?? 0),
        'client_delivered' => (int)($r['client_delivered'] ?? 0),
        'last_submitted_at' => $r['last_submitted_at'] ?? null,
    ];
}

function getCampaignLeadStatsBulk(array $campaignIds): array {
    $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
    if (empty($ids)) return [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("
        SELECT
            campaign_id,
            COUNT(*) AS total,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') OR qa_status IS NULL THEN 1 ELSE 0 END) AS pending_qa,
            SUM(CASE WHEN qa_status='Qualified' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN qa_status='Disqualified' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN client_delivery_status='Delivered' THEN 1 ELSE 0 END) AS client_delivered,
            MAX(created_at) AS last_submitted_at
        FROM leads
        WHERE campaign_id IN ($in)
        GROUP BY campaign_id
    ");
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $out = [];
    foreach ($rows as $r) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid <= 0) continue;
        $out[$cid] = [
            'total' => (int)($r['total'] ?? 0),
            'pending_qa' => (int)($r['pending_qa'] ?? 0),
            'approved' => (int)($r['approved'] ?? 0),
            'rejected' => (int)($r['rejected'] ?? 0),
            'client_delivered' => (int)($r['client_delivered'] ?? 0),
            'last_submitted_at' => $r['last_submitted_at'] ?? null,
        ];
    }
    return $out;
}

function getAssignedCampaignIdsForUser(int $userId): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $out = [];
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getQaVisibleCampaignIdsForUser(int $userId, string $role): ?array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $role = function_exists('normalizeRole') ? normalizeRole($role) : $role;
    if ($role === 'admin') return null;
    if (in_array($role, ['qa_director', 'qa_manager'], true)) return null;
    $conn = getDbConnection();
    $out = [];
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM qa_campaign_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    $cache[$userId] = $out;
    return $out;
}

function getQaAssignableUsers(string $currentRole): array {
    $conn = getDbConnection();
    $roles = [];
    if ($currentRole === 'admin') {
        $roles = ['qa_director', 'qa_manager', 'qa_agent', 'qa'];
    } elseif ($currentRole === 'qa_director') {
        $roles = ['qa_manager', 'qa_agent', 'qa'];
    } elseif ($currentRole === 'qa_manager') {
        $roles = ['qa_agent', 'qa'];
    } else {
        return [];
    }
    $in = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT id, full_name, role FROM users WHERE is_active = 1 AND role IN ($in) ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($roles));
    $stmt->bind_param($types, ...$roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getQaAssignedCampaignIdsForUser(int $userId): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM qa_campaign_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $out = [];
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getOpsVisibleCampaignIdsForUser(int $userId, string $role): ?array {
    if ($role === 'admin') return null;
    $conn = getDbConnection();
    
    if ($role === 'vendor_admin' || $role === 'vendor_user') {
        $stmt = $conn->prepare("SELECT vendor_id FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $vendorId = (int)($userRow['vendor_id'] ?? 0);
        if ($vendorId <= 0) return [];

        $allowedIds = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM vendor_campaign_map WHERE vendor_id = ? AND uploads_enabled = 1");
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) { $allowedIds[(int)$r['campaign_id']] = true; }
        $stmt->close();

        if ($role === 'vendor_user') {
            $userAssigned = [];
            $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) { 
                $cid = (int)$r['campaign_id'];
                if (isset($allowedIds[$cid])) $userAssigned[$cid] = true; 
            }
            $stmt->close();
            return $userAssigned;
        }
        return $allowedIds;
    }

    $opsRoles = [
        'director','manager_director','operations_director','operations_manager','operations_agent','agent',
        'email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler'
    ];
    if (!in_array($role, $opsRoles, true)) return null;
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM operations_campaign_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $out = [];
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getOpsAssignableUsers(string $currentRole): array {
    $conn = getDbConnection();
    $roles = [];
    if ($currentRole === 'admin' || $currentRole === 'director' || $currentRole === 'manager_director' || $currentRole === 'operations_director') {
        $roles = ['operations_manager', 'operations_agent', 'agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    } elseif ($currentRole === 'operations_manager') {
        $roles = ['operations_agent', 'agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    } else {
        return [];
    }
    $in = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT id, full_name, role, job_title FROM users WHERE is_active = 1 AND role IN ($in) ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($roles));
    $stmt->bind_param($types, ...$roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getOpsCampaignsForUser(int $userId, string $role, string $status = ''): array {
    $conn = getDbConnection();
    $role = (string)$role;

    $direct = getOpsVisibleCampaignIdsForUser($userId, $role);
    if ($direct === null) {
        $visible = null;
    } else {
        $visible = $direct;

        if (!in_array($role, ['vendor_admin','vendor_user'], true)) {
            $teamIds = getUserTeamIds((int)$userId);
            if (!empty($teamIds)) {
                $teamCampaigns = getTeamCampaignIds($teamIds);
                foreach ($teamCampaigns as $cid => $v) {
                    $visible[(int)$cid] = true;
                }
            }

            $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($r = $rs->fetch_assoc()) {
                    $cid = (int)($r['campaign_id'] ?? 0);
                    if ($cid > 0) $visible[$cid] = true;
                }
                $stmt->close();
            }
        }
    }

    if ($visible !== null && empty($visible)) return [];

    $baseWhere = [];
    $params = [];
    $types = '';
    if ($status !== '') {
        $baseWhere[] = 'd.status = ?';
        $params[] = $status;
        $types .= 's';
    } else {
        $baseWhere[] = 'c.active = 1';
    }
    if ($visible !== null) {
        $ids = array_keys($visible);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $baseWhere[] = "c.id IN ($in)";
        foreach ($ids as $id) { $params[] = (int)$id; $types .= 'i'; }
    }
    $whereSql = implode(' AND ', $baseWhere);
    $sql = "SELECT c.id, c.name
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE $whereSql
            ORDER BY c.name";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLeadById(int $id): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT l.*,
               c.name AS campaign_name,
               u.full_name AS agent_name,
               r.full_name AS reviewer_name
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.agent_id = u.id
        LEFT JOIN users r ON l.qa_reviewed_by = r.id
        WHERE l.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getLeadByIdDynamic(int $id, int $campaignId): ?array {
    $table = getCampaignLeadTableName($campaignId);
    if (!$table || !campaignLeadTableExists($table)) {
        return getLeadById($id);
    }
    
    $conn = getDbConnection();
    $sql = "
        SELECT l.*,
               c.name AS campaign_name,
               u.full_name AS agent_name,
               r.full_name AS reviewer_name
        FROM `$table` l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.agent_id = u.id
        LEFT JOIN users r ON l.qa_reviewed_by = r.id
        WHERE l.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return getLeadById($id);
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: getLeadById($id);
}

function getLeadByCode(string $leadCode): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT l.*,
               c.name AS campaign_name,
               u.full_name AS agent_name,
               r.full_name AS reviewer_name
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.agent_id = u.id
        LEFT JOIN users r ON l.qa_reviewed_by = r.id
        WHERE l.lead_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $leadCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getCampaignForm(int $campaignId): ?array {
    return getFormForCampaign($campaignId);
}

function syncLeadToCampaignTable(int $id): bool {
    $lead = getLeadById($id);
    if (!$lead) return false;
    
    $campaignId = (int)($lead['campaign_id'] ?? 0);
    if ($campaignId <= 0) return false;

    $clientId = (int)($lead['client_id'] ?? 0);
    if ($clientId <= 0) {
        $clientId = (int)(getCampaignClientId($campaignId) ?? 0);
        if ($clientId > 0) {
            $conn = getDbConnection();
            $stmtU = $conn->prepare("UPDATE leads SET client_id = ? WHERE id = ? AND (client_id IS NULL OR client_id = 0) LIMIT 1");
            if ($stmtU) {
                $stmtU->bind_param('ii', $clientId, $id);
                $stmtU->execute();
                $stmtU->close();
            }
        }
    }
    
    $formRow = getCampaignForm((int)$campaignId);
    $formId = (int)($formRow['form_id'] ?? 0);
    $schemaFields = [];
    if ($formId > 0) {
        $form = getFormById($formId);
        if (!$form) $form = getFormForCampaign($campaignId);
        $schemaFields = (array)($form['schema']['fields'] ?? []);
    }
    $customData = [];
    if ($formId > 0) {
        $submission = getLatestFormSubmissionForLead($id, $campaignId);
        if ($submission && isset($submission['data'])) {
            $customData = $submission['data'];
        }
    }
    $pickCustomScalar = function(array $aliases) use ($customData): string {
        foreach ($aliases as $k) {
            if (!array_key_exists($k, $customData)) continue;
            $v = $customData[$k];
            if (is_array($v)) continue;
            $s = trim((string)$v);
            if ($s !== '') return $s;
        }
        return '';
    };

    $pickByLabel = function(array $needles) use ($schemaFields, $customData): string {
        if (empty($schemaFields) || empty($customData)) return '';
        foreach ($schemaFields as $f) {
            if (!is_array($f)) continue;
            if (array_key_exists('visible', $f) && empty($f['visible'])) continue;
            $key = (string)($f['key'] ?? '');
            if ($key === '') continue;
            $label = strtolower(trim((string)($f['label'] ?? '')));
            if ($label === '') continue;
            foreach ($needles as $n) {
                $n = strtolower(trim((string)$n));
                if ($n !== '' && str_contains($label, $n)) {
                    if (!array_key_exists($key, $customData)) break;
                    $v = $customData[$key];
                    if (is_array($v)) continue 2;
                    $s = trim((string)$v);
                    if ($s !== '') return $s;
                    break;
                }
            }
        }
        return '';
    };

    $campaignTableData = [
        'id' => $id,
        'campaign_id' => $campaignId,
        'campaign_code' => getCampaignCode($campaignId),
        'client_id' => $clientId,
        'lead_code' => $lead['lead_id'],
        'created_by' => (int)($lead['created_by'] ?? ($lead['agent_id'] ?? 0)),
        'created_at' => $lead['created_at'],
        'updated_by' => (int)($lead['updated_by'] ?? 0),
        'updated_at' => $lead['updated_at'],
        'vendor_id' => (int)($lead['vendor_id'] ?? 0),
        'lead_source' => (string)($lead['lead_source'] ?? ''),
        'assigned_to_user' => (int)($lead['assigned_to_user'] ?? 0),
        'agent_id' => (int)($lead['agent_id'] ?? 0),
        'agent_name' => (string)($lead['agent_name'] ?? ''),
        'qa_status' => $lead['qa_status'] ?? 'Pending',
        'qa_reviewed_by' => (int)($lead['qa_reviewed_by'] ?? 0),
        'qa_reviewed_at' => $lead['qa_updated_at'] ?? $lead['qa_reviewed_at'] ?? null,
        'client_delivery_status' => $lead['client_delivery_status'] ?? 'Pending',
        'lead_status' => $lead['lead_status'] ?? 'New',
        'ip_address' => $lead['ip_address'] ?? '',
        'recording_file_path' => $lead['recording_path'] ?? '',
        'first_name' => $lead['first_name'] ?? '',
        'last_name' => $lead['last_name'] ?? '',
        'job_title' => (
            $pickCustomScalar(['job_title','jobtitle','title','designation','job_position','position','role','contact_title','contact_role'])
            ?: $pickByLabel(['job title','designation','job position'])
            ?: ($lead['job_title'] ?? '')
        ),
        'email' => $lead['email'] ?? '',
        'phone' => $lead['contact_phone'] ?? '',
        'linkedin_profile' => $pickCustomScalar(['linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin_link','prospect_linkedin_url','prospect_linkedin_profile']) ?: ($lead['linkedin_link'] ?? ''),
        'company_name' => $lead['company_name'] ?? '',
        'company_website' => (string)($customData['company_website'] ?? $customData['website'] ?? $customData['domain'] ?? ''),
        'industry' => $lead['industry'] ?? '',
        'employee_size' => $lead['company_size'] ?? '',
        'country' => $lead['country'] ?? '',
        'company_linkedin' => $pickCustomScalar(['company_linkedin','company_linkedin_url','company_linkedin_link','companylinkedin','companylinkedinurl']) ?: ($lead['company_linkedin'] ?? ''),
        'lead_comment' => $lead['lead_comment'] ?? '',
        'software_implementation_timeline' => (
            $pickCustomScalar([
            'software_implementation_timeline',
            'implementation_timeline',
            'decision_timeline',
            'timeline',
            'when_is_your_company_planning_to_implement_new_software',
            'when_is_your_company_planning_to_implement_this_solution',
            'when_is_your_company_planning_to_implement_new_software_solution',
            ])
            ?: $pickByLabel(['implement','implementation','timeline'])
            ?: ($lead['software_implementation_timeline'] ?? '')
        ),
    ];
    
    if (!empty($customData)) {
        foreach ($customData as $k => $v) {
            $nk = normalizeFieldKey((string)$k);
            if (!isset($campaignTableData[$nk])) {
                $campaignTableData[$nk] = is_array($v) ? implode(', ', $v) : (string)$v;
            }
        }
    }
    
    return upsertCampaignLeadRow($campaignId, $id, $campaignTableData);
}

function updateLead(int $id, array $data): bool {
    ensureLeadsTrackingColumns();
    $allowed = [
        'created_by',
        'updated_by',
        'campaign_id',
        'campaign_name',
        'agent_id',
        'agent_name',
        'client_id',
        'vendor_id',
        'lead_source',
        'assigned_to_user',
        'first_name',
        'last_name',
        'job_title',
        'email',
        'contact_phone',
        'industry',
        'company_name',
        'company_website',
        'company_size',
        'country',
        'software_implementation_timeline',
        'recording_path',
        'ip_address',
        'qa_status',
        'qa_comment',
        'qa_reviewed_by',
        'qa_updated_at',
        'form_done',
        'form_filled_time',
        'lead_comment',
        'email_status',
        'email_status_comment',
        'email_status_updated_at',
        'email_status_updated_by',
    ];

    $set = [];
    $params = [];
    $types = '';
    foreach ($allowed as $col) {
        if (!array_key_exists($col, $data)) continue;
        $set[] = "$col = ?";
        $val = $data[$col];
        if ($col === 'campaign_id' || $col === 'agent_id' || $col === 'qa_reviewed_by' || $col === 'created_by' || $col === 'updated_by' || $col === 'email_status_updated_by' || $col === 'client_id' || $col === 'vendor_id' || $col === 'assigned_to_user') {
            $types .= 'i';
            $params[] = ($val === null || $val === '') ? null : (int)$val;
        } else {
            $types .= 's';
            $params[] = $val;
        }
    }
    if (empty($set)) return true;

    $conn = getDbConnection();
    $sql = "UPDATE leads SET " . implode(', ', $set) . " WHERE id = ?";
    $types .= 'i';
    $params[] = $id;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        syncLeadToCampaignTable($id);
        if (function_exists('notifyLeadUpdated')) {
            $actorId = (int)($data['updated_by'] ?? 0);
            $changed = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $data)) $changed[] = $col;
            }
            notifyLeadUpdated($id, $actorId, $changed);
        }
    }
    return $ok;
}

function logLeadActivity(int $leadId, ?int $actorId, string $action, array $meta = []): bool {
    $conn = getDbConnection();
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($metaJson === false) $metaJson = null;
    $stmt = $conn->prepare("INSERT INTO lead_activity (lead_id, actor_id, action, meta_json) VALUES (?, ?, ?, ?)");
    $actor = $actorId ?? null;
    $stmt->bind_param('iiss', $leadId, $actor, $action, $metaJson);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function deleteSingleLead(int $leadId, bool $deleteFiles = false): bool {
    $leadId = (int)$leadId;
    if ($leadId <= 0) return false;
    $conn = getDbConnection();
    $lead = getLeadById($leadId);
    if (!$lead) return false;

    $campaignId = (int)($lead['campaign_id'] ?? 0);
    $recordingPath = trim((string)($lead['recording_path'] ?? ''));
    $paths = [];
    if ($deleteFiles && $recordingPath !== '') $paths[] = $recordingPath;
    if ($deleteFiles) {
        $stmt = $conn->prepare("SELECT file_path FROM lead_files WHERE lead_id = ? AND file_path IS NOT NULL AND file_path <> ''");
        if ($stmt) {
            $stmt->bind_param('i', $leadId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $r) {
                $p = trim((string)($r['file_path'] ?? ''));
                if ($p !== '') $paths[] = $p;
            }
        }
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM lead_activity WHERE lead_id = ?");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }
        $stmt = $conn->prepare("DELETE FROM lead_files WHERE lead_id = ?");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }
        $stmt = $conn->prepare("DELETE FROM form_submissions WHERE lead_id = ?");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }

        if ($campaignId > 0) {
            $ct = getCampaignLeadTableName($campaignId);
            if ($ct && campaignLeadTableExists($ct) && preg_match('/^[A-Za-z0-9_]+$/', $ct)) {
                $conn->query("DELETE FROM `$ct` WHERE id = " . (int)$leadId . " LIMIT 1");
            }
        }

        $stmt = $conn->prepare("DELETE FROM leads WHERE id = ? LIMIT 1");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }

    if ($deleteFiles && !empty($paths)) {
        $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
        foreach ($paths as $rel) {
            $rel = str_replace(['\\', "\0"], ['/', ''], (string)$rel);
            if (str_starts_with($rel, '/')) $rel = ltrim($rel, '/');
            $full = realpath($root . '/' . $rel);
            if ($full && str_starts_with($full, $root) && is_file($full)) {
                @unlink($full);
            }
        }
    }
    return true;
}

function uploadRecording(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }
    $maxSize = 50 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        return false;
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['mp3', 'wav', 'm4a', 'aac', 'ogg'];
    if (!in_array($ext, $allowed, true)) {
        return false;
    }
    $dir = __DIR__ . '/../uploads/lead_files';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $safeName = 'rec_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $dir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return false;
    }
    return 'uploads/lead_files/' . $safeName;
}

function findDuplicateLeads(string $firstName = '', string $lastName = '', string $email = '', string $companyName = '', int $limit = 10, int $campaignId = 0, string $search = ''): array {
    $conn = getDbConnection();
    $where = [];
    $params = [];
    $types = '';

    $search = trim($search);
    if ($search !== '') {
        $where[] = "(l.email LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.company_name LIKE ? OR l.lead_id LIKE ?)";
        $p = '%' . $search . '%';
        $params = array_merge($params, [$p, $p, $p, $p, $p]);
        $types .= 'sssss';
    }

    $email = trim($email);
    if ($email !== '') {
        $where[] = "l.email = ?";
        $params[] = $email;
        $types .= 's';
        
        $emailDomain = extractDomain($email);
        if ($emailDomain !== '') {
            $where[] = "LOWER(SUBSTRING_INDEX(l.email,'@',-1)) = ?";
            $params[] = strtolower($emailDomain);
            $types .= 's';
        }
    }

    $nameParts = [];
    if (trim($firstName) !== '') { $nameParts[] = "l.first_name LIKE ?"; $params[] = '%'.trim($firstName).'%'; $types .= 's'; }
    if (trim($lastName) !== '') { $nameParts[] = "l.last_name LIKE ?"; $params[] = '%'.trim($lastName).'%'; $types .= 's'; }
    if (!empty($nameParts)) {
        $where[] = '(' . implode(' AND ', $nameParts) . ')';
    }

    if (trim($companyName) !== '') {
        $where[] = "l.company_name LIKE ?";
        $params[] = '%'.trim($companyName).'%';
        $types .= 's';
    }

    if (empty($where)) {
        return [];
    }

    $clientId = $campaignId > 0 ? (int)(getCampaignClientId($campaignId) ?? 0) : 0;
    $scopeSql = '';
    if ($clientId > 0) {
        $scopeSql = 'd.client_id = ? AND ';
        $params = array_merge([$clientId], $params);
        $types = 'i' . $types;
    }

    $sql = "
        SELECT l.id, l.first_name, l.last_name, l.email, l.company_name, l.qa_status, l.created_at,
               d.client_id, d.code AS campaign_code, c.name AS campaign_name
        FROM leads l
        LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
        LEFT JOIN campaigns c ON c.id = l.campaign_id
        WHERE " . $scopeSql . "(" . implode(' OR ', $where) . ")
        ORDER BY l.created_at DESC
        LIMIT " . (int)$limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('findDuplicateLeads prepare failed: ' . ($conn->error ?? 'unknown'));
        return [];
    }
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();
    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

function extractDomain(string $value): string {
    $v = trim($value);
    if ($v === '') return '';
    if (strpos($v, '@') !== false) {
        $parts = explode('@', $v);
        $v = trim((string)end($parts));
    }
    $v = preg_replace('/^https?:\/\//i', '', $v);
    $v = preg_replace('/^www\./i', '', $v);
    $v = preg_replace('/\/.*$/', '', $v);
    $v = preg_replace('/:.*/', '', $v);
    return strtolower(trim($v));
}

function findClientDomainSuppressionLead(int $campaignId, string $domain, int $days = 90): ?array {
    $domain = extractDomain($domain);
    if ($campaignId <= 0 || $domain === '') return null;
    $clientId = (int)(getCampaignClientId($campaignId) ?? 0);
    if ($clientId <= 0) return null;
    $conn = getDbConnection();
    $sql = "
        SELECT l.id, l.lead_id, l.company_name, l.email, l.qa_updated_at, l.created_at
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE d.client_id = ?
          AND l.client_delivery_status = 'Delivered'
          AND l.company_domain = ?
          AND COALESCE(l.qa_updated_at, l.created_at) >= (NOW() - INTERVAL ? DAY)
        ORDER BY COALESCE(l.qa_updated_at, l.created_at) DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('isi', $clientId, $domain, $days);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function findDuplicateSalesLeads(array $data, int $excludeId = 0, int $limit = 10): array {
    $conn = getDbConnection();
    $companyName = trim((string)($data['company_name'] ?? ''));
    $websiteDomain = extractDomain((string)($data['website'] ?? ($data['website_domain'] ?? '')));
    $emailDomain = extractDomain((string)($data['contact_email'] ?? ($data['contact_email_domain'] ?? '')));
    $linkedin = trim((string)($data['linkedin_url'] ?? ''));

    $where = [];
    $params = [];
    $types = '';

    if ($excludeId > 0) {
        $where[] = "sl.id <> ?";
        $params[] = $excludeId;
        $types .= 'i';
    }

    $dupParts = [];
    if ($websiteDomain !== '') { $dupParts[] = "sl.website_domain = ?"; $params[] = $websiteDomain; $types .= 's'; }
    if ($companyName !== '') { $dupParts[] = "sl.company_name LIKE ?"; $params[] = '%'.$companyName.'%'; $types .= 's'; }
    if ($emailDomain !== '') { $dupParts[] = "sl.contact_email_domain = ?"; $params[] = $emailDomain; $types .= 's'; }
    if ($linkedin !== '') { $dupParts[] = "sl.linkedin_url = ?"; $params[] = $linkedin; $types .= 's'; }

    if (empty($dupParts)) return [];
    $where[] = '(' . implode(' OR ', $dupParts) . ')';

    $sql = "SELECT sl.id, sl.company_name, sl.website, sl.contact_email, sl.linkedin_url, sl.status, sl.created_at
            FROM sales_leads sl
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sl.created_at DESC
            LIMIT " . (int)$limit;

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();
    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

function getSalesLeadById(int $id): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT sl.*,
        o.full_name AS owner_name, o.role AS owner_role,
        m.full_name AS manager_name, m.role AS manager_role,
        c.id AS client_id, c.client_code AS client_code, c.name AS client_name
        FROM sales_leads sl
        LEFT JOIN users o ON o.id = sl.owner_id
        LEFT JOIN users m ON m.id = sl.sales_manager_id
        LEFT JOIN clients c ON c.id = sl.client_id
        WHERE sl.id = ?
        LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getSalesLeadActivities(int $salesLeadId, int $limit = 200): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT a.*,
        u.full_name AS created_by_name, u.role AS created_by_role,
        uu.full_name AS updated_by_name, uu.role AS updated_by_role
        FROM sales_lead_activities a
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN users uu ON uu.id = a.updated_by
        WHERE a.sales_lead_id = ?
        ORDER BY a.created_at DESC
        LIMIT " . (int)$limit);
    $stmt->bind_param('i', $salesLeadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function updateSalesLeadActivity(int $activityId, string $status, string $comment, int $updatedBy): bool {
    $conn = getDbConnection();
    $status = trim($status);
    $comment = trim($comment);
    if ($comment === '') throw new RuntimeException('Comment is required');

    $stmt = $conn->prepare("UPDATE sales_lead_activities
        SET status = ?, comment = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?");
    $stmt->bind_param('ssii', $status, $comment, $updatedBy, $activityId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getUsdInrRate(?string $date = null): ?float {
    $conn = getDbConnection();
    $date = $date ?: date('Y-m-d');
    $stmt = $conn->prepare("SELECT usd_inr FROM fx_rates WHERE rate_date = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return null;
    $v = (float)($row['usd_inr'] ?? 0);
    return $v > 0 ? $v : null;
}

function setUsdInrRate(string $date, float $rate, int $updatedBy): bool {
    $conn = getDbConnection();
    $date = date('Y-m-d', strtotime($date));
    $rate = (float)$rate;
    if ($rate <= 0) throw new RuntimeException('Invalid USD→INR rate');
    $stmt = $conn->prepare("INSERT INTO fx_rates (rate_date, usd_inr, updated_by, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE usd_inr = VALUES(usd_inr), updated_by = VALUES(updated_by), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('sdi', $date, $rate, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function upsertSalesMonthlyTarget(int $userId, int $year, int $month, int $newAccountsTarget, float $revenueTargetUsd, int $assignedBy): bool {
    $conn = getDbConnection();
    $year = max(2000, min(2100, $year));
    $month = max(1, min(12, $month));
    $newAccountsTarget = max(0, $newAccountsTarget);
    $revenueTargetUsd = max(0.0, (float)$revenueTargetUsd);

    $stmt = $conn->prepare("INSERT INTO sales_targets (user_id, year, month, target_new_accounts, target_revenue_usd, assigned_by, assigned_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            target_new_accounts = VALUES(target_new_accounts),
            target_revenue_usd = VALUES(target_revenue_usd),
            assigned_by = VALUES(assigned_by),
            assigned_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iiiidi', $userId, $year, $month, $newAccountsTarget, $revenueTargetUsd, $assignedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getSalesMonthlyTarget(int $userId, int $year, int $month): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM sales_targets WHERE user_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $userId, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getSalesTargetAssignableUsers(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, job_title, role FROM users WHERE role IN ('sdr','sales_manager') ORDER BY role, full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function getSalesScopeUserIdsForCurrentUser(array $user): ?array {
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    if ($role === 'admin' || $role === 'sales_director') return null;
    if ($role === 'sdr') return [$userId];
    if ($role === 'sales_manager') {
        $conn = getDbConnection();
        $ids = [$userId => true];
        $stmt = $conn->prepare("SELECT sdr_user_id FROM sales_manager_sdr_map WHERE manager_user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $sid = (int)($r['sdr_user_id'] ?? 0);
            if ($sid > 0) $ids[$sid] = true;
        }
        $stmt->close();
        return array_keys($ids);
    }
    return [$userId];
}

function getSalesNewAccountsProgress(array $user, int $year, int $month): array {
    $conn = getDbConnection();
    $scope = getSalesScopeUserIdsForCurrentUser($user);
    $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
    if ($scope === null) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM sales_client_ownership WHERE assigned_at >= ? AND assigned_at < ?");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        return ['achieved' => $cnt];
    }
    if (empty($scope)) return ['achieved' => 0];
    $in = implode(',', array_fill(0, count($scope), '?'));
    $sql = "SELECT COUNT(*) AS cnt
        FROM sales_client_ownership sco
        WHERE sco.assigned_at >= ? AND sco.assigned_at < ?
          AND (sco.owner_id IN ($in) OR sco.manager_id IN ($in))";
    $stmt = $conn->prepare($sql);
    $types = 'ss' . str_repeat('i', count($scope)) . str_repeat('i', count($scope));
    $stmt->bind_param($types, $start, $end, ...$scope, ...$scope);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return ['achieved' => $cnt];
}

function getSalesRevenueProgress(array $user): array {
    $conn = getDbConnection();
    $scope = getSalesScopeUserIdsForCurrentUser($user);
    $clientIds = [];
    if ($scope === null) {
        $rs = $conn->query("SELECT client_id FROM sales_client_ownership");
        if ($rs) {
            while ($r = $rs->fetch_assoc()) {
                $cid = (int)($r['client_id'] ?? 0);
                if ($cid > 0) $clientIds[$cid] = true;
            }
        }
    } else {
        if (!empty($scope)) {
            $in = implode(',', array_fill(0, count($scope), '?'));
            $sql = "SELECT DISTINCT client_id
                FROM sales_client_ownership
                WHERE owner_id IN ($in) OR manager_id IN ($in)";
            $stmt = $conn->prepare($sql);
            $types = str_repeat('i', count($scope)) . str_repeat('i', count($scope));
            $stmt->bind_param($types, ...$scope, ...$scope);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $cid = (int)($r['client_id'] ?? 0);
                if ($cid > 0) $clientIds[$cid] = true;
            }
            $stmt->close();
        }
    }

    $allocated = 0.0;
    $generated = 0.0;
    $campaignCount = 0;
    $clientCount = count($clientIds);

    foreach (array_keys($clientIds) as $cid) {
        $stmt = $conn->prepare("SELECT c.id AS campaign_id, d.total_leads, d.cpl
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE d.client_id = ?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $campaignId = (int)($r['campaign_id'] ?? 0);
            $cpl = (float)($r['cpl'] ?? 0);
            $tl = (int)($r['total_leads'] ?? 0);
            if ($campaignId <= 0 || $cpl <= 0) continue;
            $campaignCount++;
            if ($tl > 0) $allocated += ($cpl * $tl);
            $stats = getCampaignLeadTableStats($campaignId);
            $delivered = (int)($stats['approved'] ?? 0) + (int)($stats['rejected'] ?? 0);
            if ($delivered > 0) $generated += ($cpl * $delivered);
        }
    }

    $pending = max(0.0, $allocated - $generated);
    return [
        'allocated_usd' => $allocated,
        'generated_usd' => $generated,
        'pending_usd' => $pending,
        'clients' => $clientCount,
        'campaigns' => $campaignCount,
    ];
}

function createSalesLead(array $data, int $createdBy): int {
    $conn = getDbConnection();
    $companyName = trim((string)($data['company_name'] ?? ''));
    if ($companyName === '') throw new RuntimeException('Company Name is required');

    $website = trim((string)($data['website'] ?? ''));
    $websiteDomain = extractDomain($website);
    $contactEmail = trim((string)($data['contact_email'] ?? ''));
    $emailDomain = extractDomain($contactEmail);
    $linkedin = trim((string)($data['linkedin_url'] ?? ''));

    $ownerId = (int)($data['owner_id'] ?? $createdBy);
    $managerId = ($data['sales_manager_id'] ?? '') !== '' ? (int)$data['sales_manager_id'] : null;

    $stmt = $conn->prepare("INSERT INTO sales_leads
        (company_name, website, website_domain, industry, company_size, country,
         contact_name, contact_job_title, contact_email, contact_email_domain, contact_phone, linkedin_url,
         lead_source, status, priority, expected_opportunity_size, notes,
         owner_id, sales_manager_id, created_by, created_at, updated_at, last_activity_at, next_follow_up_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW(),NULL)");

    $expected = ($data['expected_opportunity_size'] ?? '') !== '' ? (string)$data['expected_opportunity_size'] : null;
    $params = [
        $companyName,
        $website,
        $websiteDomain,
        $data['industry'],
        $data['company_size'],
        $data['country'],
        $data['contact_name'],
        $data['contact_job_title'],
        $contactEmail,
        $emailDomain,
        $data['contact_phone'],
        $linkedin,
        $data['lead_source'],
        $data['status'],
        $data['priority'],
        $expected,
        $data['notes'],
        $ownerId,
        $managerId,
        $createdBy
    ];
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Failed to create sales lead');
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function updateSalesLead(int $id, array $data, int $updatedBy): bool {
    $conn = getDbConnection();
    $allowed = [
        'company_name','website','industry','company_size','country',
        'contact_name','contact_job_title','contact_email','contact_phone','linkedin_url',
        'lead_source','status','priority','expected_opportunity_size','notes',
        'owner_id','sales_manager_id','next_follow_up_at'
    ];

    $set = [];
    $params = [];
    foreach ($allowed as $col) {
        if (!array_key_exists($col, $data)) continue;
        if ($col === 'website') {
            $set[] = "website = ?";
            $params[] = trim((string)$data['website']);
            $set[] = "website_domain = ?";
            $params[] = extractDomain((string)$data['website']);
            continue;
        }
        if ($col === 'contact_email') {
            $set[] = "contact_email = ?";
            $params[] = trim((string)$data['contact_email']);
            $set[] = "contact_email_domain = ?";
            $params[] = extractDomain((string)$data['contact_email']);
            continue;
        }
        $set[] = "$col = ?";
        $params[] = $data[$col];
    }
    $set[] = "updated_by = ?";
    $params[] = $updatedBy;
    $set[] = "updated_at = NOW()";

    $sql = "UPDATE sales_leads SET ".implode(', ', $set)." WHERE id = ?";
    $params[] = $id;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function addSalesLeadActivity(int $salesLeadId, string $status, string $comment, int $createdBy, ?string $nextFollowUpAt = null): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO sales_lead_activities (sales_lead_id, status, comment, created_by, created_at) VALUES (?,?,?,?,NOW())");
    $stmt->bind_param('issi', $salesLeadId, $status, $comment, $createdBy);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $closed = in_array($status, ['Closed Won','Closed Lost'], true);
        if ($closed) {
            $upd = $conn->prepare("UPDATE sales_leads SET last_activity_at = NOW(), next_follow_up_at = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('ii', $createdBy, $salesLeadId);
            $upd->execute();
            $upd->close();
        } elseif ($nextFollowUpAt !== null && trim($nextFollowUpAt) !== '') {
            $nf = trim($nextFollowUpAt);
            $upd = $conn->prepare("UPDATE sales_leads SET last_activity_at = NOW(), next_follow_up_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('sii', $nf, $createdBy, $salesLeadId);
            $upd->execute();
            $upd->close();
        } else {
            $upd = $conn->prepare("UPDATE sales_leads SET last_activity_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('ii', $createdBy, $salesLeadId);
            $upd->execute();
            $upd->close();
        }
    }
    return $ok;
}

function findDuplicateClientsByNameOrDomain(string $name, string $website): array {
    $conn = getDbConnection();
    $name = trim($name);
    $domain = extractDomain($website);

    $where = [];
    $params = [];
    $types = '';

    if ($domain !== '') {
        $where[] = "website_domain = ?";
        $params[] = $domain;
        $types .= 's';
    }
    if ($name !== '') {
        $where[] = "name LIKE ?";
        $params[] = '%'.$name.'%';
        $types .= 's';
    }
    if (empty($where)) return [];

    $sql = "SELECT id, client_code, name, website FROM clients WHERE ".implode(' OR ', $where)." ORDER BY created_at DESC LIMIT 10";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function generateClientCode(string $companyName = ''): string {
    $conn = getDbConnection();
    $base = strtoupper(preg_replace('/[^A-Z]/', '', strtoupper((string)$companyName)));
    $base = substr($base, 0, 3);
    if ($base === '') $base = 'CL';

    for ($i = 0; $i < 25; $i++) {
        $rand = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $code = 'DFB-'.$base.'-'.$rand;
        $stmt = $conn->prepare("SELECT 1 FROM clients WHERE client_code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) return $code;
    }

    return 'DFB-CL-'.bin2hex(random_bytes(2));
}

function convertSalesLeadToClient(int $salesLeadId, int $actorUserId): int {
    $conn = getDbConnection();
    $lead = getSalesLeadById($salesLeadId);
    if (!$lead) throw new RuntimeException('Prospect not found');
    if (($lead['status'] ?? '') !== 'Closed Won') throw new RuntimeException('Prospect must be Closed Won before conversion');
    if (!empty($lead['client_id'])) return (int)$lead['client_id'];

    $dups = findDuplicateClientsByNameOrDomain((string)($lead['company_name'] ?? ''), (string)($lead['website'] ?? ''));
    if (!empty($dups)) throw new RuntimeException('Duplicate client found. Open the existing client instead of converting.');

    $clientCode = generateClientCode((string)($lead['company_name'] ?? ''));
    $clientName = trim((string)($lead['company_name'] ?? ''));
    $website = trim((string)($lead['website'] ?? ''));
    $websiteDomain = extractDomain($website);
    $industry = trim((string)($lead['industry'] ?? ''));
    $notes = trim((string)($lead['notes'] ?? ''));
    $prefix = 'Converted from Sales Prospect #'.$salesLeadId;
    $clientNotes = $notes !== '' ? ($prefix."\n".$notes) : $prefix;

    $stmt = $conn->prepare("INSERT INTO clients (client_code, name, website, website_domain, industry, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param('ssssssi', $clientCode, $clientName, $website, $websiteDomain, $industry, $clientNotes, $actorUserId);
    if (!$stmt->execute()) throw new RuntimeException('Failed to create client');
    $clientId = (int)$conn->insert_id;
    $stmt->close();

    $contactName = trim((string)($lead['contact_name'] ?? ''));
    $contactEmail = trim((string)($lead['contact_email'] ?? ''));
    $contactPhone = trim((string)($lead['contact_phone'] ?? ''));
    $contactTitle = trim((string)($lead['contact_job_title'] ?? ''));
    if ($contactName !== '' || $contactEmail !== '' || $contactPhone !== '' || $contactTitle !== '') {
        $cName = $contactName !== '' ? $contactName : ($clientName !== '' ? $clientName.' Contact' : 'Contact');
        $stmt = $conn->prepare("INSERT INTO client_contacts (client_id, name, email, phone, title, created_at) VALUES (?,?,?,?,?,NOW())");
        $stmt->bind_param('issss', $clientId, $cName, $contactEmail, $contactPhone, $contactTitle);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE sales_leads SET client_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('iii', $clientId, $actorUserId, $salesLeadId);
    $stmt->execute();
    $stmt->close();

    $ownerId = (int)($lead['owner_id'] ?? 0);
    $managerId = (int)($lead['sales_manager_id'] ?? 0);
    $managerId = $managerId > 0 ? $managerId : null;
    upsertSalesClientOwnership($clientId, $ownerId, $managerId, $actorUserId, $salesLeadId);

    addSalesLeadActivity($salesLeadId, 'Closed Won', 'Converted to client: '.$clientCode, $actorUserId);

    return $clientId;
}

function upsertSalesClientOwnership(int $clientId, int $ownerId, ?int $managerId, int $assignedBy, ?int $sourceSalesLeadId = null): bool {
    $conn = getDbConnection();
    if ($clientId <= 0 || $ownerId <= 0) throw new RuntimeException('Invalid client assignment');
    $managerId = $managerId && $managerId > 0 ? $managerId : null;
    $sourceSalesLeadId = $sourceSalesLeadId && $sourceSalesLeadId > 0 ? $sourceSalesLeadId : null;

    $stmt = $conn->prepare("INSERT INTO sales_client_ownership (client_id, owner_id, manager_id, source_sales_lead_id, assigned_at, assigned_by)
        VALUES (?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            owner_id = VALUES(owner_id),
            manager_id = VALUES(manager_id),
            source_sales_lead_id = VALUES(source_sales_lead_id),
            assigned_at = NOW(),
            assigned_by = VALUES(assigned_by)");
    if (!$stmt) return false;
    $stmt->bind_param('iiiii', $clientId, $ownerId, $managerId, $sourceSalesLeadId, $assignedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getSalesManagersBasic(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('sales_manager','sales_director') ORDER BY role, full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function getSalesOwnersBasic(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('sales_manager','sdr') ORDER BY role, full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function pushLeadToGoogleSheet(string $campaignName, array $leadData): bool {
    return false;
}

/**
 * Ensure all required tables and columns exist in the database.
 * This should be run sparingly (e.g., once per session or on app update).
 */
function ensureDatabaseSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    $hasIndex = function(string $table, string $indexName) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->bind_param('ss', $table, $indexName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    $getColumnType = function(string $table, string $column) use ($conn): ?string {
        $stmt = $conn->prepare("SELECT COLUMN_TYPE AS ct FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row ? (string)($row['ct'] ?? '') : null;
    };
    $addColumn = function(string $table, string $column, string $definition) use ($conn, $hasColumn): void {
        if ($hasColumn($table, $column)) return;
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    };
    $addIndex = function(string $table, string $indexName, string $columns, bool $unique = false) use ($conn, $hasIndex): void {
        if ($hasIndex($table, $indexName)) return;
        $kw = $unique ? 'UNIQUE ' : '';
        $conn->query("ALTER TABLE `$table` ADD {$kw}INDEX `$indexName` ($columns)");
    };

    // Essential Core Tables
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'agent',
        is_active TINYINT(1) DEFAULT 1,
        is_locked TINYINT(1) DEFAULT 0,
        locked_until TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role (role),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaigns (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 0,
        owner_id INT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT NULL,
        login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_activity DATETIME NULL,
        logout_time DATETIME NULL,
        INDEX idx_user (user_id),
        INDEX idx_login (login_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id VARCHAR(20) NOT NULL,
        campaign_id INT NOT NULL,
        campaign_name VARCHAR(100) NOT NULL,
        agent_id INT NOT NULL,
        agent_name VARCHAR(100) NOT NULL,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        job_title VARCHAR(150) NULL,
        email VARCHAR(150) NULL,
        company_domain VARCHAR(255) NULL,
        linkedin_link TEXT NULL,
        contact_phone VARCHAR(20) NULL,
        industry VARCHAR(100) NULL,
        company_linkedin TEXT NULL,
        company_name VARCHAR(150) NULL,
        company_size VARCHAR(50) NULL,
        country VARCHAR(100) NULL,
        software_implementation_timeline VARCHAR(20) NULL,
        recording_path VARCHAR(255) NULL,
        qa_status ENUM('Qualified','Disqualified','Rework Needed','Duplicate','Pending') NULL DEFAULT 'Pending',
        qa_comment TEXT NULL,
        qa_client_comment TEXT NULL,
        qa_updated_at TIMESTAMP NULL DEFAULT NULL,
        qa_reviewed_by INT NULL,
        client_delivery_status ENUM('Pending','Delivered') NOT NULL DEFAULT 'Pending',
        form_done ENUM('Yes','No') NULL DEFAULT 'No',
        ip_address VARCHAR(50) NULL,
        form_filled_time TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        lead_comment TEXT NULL,
        created_by INT NULL,
        updated_by INT NULL,
        INDEX idx_campaign_id (campaign_id),
        INDEX idx_agent_id (agent_id),
        INDEX idx_client_delivery (client_delivery_status),
        INDEX idx_company_domain (company_domain),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Campaign details table
    $conn->query("CREATE TABLE IF NOT EXISTS campaign_details (
        campaign_id INT NOT NULL PRIMARY KEY,
        code VARCHAR(32) NOT NULL UNIQUE,
        client_code VARCHAR(50) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        start_date DATE NULL,
        end_date DATE NULL,
        total_leads INT NULL,
        pacing_type VARCHAR(20) NULL,
        pacing_count INT NULL,
        cpc DECIMAL(10,2) NULL,
        cpl DECIMAL(10,2) NULL,
        cpl_currency VARCHAR(8) NULL,
        campaign_type VARCHAR(40) NULL,
        delivery_format VARCHAR(40) NULL,
        targeted_country TEXT NULL,
        job_title VARCHAR(255) NULL,
        departments TEXT NULL,
        seniority_levels TEXT NULL,
        industries TEXT NULL,
        employee_sizes TEXT NULL,
        revenue_sizes TEXT NULL,
        instruction TEXT NULL,
        script_path VARCHAR(255) NULL,
        tal_path VARCHAR(255) NULL,
        suppression_path VARCHAR(255) NULL,
        recording_path VARCHAR(255) NULL,
        custom_fields_json TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_status(status),
        INDEX idx_campaign_type(campaign_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    ensureTeamSchema();
    ensureAppSettingsSchema();
    ensureUserIpAccessSchema();

    $conn->query("CREATE TABLE IF NOT EXISTS holidays (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        country_code VARCHAR(2) NOT NULL,
        holiday_date DATE NOT NULL,
        name VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_country_date (country_code, holiday_date),
        INDEX idx_date (holiday_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_attendance_days (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        work_date DATE NOT NULL,
        punch_in DATETIME NULL,
        punch_out DATETIME NULL,
        current_state VARCHAR(20) NOT NULL DEFAULT 'Off',
        break_minutes INT NOT NULL DEFAULT 0,
        working_minutes INT NOT NULL DEFAULT 0,
        late_minutes INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Absent',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_date (user_id, work_date),
        INDEX idx_work_date (work_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $addColumn('hr_attendance_days', 'late_minutes', "INT NOT NULL DEFAULT 0 AFTER `working_minutes`");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_attendance_states (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attendance_day_id INT NOT NULL,
        state VARCHAR(20) NOT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NULL,
        minutes INT NULL,
        INDEX idx_day (attendance_day_id),
        INDEX idx_state (state),
        INDEX idx_start (start_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_salary_settings (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
        pf DECIMAL(12,2) NOT NULL DEFAULT 0,
        tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
        effective_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_date (user_id, effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_salary_structures (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        effective_date DATE NOT NULL,
        structure_type VARCHAR(40) NOT NULL DEFAULT 'Standard',
        total_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        basic DECIMAL(12,2) NOT NULL DEFAULT 0,
        hra DECIMAL(12,2) NOT NULL DEFAULT 0,
        conveyance DECIMAL(12,2) NOT NULL DEFAULT 0,
        medical DECIMAL(12,2) NOT NULL DEFAULT 0,
        special_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
        pf DECIMAL(12,2) NOT NULL DEFAULT 0,
        professional_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        tds DECIMAL(12,2) NOT NULL DEFAULT 0,
        locked TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_user_date (user_id, effective_date),
        INDEX idx_effective (effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_bonuses (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_month (user_id, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_loans (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        emi_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        start_date DATE NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_active (user_id, active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_loan_deductions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NOT NULL,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loan_month (loan_id, year, month),
        INDEX idx_user_month (user_id, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_payslips (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        salary_data LONGTEXT NOT NULL,
        generated_at DATETIME NOT NULL,
        generated_by INT NOT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_month (user_id, year, month),
        INDEX idx_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_payroll_month_locks (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        month INT NOT NULL,
        locked_by INT NOT NULL,
        locked_at DATETIME NOT NULL,
        UNIQUE KEY uniq_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_shifts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        grace_minutes INT NOT NULL DEFAULT 15,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_user_shift_assignments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        shift_id INT NOT NULL,
        effective_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_date (user_id, effective_date),
        INDEX idx_user (user_id),
        INDEX idx_effective (effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_personal_details (
        user_id INT NOT NULL PRIMARY KEY,
        personal_email VARCHAR(190) NULL,
        emergency_contact_number VARCHAR(40) NULL,
        date_of_birth DATE NULL,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_bank_details (
        user_id INT NOT NULL PRIMARY KEY,
        bank_name VARCHAR(120) NULL,
        account_number VARCHAR(40) NULL,
        account_type VARCHAR(20) NULL,
        ifsc_code VARCHAR(20) NULL,
        pan_number VARCHAR(20) NULL,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_documents (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(60) NOT NULL,
        doc_type VARCHAR(80) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NULL,
        mime_type VARCHAR(120) NULL,
        file_size INT NULL,
        uploaded_by INT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_user_cat (user_id, category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('users', 'reporting_manager_id', 'INT NULL');
    $addColumn('users', 'onboarding_notes', 'TEXT NULL');

    $addColumn('hr_attendance_days', 'late_minutes', 'INT NOT NULL DEFAULT 0');
    $addColumn('hr_attendance_days', 'shift_id', 'INT NULL');
    $addColumn('hr_attendance_days', 'shift_start_time', 'TIME NULL');
    $addColumn('hr_attendance_days', 'grace_minutes', 'INT NOT NULL DEFAULT 15');

    // Chat schema
    $conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        delivered_at DATETIME NULL,
        read_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sender(sender_id),
        INDEX idx_receiver(receiver_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_presence (
        user_id INT PRIMARY KEY,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_online TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Campaign delivery and metrics
    $conn->query("CREATE TABLE IF NOT EXISTS campaign_delivery_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        file_type VARCHAR(50) NULL,
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(255) NOT NULL,
        uploader_id INT NULL,
        format VARCHAR(40) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_campaign(campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_additional_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        file_title VARCHAR(180) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_type VARCHAR(80) NULL,
        description TEXT NULL,
        uploaded_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_campaign(campaign_id),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_user_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_campaign_user (campaign_id, user_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS operations_campaign_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ops_campaign_user (campaign_id, user_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_user(user_id),
        INDEX idx_assigned_by(assigned_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS qa_campaign_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_qa_campaign_user (campaign_id, user_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_user(user_id),
        INDEX idx_assigned_by(assigned_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS qa_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        prev_status VARCHAR(32) NULL,
        campaign_id INT NOT NULL,
        qa_status VARCHAR(32) NOT NULL,
        qa_comment TEXT NULL,
        qa_client_comment TEXT NULL,
        client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        qa_reviewed_by INT NOT NULL,
        reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead(lead_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_reviewer(qa_reviewed_by),
        INDEX idx_reviewed_at(reviewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$hasColumn('qa_audit_logs', 'prev_status')) {
        @$conn->query("ALTER TABLE qa_audit_logs ADD COLUMN prev_status VARCHAR(32) NULL AFTER lead_id");
    }
    if (!$hasColumn('qa_audit_logs', 'qa_client_comment')) {
        @$conn->query("ALTER TABLE qa_audit_logs ADD COLUMN qa_client_comment TEXT NULL AFTER qa_comment");
    }
    if (!$hasColumn('qa_audit_logs', 'client_delivery_status')) {
        @$conn->query("ALTER TABLE qa_audit_logs ADD COLUMN client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER qa_client_comment");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS qa_assignment_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requested_by INT NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'Open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_by INT NULL,
        resolved_at DATETIME NULL,
        INDEX idx_status(status),
        INDEX idx_created(created_at),
        INDEX idx_requested_by(requested_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_metrics (
        campaign_id INT PRIMARY KEY,
        delivered INT NULL,
        generated INT NULL,
        qualified INT NULL,
        disqualified INT NULL,
        pending INT NULL,
        rejected INT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS forms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        fingerprint CHAR(64) NOT NULL UNIQUE,
        schema_json MEDIUMTEXT NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS form_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(150) NOT NULL,
        schema_json MEDIUMTEXT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_forms (
        campaign_id INT NOT NULL UNIQUE,
        form_id INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (campaign_id),
        INDEX idx_form(form_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS form_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        form_id INT NOT NULL,
        campaign_id INT NOT NULL,
        lead_id INT NULL,
        submitted_by INT NOT NULL,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        data_json MEDIUMTEXT NOT NULL,
        INDEX idx_form(form_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_lead(lead_id),
        INDEX idx_lead_campaign(lead_id, campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addIndex('form_submissions', 'idx_lead_campaign', 'lead_id, campaign_id');

    $conn->query("CREATE TABLE IF NOT EXISTS lead_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        field_id VARCHAR(80) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead(lead_id),
        INDEX idx_field(field_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS lead_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        actor_id INT NULL,
        action VARCHAR(50) NOT NULL,
        meta_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead(lead_id),
        INDEX idx_actor(actor_id),
        INDEX idx_action(action),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS url_previews (
        url_hash CHAR(64) PRIMARY KEY,
        url TEXT NOT NULL,
        final_url TEXT NULL,
        preview_title VARCHAR(255) NULL,
        preview_description TEXT NULL,
        preview_image VARCHAR(512) NULL,
        fetch_status VARCHAR(20) NOT NULL DEFAULT 'ok',
        http_status INT NULL,
        last_error VARCHAR(255) NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fetched(fetched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL,
        body TEXT NULL,
        link_url VARCHAR(255) NULL,
        dedup_key CHAR(40) NULL,
        importance VARCHAR(12) NOT NULL DEFAULT 'normal',
        show_toast TINYINT(1) NOT NULL DEFAULT 0,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        INDEX idx_user_created(user_id, created_at),
        INDEX idx_user_read(user_id, is_read),
        INDEX idx_user_dedup(user_id, type, dedup_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('notifications', 'dedup_key', "CHAR(40) NULL AFTER `link_url`");
    $addColumn('notifications', 'importance', "VARCHAR(12) NOT NULL DEFAULT 'normal' AFTER `dedup_key`");
    $addColumn('notifications', 'show_toast', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `importance`");
    $addIndex('notifications', 'idx_user_dedup', 'user_id, type, dedup_key');

    $conn->query("CREATE TABLE IF NOT EXISTS notification_preferences (
        user_id INT NOT NULL,
        type VARCHAR(40) NOT NULL,
        delivery_mode VARCHAR(12) NOT NULL DEFAULT 'instant',
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        show_toast TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, type),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS notification_digest_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL,
        body TEXT NULL,
        link_url VARCHAR(255) NULL,
        dedup_key CHAR(40) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        INDEX idx_user_created(user_id, created_at),
        INDEX idx_user_processed(user_id, processed_at),
        INDEX idx_user_dedup(user_id, type, dedup_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS vendors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(160) NOT NULL,
        website VARCHAR(255) NULL,
        contact_name VARCHAR(160) NULL,
        contact_email VARCHAR(255) NULL,
        contact_phone VARCHAR(50) NULL,
        country VARCHAR(120) NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        INDEX idx_active(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('vendors', 'contact_name', "VARCHAR(160) NULL");
    $addColumn('vendors', 'contact_email', "VARCHAR(255) NULL");
    $addColumn('vendors', 'contact_phone', "VARCHAR(50) NULL");
    $addColumn('vendors', 'country', "VARCHAR(120) NULL");
    $addColumn('vendors', 'notes', "TEXT NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS vendor_campaign_map (
        vendor_id INT NOT NULL,
        campaign_id INT NOT NULL,
        vendor_cpl DECIMAL(10,2) NULL,
        vendor_cpl_currency VARCHAR(8) NULL,
        uploads_enabled TINYINT(1) NOT NULL DEFAULT 1,
        assigned_by INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor_id, campaign_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_vendor(vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS client_sdr_map (
        client_id INT NOT NULL,
        sdr_user_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (client_id, sdr_user_id),
        INDEX idx_client(client_id),
        INDEX idx_sdr(sdr_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS vendor_user_map (
        vendor_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor_id, user_id),
        INDEX idx_vendor(vendor_id),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure extra columns in core tables
    $addColumn('campaigns', 'active', "TINYINT(1) NOT NULL DEFAULT 0");
    $addColumn('campaigns', 'owner_id', "INT NULL");
    $addColumn('campaigns', 'created_by', "INT NULL");
    $addColumn('campaigns', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $addColumn('campaigns', 'updated_by', "INT NULL");
    $addColumn('campaigns', 'updated_at', "DATETIME NULL");
    if (!$hasColumn('campaign_details', 'client_code')) {
        $addColumn('campaign_details', 'client_code', "VARCHAR(50) NULL");
    }
    if (!$hasColumn('campaign_details', 'client_id')) {
        $addColumn('campaign_details', 'client_id', "INT NULL");
        $addIndex('campaign_details', 'idx_client_id', 'client_id');
    }
    if (!$hasColumn('campaign_forms', 'assigned_by')) {
        $addColumn('campaign_forms', 'assigned_by', "INT NULL");
    }
    if (!$hasColumn('campaign_forms', 'created_at')) {
        $addColumn('campaign_forms', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    if (!$hasColumn('campaign_delivery_files', 'file_type')) {
        $addColumn('campaign_delivery_files', 'file_type', "VARCHAR(40) NULL");
    }
    if (!$hasColumn('campaign_delivery_files', 'file_name')) {
        $addColumn('campaign_delivery_files', 'file_name', "VARCHAR(180) NULL");
    }
    if (!$hasColumn('campaign_delivery_files', 'uploaded_by')) {
        $addColumn('campaign_delivery_files', 'uploaded_by', "INT NULL");
    }
    if (!$hasColumn('campaign_delivery_files', 'uploaded_at')) {
        $addColumn('campaign_delivery_files', 'uploaded_at', "DATETIME NULL");
    }
    $addColumn('leads', 'qa_status', "VARCHAR(20) DEFAULT 'Pending'");
    $addColumn('leads', 'qa_comment', "TEXT NULL");
    if (!$hasColumn('leads', 'qa_client_comment')) {
        $addColumn('leads', 'qa_client_comment', "TEXT NULL AFTER `qa_comment`");
    }
    $addColumn('leads', 'qa_reviewed_by', "INT NULL");
    $addColumn('leads', 'qa_updated_at', "DATETIME NULL");
    $addColumn('leads', 'form_done', "VARCHAR(3) DEFAULT 'No'");
    $addColumn('leads', 'lead_comment', "TEXT NULL");
    $addColumn('leads', 'created_by', "INT NULL");
    $addColumn('leads', 'updated_by', "INT NULL");
    $addColumn('leads', 'email_status', "VARCHAR(30) NULL");
    $addColumn('leads', 'email_status_comment', "TEXT NULL");
    $addColumn('leads', 'email_status_updated_by', "INT NULL");
    $addColumn('leads', 'email_status_updated_at', "DATETIME NULL");
    $addColumn('leads', 'client_id', "INT NULL");
    $addColumn('leads', 'vendor_id', "INT NULL");
    $addColumn('leads', 'lead_source', "VARCHAR(20) NULL");
    $addColumn('leads', 'assigned_to_user', "INT NULL");

    $ct = $getColumnType('leads', 'qa_status');
    if ($ct && str_contains(strtolower($ct), 'enum(') && (!str_contains($ct, "'Delivered'") || !str_contains($ct, "'Reopened'") || !str_contains($ct, "'Rectified'") || !str_contains($ct, "'Approved'") || !str_contains($ct, "'Rejected'"))) {
        @$conn->query("ALTER TABLE leads MODIFY COLUMN qa_status ENUM('Pending','Reopened','Qualified','Disqualified','Rework Needed','Duplicate','Rectified','Delivered','Approved','Rejected') NULL DEFAULT 'Pending'");
    }
    if (!$hasIndex('leads', 'idx_created_by')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_created_by (created_by)");
    }
    if (!$hasIndex('leads', 'idx_updated_by')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_updated_by (updated_by)");
    }
    if (!$hasIndex('leads', 'idx_email_status')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_email_status (email_status)");
    }
    if (!$hasIndex('leads', 'idx_email_status_updated')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_email_status_updated (email_status_updated_at)");
    }
    if (!$hasIndex('leads', 'idx_client_id')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_client_id (client_id)");
    }
    if (!$hasIndex('leads', 'idx_vendor_id')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_vendor_id (vendor_id)");
    }
    if (!$hasIndex('leads', 'idx_lead_source')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_lead_source (lead_source)");
    }

    // Users table enhancements
    $addColumn('users', 'profile_pic', "VARCHAR(255) NULL");
    $addColumn('users', 'client_id', "INT NULL");
    $addColumn('users', 'vendor_id', "INT NULL");
    $addColumn('users', 'employee_id', "VARCHAR(50) NULL");
    $addColumn('users', 'date_of_birth', "DATE NULL");
    $addColumn('users', 'date_of_joining', "DATE NULL");
    $addColumn('users', 'job_title', "VARCHAR(100) NULL");
    $addColumn('users', 'phone_number', "VARCHAR(20) NULL");
    $addColumn('users', 'department', "VARCHAR(100) NULL");
    $addColumn('users', 'address', "TEXT NULL");
    $addColumn('users', 'emergency_contact', "VARCHAR(100) NULL");
    $addIndex('users', 'employee_id', 'employee_id', true);
    $roleType = $getColumnType('users', 'role');
    if ($roleType && stripos($roleType, 'enum(') === 0) {
        @$conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'agent'");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        website VARCHAR(255) NULL,
        website_domain VARCHAR(255) NULL,
        industry VARCHAR(120) NULL,
        country VARCHAR(120) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_website_domain(website_domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('clients', 'country', "VARCHAR(120) NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS client_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        name VARCHAR(180) NOT NULL,
        email VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        title VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client(client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS client_tags (
        client_id INT NOT NULL,
        tag_id INT NOT NULL,
        PRIMARY KEY (client_id, tag_id),
        INDEX idx_tag(tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_revenue (
        campaign_id INT PRIMARY KEY,
        revenue DECIMAL(12,2) NULL,
        currency VARCHAR(8) NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_manual_expenses (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(80) NOT NULL,
        description TEXT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'INR',
        campaign_id INT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date (expense_date),
        INDEX idx_campaign (campaign_id),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoices (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_no VARCHAR(40) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        issue_date DATE NOT NULL,
        due_date DATE NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        client_id INT NULL,
        client_code VARCHAR(50) NULL,
        client_name VARCHAR(200) NULL,
        bill_to_name VARCHAR(200) NULL,
        bill_to_address TEXT NULL,
        bill_to_contact_name VARCHAR(180) NULL,
        bill_to_contact_email VARCHAR(255) NULL,
        bill_to_contact_phone VARCHAR(50) NULL,
        bill_to_contacts TEXT NULL,
        bill_from_name VARCHAR(200) NULL,
        bill_from_address TEXT NULL,
        bill_from_city_state VARCHAR(200) NULL,
        bill_from_country VARCHAR(120) NULL,
        bill_from_email VARCHAR(255) NULL,
        bill_from_phone VARCHAR(50) NULL,
        bank_name VARCHAR(200) NULL,
        account_name VARCHAR(200) NULL,
        account_number VARCHAR(80) NULL,
        ifsc_code VARCHAR(40) NULL,
        swift_code VARCHAR(40) NULL,
        beneficiary_address TEXT NULL,
        beneficiary_city_state VARCHAR(200) NULL,
        signature_path VARCHAR(255) NULL,
        campaign_id INT NULL,
        month_str VARCHAR(7) NULL,
        notes TEXT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        tax_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
        tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_invoice_no (invoice_no),
        INDEX idx_status (status),
        INDEX idx_issue (issue_date),
        INDEX idx_client (client_id),
        INDEX idx_campaign (campaign_id),
        INDEX idx_month (month_str)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('revenue_invoices', 'bill_to_contact_name', "VARCHAR(180) NULL");
    $addColumn('revenue_invoices', 'bill_to_contact_email', "VARCHAR(255) NULL");
    $addColumn('revenue_invoices', 'bill_to_contact_phone', "VARCHAR(50) NULL");
    $addColumn('revenue_invoices', 'bill_to_contacts', "TEXT NULL");
    $addColumn('revenue_invoices', 'bill_from_name', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'bill_from_address', "TEXT NULL");
    $addColumn('revenue_invoices', 'bill_from_city_state', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'bill_from_country', "VARCHAR(120) NULL");
    $addColumn('revenue_invoices', 'bill_from_email', "VARCHAR(255) NULL");
    $addColumn('revenue_invoices', 'bill_from_phone', "VARCHAR(50) NULL");
    $addColumn('revenue_invoices', 'bank_name', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'account_name', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'account_number', "VARCHAR(80) NULL");
    $addColumn('revenue_invoices', 'ifsc_code', "VARCHAR(40) NULL");
    $addColumn('revenue_invoices', 'swift_code', "VARCHAR(40) NULL");
    $addColumn('revenue_invoices', 'beneficiary_address', "TEXT NULL");
    $addColumn('revenue_invoices', 'beneficiary_city_state', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'signature_path', "VARCHAR(255) NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoice_settings (
        user_id INT NOT NULL PRIMARY KEY,
        bill_from_name VARCHAR(200) NULL,
        bill_from_address TEXT NULL,
        bill_from_city_state VARCHAR(200) NULL,
        bill_from_country VARCHAR(120) NULL,
        bill_from_email VARCHAR(255) NULL,
        bill_from_phone VARCHAR(50) NULL,
        bank_name VARCHAR(200) NULL,
        account_name VARCHAR(200) NULL,
        account_number VARCHAR(80) NULL,
        ifsc_code VARCHAR(40) NULL,
        swift_code VARCHAR(40) NULL,
        beneficiary_address TEXT NULL,
        beneficiary_city_state VARCHAR(200) NULL,
        signature_path VARCHAR(255) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoice_billto_profiles (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        label VARCHAR(120) NOT NULL,
        client_id INT NULL,
        client_code VARCHAR(50) NULL,
        bill_to_name VARCHAR(200) NULL,
        bill_to_address TEXT NULL,
        bill_to_contacts TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_label (user_id, label),
        INDEX idx_user (user_id),
        INDEX idx_client_code (client_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoice_items (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        description VARCHAR(255) NOT NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 1,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_invoice (invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(200) NOT NULL,
        website VARCHAR(255) NULL,
        website_domain VARCHAR(255) NULL,
        industry VARCHAR(120) NULL,
        company_size VARCHAR(60) NULL,
        country VARCHAR(120) NULL,
        contact_name VARCHAR(180) NULL,
        contact_job_title VARCHAR(120) NULL,
        contact_email VARCHAR(255) NULL,
        contact_email_domain VARCHAR(255) NULL,
        contact_phone VARCHAR(50) NULL,
        linkedin_url VARCHAR(255) NULL,
        lead_source VARCHAR(40) NOT NULL DEFAULT 'Manual Outreach',
        status VARCHAR(40) NOT NULL DEFAULT 'New',
        priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
        expected_opportunity_size DECIMAL(12,2) NULL,
        notes TEXT NULL,
        client_id INT NULL,
        owner_id INT NOT NULL,
        sales_manager_id INT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        last_activity_at DATETIME NULL,
        next_follow_up_at DATETIME NULL,
        INDEX idx_status(status),
        INDEX idx_client(client_id),
        INDEX idx_owner(owner_id),
        INDEX idx_manager(sales_manager_id),
        INDEX idx_company(company_name),
        INDEX idx_website_domain(website_domain),
        INDEX idx_email_domain(contact_email_domain),
        INDEX idx_linkedin(linkedin_url),
        INDEX idx_last_activity(last_activity_at),
        INDEX idx_next_followup(next_follow_up_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_lead_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sales_lead_id INT NOT NULL,
        status VARCHAR(40) NULL,
        comment TEXT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        INDEX idx_lead(sales_lead_id),
        INDEX idx_created(created_at),
        INDEX idx_updated(updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_manager_sdr_map (
        id INT AUTO_INCREMENT PRIMARY KEY,
        manager_user_id INT NOT NULL,
        sdr_user_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_manager_sdr (manager_user_id, sdr_user_id),
        INDEX idx_manager(manager_user_id),
        INDEX idx_sdr(sdr_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS fx_rates (
        rate_date DATE PRIMARY KEY,
        usd_inr DECIMAL(10,4) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at(updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        target_new_accounts INT NOT NULL DEFAULT 0,
        target_revenue_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_month (user_id, year, month),
        INDEX idx_year_month (year, month),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_client_ownership (
        client_id INT NOT NULL PRIMARY KEY,
        owner_id INT NOT NULL,
        manager_id INT NULL,
        source_sales_lead_id INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        assigned_by INT NULL,
        INDEX idx_owner(owner_id),
        INDEX idx_manager(manager_id),
        INDEX idx_assigned_at(assigned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("INSERT INTO sales_client_ownership (client_id, owner_id, manager_id, source_sales_lead_id, assigned_at, assigned_by)
        SELECT sl.client_id, sl.owner_id, sl.sales_manager_id, sl.id, COALESCE(sl.updated_at, sl.created_at), sl.updated_by
        FROM sales_leads sl
        LEFT JOIN sales_client_ownership sco ON sco.client_id = sl.client_id
        WHERE sl.client_id IS NOT NULL AND sl.client_id > 0
          AND sco.client_id IS NULL
          AND sl.status = 'Closed Won'");

    // Login attempts table
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        success TINYINT(1) DEFAULT 0,
        user_agent TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$hasColumn('clients', 'website_domain')) {
        @$conn->query("ALTER TABLE clients ADD COLUMN website_domain VARCHAR(255) NULL");
        @$conn->query("ALTER TABLE clients ADD INDEX idx_website_domain (website_domain)");
    }

    if (!$hasColumn('sales_leads', 'client_id')) {
        @$conn->query("ALTER TABLE sales_leads ADD COLUMN client_id INT NULL");
        @$conn->query("ALTER TABLE sales_leads ADD INDEX idx_client (client_id)");
    }

    if (!$hasColumn('sales_leads', 'last_activity_at')) {
        @$conn->query("ALTER TABLE sales_leads ADD COLUMN last_activity_at DATETIME NULL AFTER updated_at");
        @$conn->query("ALTER TABLE sales_leads ADD INDEX idx_last_activity (last_activity_at)");
    }
    if (!$hasColumn('sales_leads', 'next_follow_up_at')) {
        @$conn->query("ALTER TABLE sales_leads ADD COLUMN next_follow_up_at DATETIME NULL AFTER last_activity_at");
        @$conn->query("ALTER TABLE sales_leads ADD INDEX idx_next_followup (next_follow_up_at)");
    }

    if (!$hasColumn('sales_lead_activities', 'updated_by')) {
        @$conn->query("ALTER TABLE sales_lead_activities ADD COLUMN updated_by INT NULL AFTER created_at");
    }
    if (!$hasColumn('sales_lead_activities', 'updated_at')) {
        @$conn->query("ALTER TABLE sales_lead_activities ADD COLUMN updated_at DATETIME NULL AFTER updated_by");
        @$conn->query("ALTER TABLE sales_lead_activities ADD INDEX idx_updated (updated_at)");
    }

    if (!$hasColumn('sales_targets', 'target_new_accounts')) {
        @$conn->query("ALTER TABLE sales_targets ADD COLUMN target_new_accounts INT NOT NULL DEFAULT 0");
    }
    if (!$hasColumn('sales_targets', 'target_revenue_usd')) {
        @$conn->query("ALTER TABLE sales_targets ADD COLUMN target_revenue_usd DECIMAL(12,2) NOT NULL DEFAULT 0");
    }
    if (!$hasColumn('sales_client_ownership', 'assigned_by')) {
        @$conn->query("ALTER TABLE sales_client_ownership ADD COLUMN assigned_by INT NULL AFTER assigned_at");
    }

    // Performance indexes
    $addIndex('users', 'idx_role', 'role');
    $addIndex('users', 'idx_active', 'is_active');
    $addIndex('leads', 'idx_lead_id_search', 'lead_id');
    $addIndex('leads', 'idx_email_search', 'email');
    $addIndex('leads', 'idx_company_search', 'company_name');
    $addIndex('leads', 'idx_name_search', 'first_name, last_name');
    $addIndex('leads', 'idx_qa_queue', 'qa_status, created_at');

    $verified = true;
}

function createNotification(int $userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null): bool {
    $conn = getDbConnection();
    if ($userId <= 0) return false;
    $type = trim($type);
    if ($type === '') $type = 'system';
    $title = trim($title);
    if ($title === '') return false;
    $title = substr($title, 0, 180);
    if ($linkUrl !== null) $linkUrl = substr(trim($linkUrl), 0, 255);

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, body, link_url) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('issss', $userId, $type, $title, $body, $linkUrl);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getNotificationPreference(int $userId, string $type): array {
    $userId = (int)$userId;
    $type = trim($type);
    $defaultToastTypes = [
        'campaign.end_warning' => true,
        'campaign.pacing_risk' => true,
        'sales.followup_reminder' => true,
        'chat.message' => true,
        'chat.group_message' => true,
        'lead.created' => true,
        'lead.updated' => true,
    ];
    $defaultToast = !empty($defaultToastTypes[$type]);
    if ($userId <= 0 || $type === '') return ['enabled' => true, 'mode' => 'instant', 'toast' => $defaultToast];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT delivery_mode, is_enabled, show_toast FROM notification_preferences WHERE user_id = ? AND type = ? LIMIT 1");
    if (!$stmt) return ['enabled' => true, 'mode' => 'instant', 'toast' => $defaultToast];
    $stmt->bind_param('is', $userId, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return ['enabled' => true, 'mode' => 'instant', 'toast' => $defaultToast];
    $enabled = (int)($row['is_enabled'] ?? 1) === 1;
    $mode = (string)($row['delivery_mode'] ?? 'instant');
    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
    $toast = (int)($row['show_toast'] ?? 0) === 1;
    return ['enabled' => $enabled, 'mode' => $mode, 'toast' => $toast];
}

function createNotificationSmart(int $userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null, array $opts = []): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    if ($userId <= 0) return false;
    $type = trim($type);
    if ($type === '') $type = 'system';
    $title = trim($title);
    if ($title === '') return false;
    $title = substr($title, 0, 180);
    if ($linkUrl !== null) $linkUrl = substr(trim($linkUrl), 0, 255);

    $pref = getNotificationPreference($userId, $type);
    if (empty($pref['enabled'])) return false;
    $mode = (string)($pref['mode'] ?? 'instant');

    $importance = trim((string)($opts['importance'] ?? 'normal'));
    if (!in_array($importance, ['low','normal','high'], true)) $importance = 'normal';
    $showToast = !empty($opts['show_toast']) || (!empty($pref['toast']));

    $dedupKey = trim((string)($opts['dedup_key'] ?? ''));
    if ($dedupKey === '') {
        $dedupKey = sha1($type . '|' . $title . '|' . (string)$linkUrl . '|' . (string)$body);
    } else {
        $dedupKey = sha1($type . '|' . $dedupKey);
    }
    $dedupWindowMin = (int)($opts['dedup_window_min'] ?? 10);
    if ($dedupWindowMin < 0) $dedupWindowMin = 0;
    if ($dedupWindowMin > 1440) $dedupWindowMin = 1440;

    if ($dedupWindowMin > 0) {
        $stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = ? AND dedup_key = ? AND created_at >= (NOW() - INTERVAL ? MINUTE) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('issi', $userId, $type, $dedupKey, $dedupWindowMin);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row()[0] ?? null;
            $stmt->close();
            if ($exists) return true;
        }
        $stmt = $conn->prepare("SELECT id FROM notification_digest_queue WHERE user_id = ? AND type = ? AND dedup_key = ? AND processed_at IS NULL AND created_at >= (NOW() - INTERVAL ? MINUTE) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('issi', $userId, $type, $dedupKey, $dedupWindowMin);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row()[0] ?? null;
            $stmt->close();
            if ($exists) return true;
        }
    }

    if ($mode === 'digest') {
        $stmt = $conn->prepare("INSERT INTO notification_digest_queue (user_id, type, title, body, link_url, dedup_key) VALUES (?,?,?,?,?,?)");
        if (!$stmt) return false;
        $stmt->bind_param('isssss', $userId, $type, $title, $body, $linkUrl, $dedupKey);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, body, link_url, dedup_key, importance, show_toast) VALUES (?,?,?,?,?,?,?,?)");
    if (!$stmt) return false;
    $toastVal = $showToast ? 1 : 0;
    $stmt->bind_param('issssssi', $userId, $type, $title, $body, $linkUrl, $dedupKey, $importance, $toastVal);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getUnreadNotificationCount(int $userId): int {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
    $stmt->close();
    return (int)($row['cnt'] ?? 0);
}

function getUnreadToastNotifications(int $userId, int $limit = 3): array {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $limit = max(1, min(5, $limit));
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 AND show_toast = 1 ORDER BY created_at DESC LIMIT " . (int)$limit);
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getUserNotifications(int $userId, int $limit = 12): array {
    $conn = getDbConnection();
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT " . (int)$limit);
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function markNotificationRead(int $userId, int $notificationId): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $notificationId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function markAllNotificationsRead(int $userId): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function notifyUsers(array $userIds, string $type, string $title, string $message, string $link = ''): void {
    $ids = [];
    foreach ($userIds as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[$id] = true;
    }
    if (empty($ids)) return;
    foreach (array_keys($ids) as $uid) {
        createNotificationSmart($uid, $type, $title, $message, $link);
    }
}

function getCampaignAssignedUserIds(int $campaignId, array $tables = ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']): array {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return [];
    $conn = getDbConnection();
    $ids = [];
    foreach ($tables as $t) {
        $t = (string)$t;
        if (!preg_match('/^[a-z_]+$/', $t)) continue;
        $sql = "SELECT DISTINCT user_id FROM {$t} WHERE campaign_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            if ($uid > 0) $ids[$uid] = true;
        }
    }
    return array_keys($ids);
}

function getUserAssignedCampaignIds(int $userId, array $tables = ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']): array {
    static $cache = [];
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    $cacheKey = $userId . '_' . implode(',', $tables);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    
    $conn = getDbConnection();
    $ids = [];
    foreach ($tables as $t) {
        $t = (string)$t;
        if (!preg_match('/^[a-z_]+$/', $t)) continue;
        $sql = "SELECT DISTINCT campaign_id FROM {$t} WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $cid = (int)($r['campaign_id'] ?? 0);
            if ($cid > 0) $ids[$cid] = true;
        }
    }
    $result = array_keys($ids);
    $cache[$cacheKey] = $result;
    return $result;
}

function notifyCampaignEndWarningsForUser(int $userId): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;

    $conn = getDbConnection();
    $role = '';
    $clientId = 0;
    $vendorId = 0;
    $stmtU = $conn->prepare('SELECT role, client_id, vendor_id FROM users WHERE id = ? LIMIT 1');
    if ($stmtU) {
        $stmtU->bind_param('i', $userId);
        $stmtU->execute();
        $u = $stmtU->get_result()->fetch_assoc() ?: [];
        $stmtU->close();
        $role = strtolower((string)($u['role'] ?? ''));
        $clientId = (int)($u['client_id'] ?? 0);
        $vendorId = (int)($u['vendor_id'] ?? 0);
    }

    $campaignIds = [];
    if (str_starts_with($role, 'client_') && $clientId > 0) {
        $stmtC = $conn->prepare('SELECT DISTINCT campaign_id FROM campaign_details WHERE client_id = ?');
        if ($stmtC) {
            $stmtC->bind_param('i', $clientId);
            $stmtC->execute();
            $rows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtC->close();
            foreach ($rows as $r) {
                $cid = (int)($r['campaign_id'] ?? 0);
                if ($cid > 0) $campaignIds[$cid] = true;
            }
        }
    } elseif (str_starts_with($role, 'vendor_') && $vendorId > 0) {
        $stmtV = $conn->prepare('SELECT DISTINCT campaign_id FROM vendor_campaign_map WHERE vendor_id = ?');
        if ($stmtV) {
            $stmtV->bind_param('i', $vendorId);
            $stmtV->execute();
            $rows = $stmtV->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtV->close();
            foreach ($rows as $r) {
                $cid = (int)($r['campaign_id'] ?? 0);
                if ($cid > 0) $campaignIds[$cid] = true;
            }
        }
    } else {
        foreach (getUserAssignedCampaignIds($userId) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) $campaignIds[$cid] = true;
        }
    }

    $campaignIds = array_keys($campaignIds);
    if (empty($campaignIds)) return;
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $types = str_repeat('i', count($campaignIds));
    $stmt = $conn->prepare("
        SELECT c.id, c.name, d.end_date, d.status
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.id IN ($in)
    ");
    if (!$stmt) return;
    $stmt->bind_param($types, ...$campaignIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $cid = (int)($r['id'] ?? 0);
        $endDate = (string)($r['end_date'] ?? '');
        if ($cid <= 0 || $endDate === '') continue;
        $daysLeft = (int)floor((strtotime($endDate) - strtotime($today)) / 86400);
        $bucket = null;
        if ($daysLeft < 0) $bucket = 'overdue';
        elseif ($daysLeft <= 1) $bucket = 'd1';
        elseif ($daysLeft <= 3) $bucket = 'd3';
        elseif ($daysLeft <= 7) $bucket = 'd7';
        if ($bucket === null) continue;

        $campName = (string)($r['name'] ?? '');
        $title = $daysLeft < 0 ? 'Campaign end date passed' : 'Campaign ending soon';
        $msg = ($campName !== '' ? $campName : ('Campaign #' . $cid)) . ' · End: ' . $endDate;
        if ($daysLeft >= 0) $msg .= ' · ' . $daysLeft . ' day(s) left';
        $link = '../campaigns/campaign-details.php?id=' . $cid;
        createNotificationSmart($userId, 'campaign.end_warning', $title, $msg, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'campaign_end:' . $cid . ':' . $bucket,
            'dedup_window_min' => 720,
        ]);
    }
}

function getInternalActiveUserIds(): array {
    $conn = getDbConnection();
    $rows = [];
    $rs = $conn->query("SELECT id FROM users WHERE is_active = 1 AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0)");
    if ($rs) $rows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    return $ids;
}

function notifyCampaignCreated(int $campaignId, int $actorId = 0): void {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $name = trim((string)($row['name'] ?? ''));
    $code = trim((string)($row['code'] ?? ''));
    $status = trim((string)($row['status'] ?? ''));
    $title = 'New campaign created';
    $msg = ($name !== '' ? $name : ('Campaign #' . $campaignId));
    if ($code !== '') $msg .= ' · ' . $code;
    if ($status !== '') $msg .= ' · Status: ' . $status;
    $link = '../campaigns/view?id=' . $campaignId;

    foreach (getInternalActiveUserIds() as $to) {
        if ($to <= 0 || ($actorId > 0 && $to === (int)$actorId)) continue;
        createNotificationSmart($to, 'campaign.created', $title, $msg, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'camp_created:' . $campaignId,
            'dedup_window_min' => 120,
        ]);
    }
}

function notifyCampaignUpdated(int $campaignId, int $actorId = 0, array $meta = []): void {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $name = trim((string)($row['name'] ?? ''));
    $code = trim((string)($row['code'] ?? ''));
    $status = trim((string)($row['status'] ?? ''));
    $title = 'Campaign updated';
    $msg = ($name !== '' ? $name : ('Campaign #' . $campaignId));
    if ($code !== '') $msg .= ' · ' . $code;
    if ($status !== '') $msg .= ' · Status: ' . $status;
    if (!empty($meta['by'])) $msg .= ' · By: ' . (string)$meta['by'];
    $link = '../campaigns/view?id=' . $campaignId;

    foreach (getInternalActiveUserIds() as $to) {
        if ($to <= 0 || ($actorId > 0 && $to === (int)$actorId)) continue;
        createNotificationSmart($to, 'campaign.updated', $title, $msg, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'camp_updated:' . $campaignId,
            'dedup_window_min' => 10,
        ]);
    }
}

function notifyCampaignPacingRiskForUser(int $userId): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $campaignIds = getUserAssignedCampaignIds($userId, ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']);
    if (empty($campaignIds)) return;

    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $types = str_repeat('i', count($campaignIds));
    $stmt = $conn->prepare("
        SELECT c.id, c.name, d.status, d.start_date, d.end_date, d.total_leads, d.pacing_type, d.pacing_count
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.id IN ($in)
    ");
    if (!$stmt) return;
    $stmt->bind_param($types, ...$campaignIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $cid = (int)($r['id'] ?? 0);
        if ($cid <= 0) continue;
        $status = (string)($r['status'] ?? '');
        if (!in_array($status, ['Active','Live'], true)) continue;

        $start = (string)($r['start_date'] ?? '');
        if ($start === '' || strtotime($start) === false) continue;
        if (strtotime($start) > strtotime($today)) continue;

        $end = (string)($r['end_date'] ?? '');
        if ($end !== '' && strtotime($end) !== false && strtotime($end) < strtotime($today)) continue;

        $totalLeads = (int)($r['total_leads'] ?? 0);
        $pacingType = trim((string)($r['pacing_type'] ?? ''));
        $pacingCount = (int)($r['pacing_count'] ?? 0);

        $daysElapsed = (int)floor((strtotime($today) - strtotime($start)) / 86400) + 1;
        if ($daysElapsed < 1) $daysElapsed = 1;

        $expected = 0;
        if ($pacingCount > 0 && $pacingType !== '') {
            if ($pacingType === 'Daily') $expected = $pacingCount * $daysElapsed;
            elseif ($pacingType === 'Weekly') $expected = $pacingCount * (int)max(1, ceil($daysElapsed / 7));
            elseif ($pacingType === 'Monthly') $expected = $pacingCount * (int)max(1, ceil($daysElapsed / 30));
        } elseif ($totalLeads > 0 && $end !== '' && strtotime($end) !== false) {
            $totalDays = (int)floor((strtotime($end) - strtotime($start)) / 86400) + 1;
            if ($totalDays < 1) $totalDays = 1;
            $expected = (int)ceil($totalLeads * min(1, max(0, $daysElapsed / $totalDays)));
        }
        if ($expected <= 0) continue;
        if ($totalLeads > 0) $expected = min($expected, $totalLeads);

        $stmt2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM leads WHERE campaign_id = ? AND client_delivery_status = 'Delivered'");
        if (!$stmt2) continue;
        $stmt2->bind_param('i', $cid);
        $stmt2->execute();
        $delivered = (int)(($stmt2->get_result()->fetch_assoc() ?: [])['cnt'] ?? 0);
        $stmt2->close();

        $threshold = (int)floor($expected * 0.8);
        if ($delivered >= $threshold) continue;

        $campName = (string)($r['name'] ?? '');
        $title = 'Low delivery pacing';
        $msg = ($campName !== '' ? $campName : ('Campaign #' . $cid)) . ' · Delivered: ' . $delivered . ' · Expected: ' . $expected;
        $link = '../campaigns/view?id=' . $cid;
        createNotificationSmart($userId, 'campaign.pacing_risk', $title, $msg, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'pacing:' . $cid . ':' . $today,
            'dedup_window_min' => 1440,
        ]);
    }
}

function notifyLeadUpdated(int $leadDbId, int $actorId = 0, array $changedFields = []): void {
    $leadDbId = (int)$leadDbId;
    if ($leadDbId <= 0) return;
    $lead = getLeadById($leadDbId);
    if (!$lead) return;
    $campaignId = (int)($lead['campaign_id'] ?? 0);

    $recipients = [];
    if ($campaignId > 0) {
        foreach (getCampaignAssignedUserIds($campaignId, ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']) as $uid) {
            if ((int)$uid > 0) $recipients[(int)$uid] = true;
        }
    }
    $agentId = (int)($lead['agent_id'] ?? 0);
    if ($agentId > 0) $recipients[$agentId] = true;
    $assignedTo = (int)($lead['assigned_to_user'] ?? 0);
    if ($assignedTo > 0) $recipients[$assignedTo] = true;
    $createdBy = (int)($lead['created_by'] ?? 0);
    if ($createdBy > 0) $recipients[$createdBy] = true;
    if ($actorId > 0) unset($recipients[(int)$actorId]);
    if (empty($recipients)) return;

    $leadLabel = (string)($lead['lead_id'] ?? ('#' . (string)$leadDbId));
    $campName = (string)($lead['campaign_name'] ?? '');
    $title = 'Lead updated';
    $msg = $leadLabel . ($campName !== '' ? (' · ' . $campName) : '');
    if (!empty($changedFields)) {
        $safe = array_slice(array_values(array_filter(array_map('strval', $changedFields))), 0, 6);
        if (!empty($safe)) $msg .= ' · Fields: ' . implode(', ', $safe);
    }
    $link = '../leads/lead-details.php?id=' . $leadDbId;

    foreach (array_keys($recipients) as $uid) {
        createNotificationSmart((int)$uid, 'lead.updated', $title, $msg, $link, [
            'importance' => 'normal',
            'show_toast' => false,
            'dedup_key' => 'lead_updated:' . $leadDbId . ':' . sha1(implode('|', $changedFields)),
            'dedup_window_min' => 2,
        ]);
    }
}

function notifySalesFollowupRemindersForUser(int $userId): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $isAdminFn = function_exists('isAdmin') && isAdmin();
    $isSalesDirFn = function_exists('isSalesDirector') && isSalesDirector();
    $isSalesMgrFn = function_exists('isSalesManager') && isSalesManager();
    $isSdrFn = function_exists('isSDR') && isSDR();
    if (!($isAdminFn || $isSalesDirFn || $isSalesMgrFn || $isSdrFn)) return;

    $conn = getDbConnection();
    $where = [];
    $params = [];
    $types = '';

    if ($isAdminFn || $isSalesDirFn) {
        $where[] = "1=1";
    } elseif ($isSalesMgrFn) {
        $where[] = "(sl.owner_id = ? OR sl.owner_id IN (SELECT sdr_user_id FROM sales_manager_sdr_map WHERE manager_user_id = ?))";
        $types .= 'ii';
        $params[] = $userId;
        $params[] = $userId;
    } else {
        $where[] = "sl.owner_id = ?";
        $types .= 'i';
        $params[] = $userId;
    }

    $where[] = "sl.next_follow_up_at IS NOT NULL AND sl.next_follow_up_at <> ''";
    $where[] = "sl.status NOT IN ('Closed Won','Closed Lost')";

    $sql = "
        SELECT sl.id, sl.company_name, sl.next_follow_up_at
        FROM sales_leads sl
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sl.next_follow_up_at ASC
        LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $now = time();
    foreach ($rows as $r) {
        $sid = (int)($r['id'] ?? 0);
        $nfa = (string)($r['next_follow_up_at'] ?? '');
        if ($sid <= 0 || $nfa === '' || strtotime($nfa) === false) continue;
        $ts = strtotime($nfa);
        $minLeft = (int)floor(($ts - $now) / 60);

        $bucket = null;
        if ($minLeft <= 60 && $minLeft >= 0) $bucket = 'h1';
        elseif ($minLeft <= 360 && $minLeft > 60) $bucket = 'h6';
        elseif ($minLeft <= 1440 && $minLeft > 360) $bucket = 'd1';
        elseif ($minLeft < 0 && $minLeft >= -120) $bucket = 'overdue';
        if ($bucket === null) continue;

        $company = trim((string)($r['company_name'] ?? ''));
        $title = $bucket === 'overdue' ? 'Follow-up overdue' : 'Follow-up reminder';
        $when = date('d M Y, H:i', $ts);
        $msg = ($company !== '' ? $company : ('Prospect #' . $sid)) . ' · ' . $when;
        $link = '../sales/lead-view.php?id=' . $sid;
        $dedupKey = 'sfup:' . $sid . ':' . $bucket . ':' . sha1($nfa);
        createNotificationSmart($userId, 'sales.followup_reminder', $title, $msg, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => $dedupKey,
            'dedup_window_min' => 180,
        ]);
    }
}

function notifyLeadCreated(int $leadDbId, int $campaignId, int $actorId = 0): void {
    $leadDbId = (int)$leadDbId;
    $campaignId = (int)$campaignId;
    if ($leadDbId <= 0 || $campaignId <= 0) return;
    $lead = getLeadById($leadDbId);
    if (!$lead) return;

    $recipients = [];
    foreach (getCampaignAssignedUserIds($campaignId, ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']) as $uid) {
        if ((int)$uid > 0) $recipients[(int)$uid] = true;
    }
    $agentId = (int)($lead['agent_id'] ?? 0);
    if ($agentId > 0) $recipients[$agentId] = true;
    $assignedTo = (int)($lead['assigned_to_user'] ?? 0);
    if ($assignedTo > 0) $recipients[$assignedTo] = true;
    if ($actorId > 0) unset($recipients[(int)$actorId]);

    $leadLabel = (string)($lead['lead_id'] ?? ('#' . (string)$leadDbId));
    $campName = (string)($lead['campaign_name'] ?? '');
    $title = 'New lead uploaded';
    $msg = $leadLabel . ($campName !== '' ? (' · ' . $campName) : '');
    $link = '../leads/lead-details.php?id=' . $leadDbId;
    notifyUsers(array_keys($recipients), 'lead.created', $title, $msg, $link);
}

function ensureCampaignDetailsSchema(): void {}
function ensureProductivityTargetsSchema(): void {}
function ensureChatSchema(): void {
    $conn = getDbConnection();
    $sql = "CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        group_id INT NULL,
        message TEXT,
        attachment_path VARCHAR(255),
        message_type VARCHAR(16) DEFAULT 'text',
        delivered_at DATETIME NULL,
        read_at DATETIME,
        is_deleted TINYINT(1) DEFAULT 0,
        deleted_by INT NULL,
        deleted_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (sender_id),
        INDEX (receiver_id),
        INDEX (group_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);

    @$conn->query("ALTER TABLE chat_messages ADD COLUMN delivered_at DATETIME NULL AFTER attachment_path");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN group_id INT NULL AFTER receiver_id");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN message_type VARCHAR(16) DEFAULT 'text' AFTER attachment_path");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0 AFTER read_at");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN deleted_by INT NULL AFTER is_deleted");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN deleted_at DATETIME NULL AFTER deleted_by");
    @$conn->query("ALTER TABLE chat_messages ADD INDEX (group_id)");
    @$conn->query("ALTER TABLE chat_messages ADD INDEX (created_at)");

    $sqlGroups = "CREATE TABLE IF NOT EXISTS chat_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(120) NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (created_by),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sqlGroups);

    $sqlMembers = "CREATE TABLE IF NOT EXISTS chat_group_members (
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(16) NOT NULL DEFAULT 'member',
        added_by INT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_read_message_id INT NOT NULL DEFAULT 0,
        PRIMARY KEY (group_id, user_id),
        INDEX (user_id),
        INDEX (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sqlMembers);
    @$conn->query("ALTER TABLE chat_group_members ADD COLUMN last_read_message_id INT NOT NULL DEFAULT 0");
    
    $sqlPresence = "CREATE TABLE IF NOT EXISTS user_presence (
        user_id INT PRIMARY KEY,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_online TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sqlPresence);
}
function ensureCampaignDeliverySchema(): void {}
function ensureCampaignMetricsSchema(): void {
    $conn = getDbConnection();
    $sql = "CREATE TABLE IF NOT EXISTS campaign_metrics (
        campaign_id INT PRIMARY KEY,
        delivered INT DEFAULT 0,
        generated INT DEFAULT 0,
        qualified INT DEFAULT 0,
        disqualified INT DEFAULT 0,
        pending INT DEFAULT 0,
        rejected INT DEFAULT 0,
        updated_by INT,
        updated_at DATETIME,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @$conn->query($sql);
}

function createOrReuseForm(string $name, array $schema, ?int $createdBy): int {
    $conn = getDbConnection();
    $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($schemaJson === false) {
        throw new RuntimeException('Invalid form schema');
    }
    $fingerprint = hash('sha256', $schemaJson);

    $stmt = $conn->prepare("INSERT IGNORE INTO forms (name, fingerprint, schema_json, created_by) VALUES (?, ?, ?, ?)");
    $createdByInt = $createdBy ?? null;
    $stmt->bind_param("sssi", $name, $fingerprint, $schemaJson, $createdByInt);
    $stmt->execute();
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT id FROM forms WHERE fingerprint = ? LIMIT 1");
    $stmt2->bind_param("s", $fingerprint);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if (!$row) {
        throw new RuntimeException('Failed to create or reuse form');
    }
    return (int)$row['id'];
}

function assignFormToCampaign(int $campaignId, int $formId, int $assignedBy = 0): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO campaign_forms (campaign_id, form_id, assigned_by, assigned_at, created_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, NOW())
        ON DUPLICATE KEY UPDATE
            form_id = VALUES(form_id),
            assigned_by = VALUES(assigned_by),
            assigned_at = CURRENT_TIMESTAMP");
    $stmt->bind_param("iii", $campaignId, $formId, $assignedBy);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        ensureCampaignLeadTable($campaignId);
    }
    return $ok;
}

function getFormForCampaign(int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT f.id AS form_id, f.name, f.schema_json
        FROM campaign_forms cf
        JOIN forms f ON cf.form_id = f.id
        WHERE cf.campaign_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $schema = json_decode($row['schema_json'], true);
    if (!is_array($schema)) $schema = ['fields' => []];
    return [
        'form_id' => (int)$row['form_id'],
        'name' => $row['name'],
        'schema' => $schema,
    ];
}

function getSelectOptionsByFormSchema(array $schema, array $keys): array {
    $norm = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        return trim($s, '_');
    };
    $want = [];
    foreach ($keys as $k) {
        $nk = $norm((string)$k);
        if ($nk !== '') $want[$nk] = true;
    }
    if (empty($want)) return [];

    $fields = (array)($schema['fields'] ?? []);
    $out = [];
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $key = $norm((string)($f['key'] ?? ''));
        if ($key === '' || !isset($want[$key])) continue;
        $type = strtolower(trim((string)($f['type'] ?? 'text')));
        if (!in_array($type, ['select','radio','checkbox'], true)) continue;
        $opts = $f['options'] ?? [];
        if (!is_array($opts) || empty($opts)) continue;
        $clean = [];
        $seen = [];
        foreach ($opts as $o) {
            $v = is_array($o) ? (string)($o['value'] ?? ($o['label'] ?? '')) : (string)$o;
            $v = trim($v);
            if ($v === '' || isset($seen[strtolower($v)])) continue;
            $seen[strtolower($v)] = true;
            $clean[] = $v;
        }
        if (!empty($clean)) $out[$key] = $clean;
    }
    return $out;
}

function valueInAllowedOptions(?string $value, array $options): bool {
    $v = trim((string)$value);
    if ($v === '') return true;
    foreach ($options as $o) {
        if (strcasecmp($v, (string)$o) === 0) return true;
    }
    return false;
}

function normalizeSubmissionKey(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

function extractSubmissionValue(array $submissionData, array $aliases) {
    if (empty($submissionData)) return null;
    $index = [];
    foreach ($submissionData as $k => $v) {
        $nk = normalizeSubmissionKey((string)$k);
        if ($nk === '') continue;
        $index[$nk] = $v;
    }
    foreach ($aliases as $a) {
        $a = (string)$a;
        if ($a === '') continue;
        if (array_key_exists($a, $submissionData)) return $submissionData[$a];
        $na = normalizeSubmissionKey($a);
        if ($na !== '' && array_key_exists($na, $index)) return $index[$na];
    }
    return null;
}

function deriveLeadDisplayFieldsFromSubmission(array $lead, ?string $submissionJson): array {
    $data = [];
    if (is_string($submissionJson) && trim($submissionJson) !== '') {
        $tmp = json_decode($submissionJson, true);
        if (is_array($tmp)) $data = $tmp;
    }

    $timeline = extractSubmissionValue($data, [
        'software_implementation_timeline',
        'implementation_timeline',
        'decision_timeline',
        'timeline',
        'cq1',
        'when_is_your_company_planning_to_implement_new_software',
        'when_is_your_company_planning_to_implement_this_solution',
        'when_is_your_company_planning_to_implement_new_software_solution',
    ]);
    if (is_array($timeline)) $timeline = implode(', ', array_map('strval', $timeline));
    $timeline = trim((string)($timeline ?? ''));
    if ($timeline !== '') $lead['software_implementation_timeline'] = $timeline;

    $website = extractSubmissionValue($data, ['company_website', 'website', 'domain', 'company_domain']);
    if (is_array($website)) $website = implode(', ', array_map('strval', $website));
    $website = trim((string)($website ?? ''));
    if ($website === '') $website = trim((string)($lead['company_domain'] ?? ''));
    if ($website !== '') $lead['company_website'] = $website;

    $li = extractSubmissionValue($data, [
        'linkedin_link',
        'linkedin_url',
        'linkedin_profile',
        'prospect_linkedin',
        'prospect_linkedin_link',
        'prospect_linkedin_url',
        'prospect_linkedin_profile',
    ]);
    if (is_array($li)) $li = implode(', ', array_map('strval', $li));
    $li = trim((string)($li ?? ''));
    if ($li !== '') $lead['linkedin_link'] = $li;

    $cli = extractSubmissionValue($data, [
        'company_linkedin',
        'company_linkedin_url',
        'company_linkedin_link',
        'companylinkedin',
        'companylinkedinurl',
    ]);
    if (is_array($cli)) $cli = implode(', ', array_map('strval', $cli));
    $cli = trim((string)($cli ?? ''));
    if ($cli !== '') $lead['company_linkedin'] = $cli;

    return $lead;
}

function getFormById(int $formId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id AS form_id, name, schema_json FROM forms WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $formId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $schema = json_decode($row['schema_json'], true);
    if (!is_array($schema)) $schema = ['fields' => []];
    return [
        'form_id' => (int)$row['form_id'],
        'name' => $row['name'],
        'schema' => $schema,
    ];
}

function getLatestFormSubmissionForLead(int $leadId, int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT id, form_id, submitted_by, submitted_at, data_json
        FROM form_submissions
        WHERE lead_id = ? AND campaign_id = ?
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param("ii", $leadId, $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $data = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($data)) $data = [];
    return [
        'id' => (int)($row['id'] ?? 0),
        'form_id' => (int)($row['form_id'] ?? 0),
        'submitted_by' => (int)($row['submitted_by'] ?? 0),
        'submitted_at' => $row['submitted_at'] ?? null,
        'data' => $data,
    ];
}

function getCampaignAssignedFormId(int $campaignId): ?int {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT form_id FROM campaign_forms WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $fid = (int)($row['form_id'] ?? 0);
    return $fid > 0 ? $fid : null;
}

function setCampaignStatus(int $campaignId, string $status, int $updatedBy = 0): bool {
    $allowed = ['Draft','Active','Live','Pause','Complete'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid status.');
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT status FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Campaign not found.');
    }
    $currentStatus = (string)($row['status'] ?? '');

    if ($status === 'Live' && $currentStatus !== 'Live') {
        $fid = getCampaignAssignedFormId($campaignId);
        if (!$fid) {
            throw new RuntimeException('Assign a lead form before switching status to Live.');
        }
    }

    $active = in_array($status, ['Active','Live'], true) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE campaign_details SET status = ?, updated_at = NOW() WHERE campaign_id = ?");
    $stmt->bind_param('si', $status, $campaignId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Failed to update campaign status.');
    }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE campaigns SET active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('iii', $active, $updatedBy, $campaignId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function saveFormSubmission(int $formId, int $campaignId, ?int $leadId, int $submittedBy, array $data): bool {
    $conn = getDbConnection();
    $norm = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        return trim($s, '_');
    };
    $normalizeDomain = function(string $raw): string {
        $s = trim($raw);
        if ($s === '') return '';
        $s = preg_replace('/^\s*https?:\/\//i', '', $s);
        $s = preg_replace('/^\s*www\./i', '', $s);
        $s = preg_replace('/[\/?#].*$/', '', $s);
        $s = trim($s);
        $s = rtrim($s, '.');
        return $s;
    };
    foreach ($data as $k => $v) {
        if (!is_scalar($v)) continue;
        $nk = $norm((string)$k);
        if (in_array($nk, ['company_website','website','company_site','domain'], true)) {
            $data[$k] = $normalizeDomain((string)$v);
        }
    }
    $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($dataJson === false) $dataJson = '{}';
    $stmt = $conn->prepare("INSERT INTO form_submissions (form_id, campaign_id, lead_id, submitted_by, data_json) VALUES (?, ?, ?, ?, ?)");
    $leadIdInt = $leadId ?? null;
    $stmt->bind_param("iiiis", $formId, $campaignId, $leadIdInt, $submittedBy, $dataJson);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $leadIdInt) {
        $updates = [];
        foreach ($data as $k => $v) {
            $nk = $norm((string)$k);
            $val = is_array($v) ? implode(', ', array_map('strval', $v)) : (is_scalar($v) ? (string)$v : '');
            $val = trim($val);
            if ($val === '') continue;
            if ($nk === 'first_name') $updates['first_name'] = $val;
            elseif ($nk === 'last_name') $updates['last_name'] = $val;
            elseif ($nk === 'email') $updates['email'] = $val;
            elseif ($nk === 'phone' || $nk === 'contact_phone') $updates['contact_phone'] = $val;
            elseif (in_array($nk, ['linkedin','linkedin_link','linkedin_url','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_url'], true)) $updates['linkedin_link'] = $val;
            elseif ($nk === 'company_name') $updates['company_name'] = $val;
            elseif (in_array($nk, ['company_linkedin','company_linkedin_link','company_linkedin_url'], true)) $updates['company_linkedin'] = $val;
            elseif ($nk === 'industry') $updates['industry'] = $val;
            elseif (in_array($nk, ['employee_size','employee_sizes','employees','headcount','company_size'], true)) $updates['company_size'] = $val;
            elseif ($nk === 'country') $updates['country'] = $val;
        }
        if (!empty($updates)) {
            $updates['updated_by'] = $submittedBy;
            updateLead((int)$leadIdInt, $updates);
        }
    }
    return $ok;
}

function saveLeadFieldFile(int $leadId, string $fieldId, array $file): ?string {
    if (empty($file['name']) || empty($file['tmp_name'])) return null;
    if (!is_dir(__DIR__ . '/../uploads/lead_files')) {
        @mkdir(__DIR__ . '/../uploads/lead_files', 0775, true);
    }
    $maxSize = 15 * 1024 * 1024;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ((int)($file['size'] ?? 0) > $maxSize) return null;

    $orig = (string)$file['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','txt','csv','xls','xlsx','png','jpg','jpeg','mp3','wav','m4a'];
    if ($ext !== '' && !in_array($ext, $allowed, true)) return null;

    $safeField = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fieldId);
    $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
    $safeBase = $safeBase !== '' ? $safeBase : 'file';
    $destName = 'L'.$leadId.'_'.$safeField.'_'.time().'_'.$safeBase.($ext ? '.'.$ext : '');
    $destAbs = __DIR__ . '/../uploads/lead_files/'.$destName;
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) return null;
    $relPath = 'uploads/lead_files/'.$destName;

    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO lead_files (lead_id, field_id, file_path, uploaded_at) VALUES (?,?,?,NOW())");
    $stmt->bind_param('iss', $leadId, $fieldId, $relPath);
    $stmt->execute();
    $stmt->close();

    return $relPath;
}

function generateCampaignCode(string $clientCode): string {
    $clientCode = strtoupper(trim((string)$clientCode));
    $clientCode = preg_replace('/[^A-Z0-9]+/', '', $clientCode);
    if ($clientCode === '') {
        throw new RuntimeException('Client code is required to generate campaign code');
    }

    $conn = getDbConnection();
    $prefix = 'TG-' . $clientCode . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT code FROM campaign_details WHERE client_code = ? AND code LIKE ? ORDER BY code DESC LIMIT 2000");
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare campaign code lookup');
    }
    $stmt->bind_param('ss', $clientCode, $like);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $max = 0;
    foreach ($rows as $r) {
        $c = (string)($r['code'] ?? '');
        if ($c === '' || !str_starts_with($c, $prefix)) continue;
        $suffix = substr($c, strlen($prefix));
        if ($suffix === '') continue;
        if (!ctype_digit($suffix)) continue;
        $n = (int)$suffix;
        if ($n > $max) $max = $n;
    }

    $next = $max + 1;
    for ($i = 0; $i < 20000; $i++) {
        $width = max(3, strlen((string)$next));
        $code = $prefix . str_pad((string)$next, $width, '0', STR_PAD_LEFT);
        $chk = $conn->prepare("SELECT 1 FROM campaign_details WHERE code = ? LIMIT 1");
        if (!$chk) return $code;
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = (bool)($chk->get_result()->fetch_row()[0] ?? false);
        $chk->close();
        if (!$exists) return $code;
        $next++;
    }
    throw new RuntimeException('Failed to generate unique campaign code');
}

/**
 * Save campaign files to uploads/campaign_files/* with validation
 */
function saveCampaignFiles(array $files, string $code): array {
    $baseDir = __DIR__ . '/../uploads/campaign_files';
    $dirs = [
        'script' => $baseDir . '/scripts',
        'tal' => $baseDir . '/tal',
        'suppression' => $baseDir . '/suppression',
        'recording' => $baseDir . '/recordings'
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) { @mkdir($d, 0775, true); }
    }

    $saved = ['script_path' => null, 'tal_path' => null, 'suppression_path' => null, 'recording_path' => null];
    $maxSize = 10 * 1024 * 1024;

    // Helper for move_uploaded_file with type check
    $process = function($key, $dir, $allowed, $label) use ($files, $code, $maxSize) {
        if (empty($files[$key]['name'])) return null;
        $ext = strtolower(pathinfo($files[$key]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) throw new RuntimeException("Invalid file type for $label");
        if ($files[$key]['size'] > $maxSize) throw new RuntimeException("$label file too large");
        $target = $dir . "/{$code}_{$key}.$ext";
        if (!move_uploaded_file($files[$key]['tmp_name'], $target)) throw new RuntimeException("Failed to save $label");
        return basename($target);
    };

    try {
        if ($p = $process('script_file', $dirs['script'], ['doc','docx','pdf'], 'Script')) $saved['script_path'] = 'uploads/campaign_files/scripts/'.$p;
        if ($p = $process('tal_file', $dirs['tal'], ['csv','xls','xlsx'], 'TAL')) $saved['tal_path'] = 'uploads/campaign_files/tal/'.$p;
        if ($p = $process('suppression_file', $dirs['suppression'], ['csv','xls','xlsx'], 'Suppression')) $saved['suppression_path'] = 'uploads/campaign_files/suppression/'.$p;
        if ($p = $process('recording_file', $dirs['recording'], ['mp3','wav'], 'Recording')) $saved['recording_path'] = 'uploads/campaign_files/recordings/'.$p;
    } catch (RuntimeException $e) { throw $e; }

    return $saved;
}

function saveCampaignSetupFilesToDb(int $campaignId, array $filePaths, int $uploadedBy): void {
    $conn = getDbConnection();
    $map = [
        'script_path' => 'Script File',
        'tal_path' => 'TAL List',
        'suppression_path' => 'Suppression List',
        'recording_path' => 'Recording',
    ];
    foreach ($map as $k => $type) {
        $path = $filePaths[$k] ?? null;
        if (!$path) continue;
        $fileName = basename((string)$path);
        $uploaderId = $uploadedBy > 0 ? $uploadedBy : 0;
        $stmt = $conn->prepare("INSERT INTO campaign_delivery_files (campaign_id, uploader_id, format, notes, file_path, created_at, file_type, file_name, uploaded_by, uploaded_at)
            VALUES (?, ?, NULL, NULL, ?, NOW(), ?, ?, ?, NOW())");
        if (!$stmt) continue;
        $stmt->bind_param('iisssi', $campaignId, $uploaderId, $path, $type, $fileName, $uploaderId);
        $stmt->execute();
        $stmt->close();
    }
}

function getDeliveryFilesByCampaign(int $campaignId): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT f.*, u.full_name
        FROM campaign_delivery_files f
        LEFT JOIN users u ON u.id = f.uploader_id
        WHERE f.campaign_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $files[] = $row;
        }
    }
    $stmt->close();
    return $files;
}

function saveCampaignDelivery(int $campaignId, ?string $format, string $notes, array $file, int $uploaderId): bool {
    if (empty($file['name']) || empty($file['tmp_name'])) return false;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['csv','xls','xlsx','zip','pdf','json'];
    if ($ext === '' || !in_array($ext, $allowed, true)) return false;

    $baseDir = __DIR__ . '/../uploads/campaign_files/deliveries';
    if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

    $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME));
    $safeBase = $safeBase !== '' ? $safeBase : 'delivery';
    $destName = 'C'.$campaignId.'_'.time().'_'.$safeBase.'.'.$ext;
    $destAbs = $baseDir . '/' . $destName;
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) return false;
    $relPath = 'uploads/campaign_files/deliveries/'.$destName;

    $conn = getDbConnection();
    $fileType = 'Delivery File';
    $fileName = (string)$file['name'];
    $stmt = $conn->prepare("INSERT INTO campaign_delivery_files (campaign_id, file_type, file_name, file_path, uploader_id, format, notes, created_at)
        VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param('isssiss', $campaignId, $fileType, $fileName, $relPath, $uploaderId, $format, $notes);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensureLeadTagsSchema(): void {
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS lead_tags (
        lead_id INT NOT NULL,
        tag_id INT NOT NULL,
        added_by INT NULL,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (lead_id, tag_id),
        INDEX (tag_id),
        FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getLeadTags(int $leadId): array {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT t.id, t.name FROM lead_tags lt JOIN tags t ON lt.tag_id = t.id WHERE lt.lead_id = ? ORDER BY t.name");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLeadTagAssignments(int $leadId): array {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT t.id AS tag_id, t.name AS tag_name, lt.added_at, lt.added_by,
               u.full_name AS added_by_name, u.role AS added_by_role
        FROM lead_tags lt
        JOIN tags t ON t.id = lt.tag_id
        LEFT JOIN users u ON u.id = lt.added_by
        WHERE lt.lead_id = ?
        ORDER BY lt.added_at DESC
    ");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLeadTagTimeline(int $leadId, int $limit = 100): array {
    $conn = getDbConnection();
    $limit = max(1, min(200, $limit));
    $stmt = $conn->prepare("
        SELECT a.id, a.actor_id, a.action, a.meta_json, a.created_at, u.full_name AS actor_name, u.role AS actor_role
        FROM lead_activity a
        LEFT JOIN users u ON u.id = a.actor_id
        WHERE a.lead_id = ? AND a.action IN ('lead_tag_added','lead_tag_removed','lead_tag_edited')
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT $limit
    ");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as &$r) {
        $meta = !empty($r['meta_json']) ? json_decode((string)$r['meta_json'], true) : [];
        if (!is_array($meta)) $meta = [];
        $r['meta'] = $meta;
        unset($r['meta_json']);
    }
    unset($r);
    return $rows;
}

function addTagToLead(int $leadId, string $tagName, int $userId, string $note = '', string $stage = ''): bool {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $tagName = trim($tagName);
    if ($tagName === '') return false;
    $note = trim($note);
    $stage = trim($stage);
    $stmt = $conn->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
    $stmt->bind_param('s', $tagName);
    $stmt->execute();
    $stmt->close();
    $stmt2 = $conn->prepare("SELECT id FROM tags WHERE name = ? LIMIT 1");
    $stmt2->bind_param('s', $tagName);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if (!$row) return false;
    $tagId = (int)$row['id'];
    $stmt3 = $conn->prepare("INSERT IGNORE INTO lead_tags (lead_id, tag_id, added_by) VALUES (?,?,?)");
    $stmt3->bind_param('iii', $leadId, $tagId, $userId);
    $ok = $stmt3->execute();
    $stmt3->close();
    if ($ok) {
        $meta = ['tag' => $tagName];
        if ($note !== '') $meta['note'] = $note;
        if ($stage !== '') $meta['stage'] = $stage;
        logLeadActivity($leadId, $userId, 'lead_tag_added', $meta);
    }
    return $ok;
}

function removeTagFromLead(int $leadId, int $tagId, int $userId): bool {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $tagName = '';
    $stmt0 = $conn->prepare("SELECT name FROM tags WHERE id = ? LIMIT 1");
    $stmt0->bind_param('i', $tagId);
    $stmt0->execute();
    $tagName = (string)($stmt0->get_result()->fetch_row()[0] ?? '');
    $stmt0->close();
    $stmt = $conn->prepare("DELETE FROM lead_tags WHERE lead_id = ? AND tag_id = ?");
    $stmt->bind_param('ii', $leadId, $tagId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $meta = ['tag_id' => $tagId];
        if ($tagName !== '') $meta['tag'] = $tagName;
        logLeadActivity($leadId, $userId, 'lead_tag_removed', $meta);
    }
    return $ok;
}

function getClientUsersByRole(int $clientId, string $role = 'client_sdr'): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE client_id = ? AND role = ? AND is_active = 1 ORDER BY full_name");
    $stmt->bind_param('is', $clientId, $role);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function assignLeadToUser(int $leadId, int $userId, int $assignerId): bool {
    ensureLeadsTrackingColumns();
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE leads SET assigned_to_user = ?, updated_by = ? WHERE id = ?");
    $stmt->bind_param('iii', $userId, $assignerId, $leadId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        logLeadActivity($leadId, $assignerId, 'lead_assigned', ['assigned_to' => $userId]);
    }
    return $ok;
}

function ensureBillingProfilesSchema(): void {
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS client_billing_profiles (
        client_id INT NOT NULL PRIMARY KEY,
        billing_name VARCHAR(180) NULL,
        billing_email VARCHAR(180) NULL,
        billing_phone VARCHAR(40) NULL,
        billing_address TEXT NULL,
        tax_id VARCHAR(120) NULL,
        bank_name VARCHAR(180) NULL,
        bank_account_name VARCHAR(180) NULL,
        bank_account_number VARCHAR(120) NULL,
        bank_ifsc_swift VARCHAR(120) NULL,
        bank_iban VARCHAR(120) NULL,
        notes TEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS vendor_billing_profiles (
        vendor_id INT NOT NULL PRIMARY KEY,
        billing_name VARCHAR(180) NULL,
        billing_email VARCHAR(180) NULL,
        billing_phone VARCHAR(40) NULL,
        billing_address TEXT NULL,
        tax_id VARCHAR(120) NULL,
        bank_name VARCHAR(180) NULL,
        bank_account_name VARCHAR(180) NULL,
        bank_account_number VARCHAR(120) NULL,
        bank_ifsc_swift VARCHAR(120) NULL,
        bank_iban VARCHAR(120) NULL,
        notes TEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getClientBillingProfile(int $clientId): array {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM client_billing_profiles WHERE client_id = ? LIMIT 1");
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function upsertClientBillingProfile(int $clientId, array $data, int $userId): bool {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $billingName = trim((string)($data['billing_name'] ?? ''));
    $billingEmail = trim((string)($data['billing_email'] ?? ''));
    $billingPhone = trim((string)($data['billing_phone'] ?? ''));
    $billingAddress = trim((string)($data['billing_address'] ?? ''));
    $taxId = trim((string)($data['tax_id'] ?? ''));
    $bankName = trim((string)($data['bank_name'] ?? ''));
    $bankAccountName = trim((string)($data['bank_account_name'] ?? ''));
    $bankAccountNumber = trim((string)($data['bank_account_number'] ?? ''));
    $bankIfscSwift = trim((string)($data['bank_ifsc_swift'] ?? ''));
    $bankIban = trim((string)($data['bank_iban'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));

    $stmt = $conn->prepare("
        INSERT INTO client_billing_profiles
        (client_id, billing_name, billing_email, billing_phone, billing_address, tax_id, bank_name, bank_account_name, bank_account_number, bank_ifsc_swift, bank_iban, notes, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
          billing_name=VALUES(billing_name),
          billing_email=VALUES(billing_email),
          billing_phone=VALUES(billing_phone),
          billing_address=VALUES(billing_address),
          tax_id=VALUES(tax_id),
          bank_name=VALUES(bank_name),
          bank_account_name=VALUES(bank_account_name),
          bank_account_number=VALUES(bank_account_number),
          bank_ifsc_swift=VALUES(bank_ifsc_swift),
          bank_iban=VALUES(bank_iban),
          notes=VALUES(notes),
          updated_by=VALUES(updated_by),
          updated_at=NOW()
    ");
    $stmt->bind_param('isssssssssssi', $clientId, $billingName, $billingEmail, $billingPhone, $billingAddress, $taxId, $bankName, $bankAccountName, $bankAccountNumber, $bankIfscSwift, $bankIban, $notes, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getVendorBillingProfile(int $vendorId): array {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM vendor_billing_profiles WHERE vendor_id = ? LIMIT 1");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function upsertVendorBillingProfile(int $vendorId, array $data, int $userId): bool {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $billingName = trim((string)($data['billing_name'] ?? ''));
    $billingEmail = trim((string)($data['billing_email'] ?? ''));
    $billingPhone = trim((string)($data['billing_phone'] ?? ''));
    $billingAddress = trim((string)($data['billing_address'] ?? ''));
    $taxId = trim((string)($data['tax_id'] ?? ''));
    $bankName = trim((string)($data['bank_name'] ?? ''));
    $bankAccountName = trim((string)($data['bank_account_name'] ?? ''));
    $bankAccountNumber = trim((string)($data['bank_account_number'] ?? ''));
    $bankIfscSwift = trim((string)($data['bank_ifsc_swift'] ?? ''));
    $bankIban = trim((string)($data['bank_iban'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));

    $stmt = $conn->prepare("
        INSERT INTO vendor_billing_profiles
        (vendor_id, billing_name, billing_email, billing_phone, billing_address, tax_id, bank_name, bank_account_name, bank_account_number, bank_ifsc_swift, bank_iban, notes, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
          billing_name=VALUES(billing_name),
          billing_email=VALUES(billing_email),
          billing_phone=VALUES(billing_phone),
          billing_address=VALUES(billing_address),
          tax_id=VALUES(tax_id),
          bank_name=VALUES(bank_name),
          bank_account_name=VALUES(bank_account_name),
          bank_account_number=VALUES(bank_account_number),
          bank_ifsc_swift=VALUES(bank_ifsc_swift),
          bank_iban=VALUES(bank_iban),
          notes=VALUES(notes),
          updated_by=VALUES(updated_by),
          updated_at=NOW()
    ");
    $stmt->bind_param('isssssssssssi', $vendorId, $billingName, $billingEmail, $billingPhone, $billingAddress, $taxId, $bankName, $bankAccountName, $bankAccountNumber, $bankIfscSwift, $bankIban, $notes, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function saveCampaignAdditionalFiles(int $campaignId, array $files, array $titles, array $types, array $descriptions, int $uploadedBy): void {
    if (empty($files) || empty($files['name']) || !is_array($files['name'])) return;
    $conn = getDbConnection();
    $baseDir = __DIR__ . '/../uploads/campaign_files/additional';
    if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

    $allowed = ['pdf','doc','docx','txt','csv','xls','xlsx','png','jpg','jpeg'];
    $maxSize = 15 * 1024 * 1024;
    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        $orig = (string)($files['name'][$i] ?? '');
        if (trim($orig) === '') continue;
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        $size = (int)($files['size'][$i] ?? 0);
        $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) continue;
        if ($size > $maxSize) continue;
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;

        $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
        $safeBase = $safeBase !== '' ? $safeBase : 'file';
        $destName = 'C'.$campaignId.'_'.time().'_'.$i.'_'.$safeBase.'.'.$ext;
        $destAbs = $baseDir . '/' . $destName;
        if (!move_uploaded_file($tmp, $destAbs)) continue;
        $relPath = 'uploads/campaign_files/additional/'.$destName;

        $title = trim((string)($titles[$i] ?? ''));
        if ($title === '') $title = $orig;
        $tag = trim((string)($types[$i] ?? ''));
        $desc = trim((string)($descriptions[$i] ?? ''));

        $stmt = $conn->prepare("INSERT INTO campaign_additional_files (campaign_id, file_title, file_path, file_type, description, uploaded_by, created_at)
            VALUES (?,?,?,?,?,?,NOW())");
        if (!$stmt) continue;
        $stmt->bind_param('issssi', $campaignId, $title, $relPath, $tag, $desc, $uploadedBy);
        $stmt->execute();
        $stmt->close();
    }
}

function getCampaignCreateFormOptionValues(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $path = __DIR__ . '/../modules/campaigns/campaign-form-options-source.php';
    $src = @file_get_contents($path);
    if (!is_string($src) || $src === '') {
        $cache = [
            'delivery_format' => ['Internal CRM','Client CRM','CSV','XLSX','Other'],
            'status' => ['Draft','Active','Pause','Complete','Live'],
            'campaign_type' => ['Email Marketing','MQL','SQL','HQL','BANT','Call Back','Webinar - Live','Webinar - OnDemand','Appointment Generation','ABM Lead'],
            'pacing_type' => ['Daily','Weekly','Monthly'],
            'targeted_country' => [],
            'departments' => [],
            'seniority_levels' => [],
            'employee_sizes' => [],
            'industries' => [],
            'revenue_sizes' => [],
        ];
        return $cache;
    }

    $extract = function(string $id) use ($src): array {
        $re = '/id="' . preg_quote($id, '/') . '"[^>]*>([\s\S]*?)<\/select>/';
        if (!preg_match($re, $src, $m)) return [];
        preg_match_all('/<option\s+value="([^"]*)"/', $m[1], $mm);
        $vals = array_values(array_filter(array_map('trim', $mm[1] ?? []), fn($v) => $v !== ''));
        $seen = [];
        $out = [];
        foreach ($vals as $v) {
            if (isset($seen[$v])) continue;
            $seen[$v] = true;
            $out[] = $v;
        }
        return $out;
    };

    $cache = [
        'delivery_format' => ['Internal CRM','Client CRM','CSV','XLSX','Other'],
        'status' => ['Draft','Active','Pause','Complete','Live'],
        'campaign_type' => ['Email Marketing','MQL','SQL','HQL','BANT','Call Back','Webinar - Live','Webinar - OnDemand','Appointment Generation','ABM Lead'],
        'pacing_type' => ['Daily','Weekly','Monthly'],
        'targeted_country' => $extract('geoSel'),
        'departments' => $extract('deptSel'),
        'seniority_levels' => $extract('levelSel'),
        'employee_sizes' => $extract('empSel'),
        'industries' => $extract('indSel'),
        'revenue_sizes' => $extract('revSel'),
    ];
    return $cache;
}

/**
 * Create campaign with details and files
 */
function createCampaignWithDetails(array $basic, array $criteria, array $customFields, array $files): int {
    $conn = getDbConnection();
    $st = $basic['status'] ?? 'Draft';
    if ($st === 'Live') {
        throw new RuntimeException('Assign a lead form before switching status to Live.');
    }
    $active = (in_array($st, ['Active','Live'], true)) ? 1 : 0;
    $ownerId = isset($basic['owner_id']) ? (int)$basic['owner_id'] : null;
    $createdBy = isset($basic['created_by']) ? (int)$basic['created_by'] : null;
    $stmt = $conn->prepare("INSERT INTO campaigns (name, active, owner_id, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('siii', $basic['name'], $active, $ownerId, $createdBy);
    if (!$stmt->execute()) { throw new RuntimeException('Failed to create campaign'); }
    $campaignId = (int)$conn->insert_id;
    $stmt->close();

    $clientCode = ($basic['client_code'] ?? '') !== '' ? trim((string)$basic['client_code']) : '';
    $code = generateCampaignCode($clientCode);
    // Save files
    $filePaths = saveCampaignFiles($files, $code);
    $customJson = !empty($customFields) ? json_encode($customFields, JSON_UNESCAPED_UNICODE) : null;
    $toJson = function($v) {
        if ($v === null) return null;
        if (is_array($v)) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE);
            return $j === false ? '[]' : $j;
        }
        return $v;
    };

    $sql = "INSERT INTO campaign_details (
                campaign_id, code, client_id, client_code, status, start_date, end_date, total_leads,
                pacing_type, pacing_count, cpc, cpl, cpl_currency, campaign_type, delivery_format, targeted_country,
                job_title, departments, seniority_levels, industries, employee_sizes,
                revenue_sizes, instruction, script_path, tal_path, suppression_path, recording_path,
                custom_fields_json, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";
    $ins = $conn->prepare($sql);
    if (!$ins) { throw new RuntimeException('Failed to save campaign details'); }

    $startDate = ($basic['start_date'] ?? '') !== '' ? $basic['start_date'] : null;
    $endDate = ($basic['end_date'] ?? '') !== '' ? $basic['end_date'] : null;
    $totalLeads = ($basic['total_leads'] ?? '') !== '' ? (int)$basic['total_leads'] : null;
    $pacingCount = ($basic['pacing_count'] ?? '') !== '' ? (int)$basic['pacing_count'] : null;
    $cpc = ($basic['cpc'] ?? '') !== '' ? (string)$basic['cpc'] : null;
    $cpl = ($basic['cpl'] ?? '') !== '' ? (string)$basic['cpl'] : null;
    $clientCode = ($basic['client_code'] ?? '') !== '' ? trim((string)$basic['client_code']) : null;
    $clientId = ($basic['client_id'] ?? '') !== '' ? (int)$basic['client_id'] : null;

    $targetedCountryJson = $toJson($criteria['targeted_country'] ?? []);
    $departmentsJson = $toJson($criteria['departments'] ?? []);
    $levelsJson = $toJson($criteria['seniority_levels'] ?? []);
    $industriesJson = $toJson($criteria['industries'] ?? []);
    $employeeSizesJson = $toJson($criteria['employee_sizes'] ?? []);
    $revenueSizesJson = $toJson($criteria['revenue_sizes'] ?? []);

    $params = [
        $campaignId,
        $code,
        $clientId,
        $clientCode,
        $st,
        $startDate,
        $endDate,
        $totalLeads,
        $basic['pacing_type'],
        $pacingCount,
        $cpc,
        $cpl,
        $basic['cpl_currency'],
        $basic['campaign_type'],
        $basic['delivery_format'],
        $targetedCountryJson,
        $criteria['job_title'],
        $departmentsJson,
        $levelsJson,
        $industriesJson,
        $employeeSizesJson,
        $revenueSizesJson,
        $basic['instruction'],
        $filePaths['script_path'],
        $filePaths['tal_path'],
        $filePaths['suppression_path'],
        $filePaths['recording_path'],
        $customJson
    ];
    $ins->bind_param(str_repeat('s', count($params)), ...$params);
    if (!$ins->execute()) { throw new RuntimeException('Failed to save campaign details'); }
    $ins->close();

    $uploadedBy = $createdBy ?: ($ownerId ?: 0);
    saveCampaignSetupFilesToDb($campaignId, $filePaths, $uploadedBy);

    if (function_exists('notifyCampaignCreated')) {
        notifyCampaignCreated($campaignId, (int)($createdBy ?: 0));
    }
    return $campaignId;
}

function updateCampaignDetails(int $campaignId, array $basic, array $criteria, array $customFields, array $files): bool {
    $conn = getDbConnection();
    $current = getCampaignDetailsById($campaignId);
    if (!$current) { throw new RuntimeException('Campaign not found'); }

    $st = $basic['status'] ?? ($current['status'] ?? 'Draft');
    if ($st === 'Live' && ($current['status'] ?? '') !== 'Live') {
        $fid = getCampaignAssignedFormId($campaignId);
        if (!$fid) {
            throw new RuntimeException('Assign a lead form before switching status to Live.');
        }
    }
    $active = (in_array($st, ['Active','Live'], true)) ? 1 : 0;
    $updatedBy = isset($basic['updated_by']) ? (int)$basic['updated_by'] : null;
    $stmt = $conn->prepare("UPDATE campaigns SET name = ?, active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('siii', $basic['name'], $active, $updatedBy, $campaignId);
    if (!$stmt->execute()) { throw new RuntimeException('Failed to update campaign'); }
    $stmt->close();

    $code = (string)($current['code'] ?? '');
    if ($code === '') { throw new RuntimeException('Campaign code missing'); }

    $filePaths = saveCampaignFiles($files, $code);
    $mergedFiles = [
        'script_path' => $filePaths['script_path'] ?: ($current['script_path'] ?? null),
        'tal_path' => $filePaths['tal_path'] ?: ($current['tal_path'] ?? null),
        'suppression_path' => $filePaths['suppression_path'] ?: ($current['suppression_path'] ?? null),
        'recording_path' => $filePaths['recording_path'] ?: ($current['recording_path'] ?? null),
    ];
    $customJson = !empty($customFields) ? json_encode($customFields, JSON_UNESCAPED_UNICODE) : (is_string($current['custom_fields_json'] ?? null) ? $current['custom_fields_json'] : json_encode($current['custom_fields_json'] ?? []));

    $toJson = function($v) {
        if ($v === null) return null;
        if (is_array($v)) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE);
            return $j === false ? '[]' : $j;
        }
        return $v;
    };

    $startDate = ($basic['start_date'] ?? '') !== '' ? $basic['start_date'] : null;
    $endDate = ($basic['end_date'] ?? '') !== '' ? $basic['end_date'] : null;
    $totalLeads = ($basic['total_leads'] ?? '') !== '' ? (int)$basic['total_leads'] : null;
    $pacingCount = ($basic['pacing_count'] ?? '') !== '' ? (int)$basic['pacing_count'] : null;
    $cpc = ($basic['cpc'] ?? '') !== '' ? (string)$basic['cpc'] : ($current['cpc'] ?? null);
    $cpl = ($basic['cpl'] ?? '') !== '' ? (string)$basic['cpl'] : ($current['cpl'] ?? null);
    $clientCode = ($basic['client_code'] ?? '') !== '' ? trim((string)$basic['client_code']) : ($current['client_code'] ?? null);
    $clientId = ($basic['client_id'] ?? '') !== '' ? (int)$basic['client_id'] : ($current['client_id'] ?? null);

    $targetedCountryJson = $toJson($criteria['targeted_country'] ?? ($current['targeted_country'] ?? []));
    $departmentsJson = $toJson($criteria['departments'] ?? ($current['departments'] ?? []));
    $levelsJson = $toJson($criteria['seniority_levels'] ?? ($current['seniority_levels'] ?? []));
    $industriesJson = $toJson($criteria['industries'] ?? ($current['industries'] ?? []));
    $employeeSizesJson = $toJson($criteria['employee_sizes'] ?? ($current['employee_sizes'] ?? []));
    $revenueSizesJson = $toJson($criteria['revenue_sizes'] ?? ($current['revenue_sizes'] ?? []));

    $sql = "UPDATE campaign_details SET
                client_id = ?,
                client_code = ?,
                status = ?,
                start_date = ?,
                end_date = ?,
                total_leads = ?,
                pacing_type = ?,
                pacing_count = ?,
                cpc = ?,
                cpl = ?,
                cpl_currency = ?,
                campaign_type = ?,
                delivery_format = ?,
                targeted_country = ?,
                job_title = ?,
                departments = ?,
                seniority_levels = ?,
                industries = ?,
                employee_sizes = ?,
                revenue_sizes = ?,
                instruction = ?,
                script_path = ?,
                tal_path = ?,
                suppression_path = ?,
                recording_path = ?,
                custom_fields_json = ?,
                updated_at = NOW()
            WHERE campaign_id = ?";
    $upd = $conn->prepare($sql);
    if (!$upd) { throw new RuntimeException('Failed to update campaign details'); }
    $params = [
        $clientId,
        $clientCode,
        $st,
        $startDate,
        $endDate,
        $totalLeads,
        $basic['pacing_type'],
        $pacingCount,
        $cpc,
        $cpl,
        $basic['cpl_currency'],
        $basic['campaign_type'],
        $basic['delivery_format'],
        $targetedCountryJson,
        $criteria['job_title'],
        $departmentsJson,
        $levelsJson,
        $industriesJson,
        $employeeSizesJson,
        $revenueSizesJson,
        $basic['instruction'],
        $mergedFiles['script_path'],
        $mergedFiles['tal_path'],
        $mergedFiles['suppression_path'],
        $mergedFiles['recording_path'],
        $customJson,
        $campaignId
    ];
    $upd->bind_param(str_repeat('s', count($params)), ...$params);
    if (!$upd->execute()) { throw new RuntimeException('Failed to update campaign details'); }
    $upd->close();

    $uploadedBy = $updatedBy ?: 0;
    saveCampaignSetupFilesToDb($campaignId, $filePaths, $uploadedBy);

    if (function_exists('notifyCampaignUpdated')) {
        notifyCampaignUpdated($campaignId, (int)($updatedBy ?: 0), []);
    }
    return true;
}

/**
 * Fetch campaign and details by ID
 */
function getCampaignDetailsById(int $campaignId): ?array {
    $conn = getDbConnection();
    $sql = 'SELECT c.id, c.name, c.active, d.* FROM campaigns c LEFT JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    $jsonFields = ['targeted_country','departments','seniority_levels','industries','employee_sizes','revenue_sizes','custom_fields_json'];
    foreach ($jsonFields as $f) {
        if (!empty($row[$f])) {
            $dec = json_decode($row[$f], true);
            if (json_last_error() === JSON_ERROR_NONE) $row[$f] = $dec;
        }
    }
    return $row;
}

/**
 * Get stats for Agent Dashboard
 */
function getAgentDashboardStats(int $userId): array {
    $conn = getDbConnection();
    $sql = "
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) as disqualified_leads,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) as pending_leads,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_leads,
            SUM(CASE WHEN DATE(created_at) = CURDATE() AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as today_qualified,
            SUM(CASE WHEN DATE(created_at) = CURDATE() AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as today_disqualified
        FROM leads
        WHERE agent_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [
        'total_leads' => 0,
        'qualified_leads' => 0,
        'disqualified_leads' => 0,
        'pending_leads' => 0,
        'today_leads' => 0,
        'today_qualified' => 0,
        'today_disqualified' => 0,
    ];
}

/**
 * Get stats for Form Filler Dashboard
 */
function getFormFillerDashboardStats(string $todayStart, string $todayEnd, string $monthStart, string $monthEnd, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    if (is_array($campaignIds) && empty($campaignIds)) {
        return [
            'total_leads' => 0,
            'filled_forms' => 0,
            'pending_form_filling' => 0,
            'qa_pending' => 0,
            'qualified_leads' => 0,
            'disqualified_leads' => 0,
            'today_total' => 0,
            'today_filled' => 0,
            'today_pass' => 0,
            'today_fail' => 0,
            'today_pending' => 0,
            'month_total' => 0,
            'month_filled' => 0,
            'month_pass' => 0,
            'month_fail' => 0,
            'month_pending' => 0,
        ];
    }
    $whereSql = '';
    $typesPrefix = '';
    $paramsPrefix = [];
    if (is_array($campaignIds) && !empty($campaignIds)) {
        $campaignIds = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($campaignIds)) return [];
        $in = implode(',', array_fill(0, count($campaignIds), '?'));
        $whereSql = "WHERE campaign_id IN ($in)";
        $typesPrefix = str_repeat('i', count($campaignIds));
        $paramsPrefix = $campaignIds;
    }
    $sql = "
        SELECT 
            COUNT(*) AS total_leads,
            SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) AS filled_forms,
            SUM(CASE WHEN (form_done = 'No') AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS pending_form_filling,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) AS qa_pending,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified_leads,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS today_total,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND form_done = 'Yes' THEN 1 ELSE 0 END) AS today_filled,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS today_pass,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS today_fail,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND (qa_status IS NULL OR qa_status IN ('Pending','Reopened')) THEN 1 ELSE 0 END) AS today_pending,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS month_total,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND form_done = 'Yes' THEN 1 ELSE 0 END) AS month_filled,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS month_pass,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS month_fail,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND (qa_status IS NULL OR qa_status = 'Pending') THEN 1 ELSE 0 END) AS month_pending
        FROM leads
        $whereSql
    ";
    $stmt = $conn->prepare($sql);
    $bind = array_merge($paramsPrefix, [
        $todayStart, $todayEnd,
        $todayStart, $todayEnd,
        $todayStart, $todayEnd,
        $todayStart, $todayEnd,
        $todayStart, $todayEnd,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
    ]);
    $stmt->bind_param($typesPrefix . 'ssssssssssssssssssss', ...$bind);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();
    return $row ?: [];
}

function normalizePreviewUrl(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('/^https?:\\/\\//i', $url)) return $url;
    if (str_starts_with($url, '//')) return 'https:' . $url;
    return 'https://' . $url;
}

function isSafeExternalUrl(string $url): bool {
    $parts = @parse_url($url);
    if (!is_array($parts)) return false;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http','https'], true)) return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost') return false;
    if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;

    $ips = @gethostbynamel($host);
    if (!$ips || !is_array($ips)) return false;
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function resolveUrl(string $baseUrl, string $maybeRelative): string {
    $maybeRelative = trim($maybeRelative);
    if ($maybeRelative === '') return '';
    if (preg_match('/^https?:\\/\\//i', $maybeRelative)) return $maybeRelative;
    if (str_starts_with($maybeRelative, '//')) {
        $p = parse_url($baseUrl);
        $scheme = (string)($p['scheme'] ?? 'https');
        return $scheme . ':' . $maybeRelative;
    }

    $b = parse_url($baseUrl);
    $scheme = (string)($b['scheme'] ?? 'https');
    $host = (string)($b['host'] ?? '');
    $port = isset($b['port']) ? (':' . (int)$b['port']) : '';
    $origin = $scheme . '://' . $host . $port;

    if (str_starts_with($maybeRelative, '/')) return $origin . $maybeRelative;
    $path = (string)($b['path'] ?? '/');
    $dir = preg_replace('~/[^/]*$~', '/', $path);
    return $origin . $dir . $maybeRelative;
}

function parseHtmlPreviewMeta(string $html, string $baseUrl): array {
    $getMeta = function(\DOMXPath $xp, string $q): ?string {
        $n = $xp->query($q);
        if (!$n || $n->length === 0) return null;
        $node = $n->item(0);
        if (!$node || !($node instanceof DOMElement)) return null;
        $v = trim((string)($node->getAttribute('content') ?? ''));
        return $v !== '' ? $v : null;
    };

    $title = null;
    $desc = null;
    $img = null;

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($dom);

    $title = $getMeta($xp, "//meta[@property='og:title']") ?? $getMeta($xp, "//meta[@name='twitter:title']");
    if (!$title) {
        $t = $xp->query('//title');
        if ($t && $t->length > 0) $title = trim((string)($t->item(0)->textContent ?? '')) ?: null;
    }

    $desc = $getMeta($xp, "//meta[@property='og:description']") ?? $getMeta($xp, "//meta[@name='twitter:description']") ?? $getMeta($xp, "//meta[@name='description']");
    $img = $getMeta($xp, "//meta[@property='og:image']") ?? $getMeta($xp, "//meta[@name='twitter:image']");
    if ($img) $img = resolveUrl($baseUrl, $img);

    if ($title !== null) $title = (function_exists('mb_substr') ? mb_substr($title, 0, 255) : substr($title, 0, 255));
    if ($desc !== null) $desc = (function_exists('mb_substr') ? mb_substr($desc, 0, 400) : substr($desc, 0, 400));

    return [
        'preview_title' => $title,
        'preview_description' => $desc,
        'preview_image' => $img,
    ];
}

function fetchUrlPreviewMeta(string $url): array {
    $out = [
        'preview_title' => null,
        'preview_description' => null,
        'preview_image' => null,
        'preview_url' => $url,
        'fetch_status' => 'error',
        'http_status' => null,
        'last_error' => null,
    ];

    if (!isSafeExternalUrl($url)) {
        $out['fetch_status'] = 'blocked';
        return $out;
    }

    $body = '';
    $effective = $url;
    $http = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'DemandFlowBridgeBot/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if (curl_errno($ch)) $out['last_error'] = curl_error($ch);
        unset($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'header' => "User-Agent: DemandFlowBridgeBot/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
            ],
        ]);
        $body = (string)@file_get_contents($url, false, $ctx);
        $http = null;
        $effective = $url;
    }

    $out['http_status'] = $http;
    $out['preview_url'] = $effective ?: $url;

    if ($body === '' || ($http !== null && ($http < 200 || $http >= 400))) {
        $out['fetch_status'] = 'error';
        if (!$out['last_error']) $out['last_error'] = $http ? ('HTTP ' . $http) : 'Empty response';
        return $out;
    }

    $meta = parseHtmlPreviewMeta($body, $out['preview_url']);
    $out['preview_title'] = $meta['preview_title'];
    $out['preview_description'] = $meta['preview_description'];
    $out['preview_image'] = $meta['preview_image'];
    $out['fetch_status'] = 'ok';
    return $out;
}

function getUrlPreviewCached(string $url): array {
    $conn = getDbConnection();
    static $tableReady = false;
    if (!$tableReady) {
        @$conn->query("CREATE TABLE IF NOT EXISTS url_previews (
            url_hash CHAR(64) PRIMARY KEY,
            url TEXT NOT NULL,
            final_url TEXT NULL,
            preview_title VARCHAR(255) NULL,
            preview_description TEXT NULL,
            preview_image VARCHAR(512) NULL,
            fetch_status VARCHAR(20) NOT NULL DEFAULT 'ok',
            http_status INT NULL,
            last_error VARCHAR(255) NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fetched(fetched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $tableReady = true;
    }
    $url = normalizePreviewUrl($url);
    if ($url === '') return [];

    $hash = hash('sha256', $url);
    $stmt = $conn->prepare("SELECT * FROM url_previews WHERE url_hash = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            $ts = strtotime((string)($row['fetched_at'] ?? '')) ?: 0;
            $age = time() - $ts;
            $ttl = ((string)($row['fetch_status'] ?? 'ok')) === 'ok' ? (7 * 86400) : (86400);
            if ($age >= 0 && $age < $ttl) {
                return [
                    'preview_title' => $row['preview_title'] ?? null,
                    'preview_description' => $row['preview_description'] ?? null,
                    'preview_image' => $row['preview_image'] ?? null,
                    'preview_url' => $row['final_url'] ?: ($row['url'] ?? $url),
                    'fetch_status' => $row['fetch_status'] ?? 'ok',
                ];
            }
        }
    }

    $meta = fetchUrlPreviewMeta($url);
    $stmt2 = $conn->prepare("INSERT INTO url_previews (url_hash, url, final_url, preview_title, preview_description, preview_image, fetch_status, http_status, last_error, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          url = VALUES(url),
          final_url = VALUES(final_url),
          preview_title = VALUES(preview_title),
          preview_description = VALUES(preview_description),
          preview_image = VALUES(preview_image),
          fetch_status = VALUES(fetch_status),
          http_status = VALUES(http_status),
          last_error = VALUES(last_error),
          fetched_at = NOW()");
    if ($stmt2) {
        $finalUrl = (string)($meta['preview_url'] ?? $url);
        $pt = $meta['preview_title'];
        $pd = $meta['preview_description'];
        $pi = $meta['preview_image'];
        $fs = (string)($meta['fetch_status'] ?? 'error');
        $hs = $meta['http_status'] !== null ? (int)$meta['http_status'] : 0;
        $le = $meta['last_error'];
        $stmt2->bind_param('sssssssis', $hash, $url, $finalUrl, $pt, $pd, $pi, $fs, $hs, $le);
        $stmt2->execute();
        $stmt2->close();
    }

    return $meta;
}

function renderUrlPreviewCard(string $url, string $fallbackTitle, string $fallbackLabel): string {
    $url = normalizePreviewUrl($url);
    if ($url === '') return '';
    $meta = getUrlPreviewCached($url);
    $title = trim((string)($meta['preview_title'] ?? ''));
    $desc = trim((string)($meta['preview_description'] ?? ''));
    $img = trim((string)($meta['preview_image'] ?? ''));
    $final = trim((string)($meta['preview_url'] ?? $url));
    if ($title === '') $title = $fallbackTitle;
    $showDesc = $desc !== '';

    $icon = '<div class="bg-light border rounded d-flex align-items-center justify-content-center" style="width:56px;height:56px;"><i class="bi bi-link-45deg"></i></div>';
    $thumb = $img !== '' ? '<img src="'.htmlspecialchars($img).'" class="rounded border" style="width:56px;height:56px;object-fit:cover;" alt="">' : $icon;

    $html = '<div class="card border-0 shadow-sm mt-2">'
        .'<div class="card-body p-2">'
        .'<div class="d-flex gap-2 align-items-start">'
        .'<div class="flex-shrink-0">'.$thumb.'</div>'
        .'<div class="flex-grow-1" style="min-width:0;">'
        .'<div class="fw-semibold small text-truncate">'.htmlspecialchars($title).'</div>';
    if ($showDesc) {
        $html .= '<div class="text-muted small" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">'.htmlspecialchars($desc).'</div>';
    }
    $html .= '<a href="'.htmlspecialchars($final).'" target="_blank" rel="noopener" class="small text-decoration-none">'.$fallbackLabel.'</a>'
        .'<div class="text-muted small text-truncate">'.htmlspecialchars($final).'</div>'
        .'</div></div></div></div>';
    return $html;
}

/**
 * Get counts of campaigns for each status tab
 */
function getCampaignTabCounts($dateFrom = null, $dateTo = null, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if ($dateFrom) { $where[] = "start_date >= ?"; $params[] = $dateFrom; $types .= "s"; }
    if ($dateTo) { $where[] = "end_date <= ?"; $params[] = $dateTo; $types .= "s"; }
    if (is_array($campaignIds)) {
        $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($ids)) {
            return ['Draft' => 0, 'Active' => 0, 'Live' => 0, 'Pause' => 0, 'Complete' => 0, 'All' => 0];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "campaign_id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= "i"; }
    }
    
    $whereSql = implode(" AND ", $where);
    
    $sql = "SELECT status, COUNT(*) as count FROM campaign_details WHERE $whereSql GROUP BY status";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['Draft' => 0, 'Active' => 0, 'Live' => 0, 'Pause' => 0, 'Complete' => 0, 'All' => 0];
    }
    if ($types) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $counts = [
        'Draft' => 0,
        'Active' => 0,
        'Live' => 0,
        'Pause' => 0,
        'Complete' => 0,
        'All' => 0,
    ];
    
    $total = 0;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $status = $row['status'];
            $count = (int)$row['count'];
            
            if ($status === 'Draft') $counts['Draft'] += $count;
            elseif ($status === 'Active') $counts['Active'] += $count;
            elseif ($status === 'Live') $counts['Live'] += $count;
            elseif ($status === 'Pause' || $status === 'Paused') $counts['Pause'] += $count;
            elseif ($status === 'Complete' || $status === 'Completed') $counts['Complete'] += $count;
            
            $total += $count;
        }
    }
    $stmt->close();
    $counts['All'] = $total;
    
    return $counts;
}

/**
 * Get detailed overview of campaigns by status
 */
function getCampaignOverviewByStatus($status = null, $dateFrom = null, $dateTo = null, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if ($status && $status !== 'All') {
        $where[] = "d.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    if ($dateFrom) { $where[] = "d.start_date >= ?"; $params[] = $dateFrom; $types .= "s"; }
    if ($dateTo) { $where[] = "d.end_date <= ?"; $params[] = $dateTo; $types .= "s"; }
    if (is_array($campaignIds)) {
        $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($ids)) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "c.id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= "i"; }
    }
    
    $whereSql = implode(" AND ", $where);
    
    $sql = "
        SELECT 
            c.id,
            c.name,
            c.owner_id,
            ou.full_name AS owner_name,
            ou.role AS owner_role,
            d.code,
            d.client_id,
            d.client_code,
            cl.name AS client_name,
            d.status,
            d.campaign_type,
            d.pacing_type,
            d.start_date,
            d.end_date,
            d.total_leads,
            d.cpl,
            d.cpl_currency,
            cf.form_id AS assigned_form_id
        FROM campaigns c
        JOIN campaign_details d ON c.id = d.campaign_id
        LEFT JOIN clients cl ON cl.id = d.client_id
        LEFT JOIN users ou ON ou.id = c.owner_id
        LEFT JOIN campaign_forms cf ON cf.campaign_id = c.id
        WHERE $whereSql
        ORDER BY d.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

/**
 * Get metrics for a specific campaign
 */
function getCampaignMetrics(int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM campaign_metrics WHERE campaign_id = ?");
    if (!$stmt) return null;
    $stmt->bind_param("i", $campaignId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function ensureCampaignNotesTable(): void {
    $conn = getDbConnection();
    if (!campaignLeadTableExists('campaign_notes')) {
        $sql = "
            CREATE TABLE IF NOT EXISTS campaign_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NOT NULL,
                user_id INT NOT NULL,
                note_text TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                updated_by INT NULL,
                attachment_path TEXT NULL,
                attachment_name VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $conn->query($sql);
    }
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_notes' AND COLUMN_NAME = 'updated_by'");
    $chk->execute();
    $c1 = $chk->get_result()->fetch_assoc();
    if ((int)($c1['cnt'] ?? 0) === 0) { $conn->query("ALTER TABLE campaign_notes ADD COLUMN updated_by INT NULL"); }
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_notes' AND COLUMN_NAME = 'attachment_path'");
    $chk->execute();
    $c2 = $chk->get_result()->fetch_assoc();
    if ((int)($c2['cnt'] ?? 0) === 0) { $conn->query("ALTER TABLE campaign_notes ADD COLUMN attachment_path TEXT NULL"); }
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_notes' AND COLUMN_NAME = 'attachment_name'");
    $chk->execute();
    $c3 = $chk->get_result()->fetch_assoc();
    if ((int)($c3['cnt'] ?? 0) === 0) { $conn->query("ALTER TABLE campaign_notes ADD COLUMN attachment_name VARCHAR(255) NULL"); }
}

function getCampaignNotes(int $campaignId): array {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $sql = "
        SELECT n.id, n.campaign_id, n.user_id, n.note_text, n.created_at, n.updated_at, n.updated_by, n.attachment_path, n.attachment_name,
               u.full_name AS author_name, up.full_name AS updated_by_name
        FROM campaign_notes n
        LEFT JOIN users u ON u.id = n.user_id
        LEFT JOIN users up ON up.id = n.updated_by
        WHERE n.campaign_id = ?
        ORDER BY n.created_at DESC, n.id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    }
    $stmt->close();
    return $rows;
}

function getCampaignNoteById(int $noteId): ?array {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $sql = "
        SELECT n.id, n.campaign_id, n.user_id, n.note_text, n.created_at, n.updated_at, n.updated_by, n.attachment_path, n.attachment_name,
               u.full_name AS author_name, up.full_name AS updated_by_name
        FROM campaign_notes n
        LEFT JOIN users u ON u.id = n.user_id
        LEFT JOIN users up ON up.id = n.updated_by
        WHERE n.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $noteId);
    $stmt->execute();
    $res = $stmt->get_result();
    $r = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $r ?: null;
}

function addCampaignNote(int $campaignId, int $userId, string $text, ?string $attachmentPath = null, ?string $attachmentName = null): ?array {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO campaign_notes (campaign_id, user_id, note_text, created_at, updated_by, attachment_path, attachment_name) VALUES (?, ?, ?, NOW(), ?, ?, ?)");
    $stmt->bind_param('iissss', $campaignId, $userId, $text, $userId, $attachmentPath, $attachmentName);
    if (!$stmt->execute()) { return null; }
    $id = (int)$conn->insert_id;
    return getCampaignNoteById($id);
}

function updateCampaignNote(int $noteId, string $text, int $updatedBy, ?string $attachmentPath = null, ?string $attachmentName = null, bool $removeAttachment = false): bool {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $sql = "UPDATE campaign_notes SET note_text = ?, updated_at = NOW(), updated_by = ?";
    $types = "si";
    $params = [$text, $updatedBy];
    if ($removeAttachment) { $sql .= ", attachment_path = NULL, attachment_name = NULL"; }
    else {
        if ($attachmentPath !== null) { $sql .= ", attachment_path = ?"; $types .= "s"; $params[] = $attachmentPath; }
        if ($attachmentName !== null) { $sql .= ", attachment_name = ?"; $types .= "s"; $params[] = $attachmentName; }
    }
    $sql .= " WHERE id = ?";
    $types .= "i";
    $params[] = $noteId;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    return $stmt->execute();
}

function deleteCampaignNote(int $noteId): bool {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM campaign_notes WHERE id = ?");
    $stmt->bind_param('i', $noteId);
    return $stmt->execute();
}

function saveCampaignNoteAttachment(array $file, int $campaignId): ?array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $name = (string)($file['name'] ?? '');
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) return null;
    if ($size <= 0 || $size > 20*1024*1024) return null;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','txt','csv','xls','xlsx','png','jpg','jpeg','gif'];
    if (!in_array($ext, $allowed, true)) return null;
    $base = realpath(__DIR__.'/..');
    if ($base === false) $base = __DIR__.'/..';
    $dir = $base.'/uploads/campaign_notes/'.(int)$campaignId;
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
    $fname = date('Ymd_His').'_'.$safe;
    $dest = $dir.'/'.$fname;
    if (!@move_uploaded_file($tmp, $dest)) return null;
    $relPath = 'uploads/campaign_notes/'.(int)$campaignId.'/'.$fname;
    return ['path' => $relPath, 'name' => $name];
}

/**
 * Update metrics for a specific campaign
 */
function updateCampaignMetrics(int $campaignId, array $metrics, int $updatedBy): bool {
    $conn = getDbConnection();
    $sql = "
        INSERT INTO campaign_metrics 
            (campaign_id, delivered, generated, qualified, disqualified, pending, rejected, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            delivered = VALUES(delivered),
            generated = VALUES(generated),
            qualified = VALUES(qualified),
            disqualified = VALUES(disqualified),
            pending = VALUES(pending),
            rejected = VALUES(rejected),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiiiii", 
        $campaignId, 
        $metrics['delivered'], 
        $metrics['generated'], 
        $metrics['qualified'], 
        $metrics['disqualified'], 
        $metrics['pending'], 
        $metrics['rejected'], 
        $updatedBy
    );
    return $stmt->execute();
}

/**
 * Get basic list of all campaigns for selection dropdowns
 */
function getAllCampaignsBasic(): array {
    $conn = getDbConnection();
    $sql = "
        SELECT c.id, c.name, d.code, d.status 
        FROM campaigns c 
        JOIN campaign_details d ON c.id = d.campaign_id 
        ORDER BY c.name
    ";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function getActiveCampaignsBasic(): array {
    $conn = getDbConnection();
    $sql = "
        SELECT c.id, c.name, d.code, d.status
        FROM campaigns c
        JOIN campaign_details d ON c.id = d.campaign_id
        WHERE c.active = 1 AND d.status = 'Live'
        ORDER BY c.name
    ";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function ensureTeamSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS teams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_name VARCHAR(120) NOT NULL,
        manager_user_id INT NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        UNIQUE KEY uniq_team_name (team_name),
        INDEX (manager_user_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS team_members (
        team_id INT NOT NULL,
        user_id INT NOT NULL,
        member_role VARCHAR(16) NOT NULL DEFAULT 'member',
        added_by INT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (team_id, user_id),
        INDEX (user_id),
        INDEX (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS team_campaigns (
        team_id INT NOT NULL,
        campaign_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (team_id, campaign_id),
        INDEX (campaign_id),
        INDEX (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $verified = true;
}

function ensureAppSettingsSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(191) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $verified = true;
}

function getAppSetting(string $key, ?string $default = null): ?string {
    static $cache = [];
    $key = trim($key);
    if ($key === '') return $default;
    if (array_key_exists($key, $cache)) return $cache[$key];
    ensureAppSettingsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $val = $row ? $row['setting_value'] : $default;
    $cache[$key] = $val;
    return $val;
}

function setAppSetting(string $key, ?string $value): bool {
    $key = trim($key);
    if ($key === '') return false;
    ensureAppSettingsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensureUserIpAccessSchema(): void {
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS user_ip_access (
        user_id INT NOT NULL PRIMARY KEY,
        mode VARCHAR(16) NOT NULL DEFAULT 'open',
        allowed_ips TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (mode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function parseAllowedIpList(?string $raw): array {
    $raw = (string)$raw;
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = str_replace(',', "\n", $raw);
    $parts = array_map('trim', explode("\n", $raw));
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        if (filter_var($p, FILTER_VALIDATE_IP)) $out[$p] = true;
    }
    return array_keys($out);
}

function getUserIpAccessPolicy(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return ['mode' => 'open', 'allowed_ips' => []];
    ensureUserIpAccessSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT mode, allowed_ips FROM user_ip_access WHERE user_id = ? LIMIT 1");
    if (!$stmt) return ['mode' => 'open', 'allowed_ips' => []];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $mode = strtolower(trim((string)($row['mode'] ?? 'open')));
    if ($mode !== 'static') $mode = 'open';
    $allowed = parseAllowedIpList($row['allowed_ips'] ?? '');
    return ['mode' => $mode, 'allowed_ips' => $allowed];
}

function setUserIpAccessPolicy(int $userId, string $mode, ?string $allowedIpsRaw): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    ensureUserIpAccessSchema();
    $mode = strtolower(trim($mode));
    if ($mode !== 'static') $mode = 'open';
    $allowedList = parseAllowedIpList($allowedIpsRaw);
    $allowed = implode("\n", $allowedList);
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO user_ip_access (user_id, mode, allowed_ips) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mode = VALUES(mode), allowed_ips = VALUES(allowed_ips), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iss', $userId, $mode, $allowed);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getInvoiceNumberingSettings(): array {
    $prefix = (string)(getAppSetting('invoice.numbering.prefix', 'INV') ?? 'INV');
    $sep = (string)(getAppSetting('invoice.numbering.separator', '-') ?? '-');
    $pad = (int)(getAppSetting('invoice.numbering.padding', '4') ?? 4);
    if ($pad < 1) $pad = 1;
    if ($pad > 12) $pad = 12;
    $resetMonthly = (string)(getAppSetting('invoice.numbering.reset_monthly', '1') ?? '1') === '1';
    $dateFmt = (string)(getAppSetting('invoice.numbering.date_format', 'Ym') ?? 'Ym');
    if ($dateFmt === '') $dateFmt = 'Ym';
    return [
        'prefix' => $prefix,
        'separator' => $sep,
        'padding' => $pad,
        'reset_monthly' => $resetMonthly,
        'date_format' => $dateFmt,
    ];
}

function nextInvoiceNumber(string $issueDateYmd): string {
    ensureAppSettingsSchema();
    $s = getInvoiceNumberingSettings();
    $prefix = (string)($s['prefix'] ?? 'INV');
    $sep = (string)($s['separator'] ?? '-');
    $pad = (int)($s['padding'] ?? 4);
    $resetMonthly = (bool)($s['reset_monthly'] ?? true);
    $dateFmt = (string)($s['date_format'] ?? 'Ym');

    $ts = strtotime($issueDateYmd . ' 00:00:00');
    if ($ts === false) $ts = time();
    $datePart = date($dateFmt, $ts);

    $key = $resetMonthly ? ('invoice.numbering.next.' . date('Y-m', $ts)) : 'invoice.numbering.next';
    $cur = (int)(getAppSetting($key, '1') ?? 1);
    if ($cur < 1) $cur = 1;
    $next = $cur + 1;
    setAppSetting($key, (string)$next);

    $num = str_pad((string)$cur, $pad, '0', STR_PAD_LEFT);
    $parts = [];
    if ($prefix !== '') $parts[] = $prefix;
    if ($datePart !== '') $parts[] = $datePart;
    $parts[] = $num;
    return implode($sep, $parts);
}

function getAttendancePolicySettings(): array {
    $grace = (int)(getAppSetting('attendance.grace_minutes', '10') ?? 10);
    if ($grace < 0) $grace = 0;
    if ($grace > 120) $grace = 120;
    $halfAt = (int)(getAppSetting('attendance.late_halfday_at', '3') ?? 3);
    if ($halfAt < 1) $halfAt = 1;
    if ($halfAt > 31) $halfAt = 31;
    $absentAt = (int)(getAppSetting('attendance.late_absent_at', '4') ?? 4);
    if ($absentAt < $halfAt) $absentAt = $halfAt;
    if ($absentAt > 31) $absentAt = 31;
    return ['grace_minutes' => $grace, 'late_halfday_at' => $halfAt, 'late_absent_at' => $absentAt];
}

function getUserTeamIds(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    ensureTeamSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT DISTINCT team_id FROM team_members WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ids = [];
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $tid = (int)($r['team_id'] ?? 0);
        if ($tid > 0) $ids[$tid] = true;
    }
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT id FROM teams WHERE manager_user_id = ?");
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $rs2 = $stmt2->get_result();
    while ($r = $rs2->fetch_assoc()) {
        $tid = (int)($r['id'] ?? 0);
        if ($tid > 0) $ids[$tid] = true;
    }
    $stmt2->close();

    return array_keys($ids);
}

function getTeamCampaignIds(array $teamIds): array {
    ensureTeamSchema();
    $ids = array_values(array_filter(array_map('intval', $teamIds), fn($v) => $v > 0));
    if (empty($ids)) return [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM team_campaigns WHERE team_id IN ($in)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $out = [];
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getTeamVisibleCampaignIdsForUser(int $userId, ?array $assignedMap = null): ?array {
    static $cache = [];
    $cacheKey = $userId . '_' . ($assignedMap === null ? 'null' : md5(serialize($assignedMap)));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $userId = (int)$userId;
    if ($userId <= 0) return $assignedMap;
    if (isAdmin() || isDirector()) return null;
    $teamIds = getUserTeamIds($userId);
    if (empty($teamIds)) return $assignedMap;
    $teamCampaigns = getTeamCampaignIds($teamIds);
    if (empty($teamCampaigns)) return $assignedMap;
    if ($assignedMap === null) return $teamCampaigns;
    $out = [];
    foreach ($assignedMap as $cid => $v) {
        $cid = (int)$cid;
        if ($cid > 0) $out[$cid] = true;
    }
    foreach ($teamCampaigns as $cid => $v) {
        $cid = (int)$cid;
        if ($cid > 0) $out[$cid] = true;
    }
    $cache[$cacheKey] = $out;
    return $out;
}

function getCampaignsBasicByIds(array $campaignIds, bool $onlyActive = true): array {
    $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
    if (empty($ids)) return [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "
        SELECT c.id, c.name, d.code, d.status
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.id IN ($in)
    ";
    if ($onlyActive) $sql .= " AND c.active = 1";
    $sql .= " ORDER BY c.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

/**
 * Get summary of all agents and their lead status counts
 */
function getAgentSummary(): array {
    $conn = getDbConnection();
    $sql = "
        SELECT 
            IFNULL(u.full_name, 'N/A') AS agent, 
            COUNT(*) AS total,
            SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
            SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) AS filled,
            SUM(CASE WHEN l.form_done='No' AND l.qa_status='Qualified' THEN 1 ELSE 0 END) AS pending
        FROM leads l
        LEFT JOIN users u ON l.agent_id = u.id 
        GROUP BY l.agent_id, u.full_name 
        ORDER BY total DESC 
    ";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($r = $result->fetch_assoc()) { $rows[] = $r; }
    }
    return $rows;
}

/**
 * Get summary of all campaigns and their lead status counts
 */
function getCampaignSummary(): array {
    $conn = getDbConnection();
    $sql = "
        SELECT 
            IFNULL(c.name, 'N/A') AS campaign, 
            COUNT(*) AS total,
            SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
            SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) AS filled,
            SUM(CASE WHEN l.form_done='No' AND l.qa_status='Qualified' THEN 1 ELSE 0 END) AS pending
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id 
        GROUP BY l.campaign_id, c.name 
        ORDER BY total DESC 
    ";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($r = $result->fetch_assoc()) { $rows[] = $r; }
    }
    return $rows;
}

/**
 * Refresh user data in the current session
 */
function refreshUserSession(int $userId): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && ($user = $result->fetch_assoc())) {
        unset($user['password']); // Never store password in session
        $_SESSION['user'] = $user;
    }
    $stmt->close();
}

/**
 * Get overall stats for Admin Dashboard
 */
function getOverallStats(string $todayStart, string $todayEnd, string $monthStart, string $monthEnd): array {
    $conn = getDbConnection();
    $sql = "
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) as disqualified_leads,
            SUM(CASE WHEN qa_status = 'Pending' THEN 1 ELSE 0 END) as pending_leads,
            SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) as filled_forms,
            SUM(CASE WHEN qa_status = 'Qualified' AND form_done = 'No' THEN 1 ELSE 0 END) as pending_form_filling,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as today_leads,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as today_qualified,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as today_disqualified,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND form_done = 'Yes' THEN 1 ELSE 0 END) as today_filled_forms,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as month_leads,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as month_qualified,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as month_disqualified,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND form_done = 'Yes' THEN 1 ELSE 0 END) as month_filled_forms
        FROM leads
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssssssssssss',
        $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd,
        $monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd
    );
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

/**
 * Get QA stats for QA Dashboard
 */
function getQaStats(string $todayStart, string $todayEnd): array {
    $conn = getDbConnection();
    $sql = "
        SELECT
            COUNT(*) AS total_generated,
            SUM(CASE WHEN qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS total_reviewed,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS total_pass,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS total_fail,
            SUM(CASE WHEN qa_status IS NULL OR qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) AS total_pending,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS today_generated,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS today_reviewed,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS today_pass,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS today_fail
        FROM leads
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssss', $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function getQaStatsScoped(?array $campaignIds, string $todayStart, string $todayEnd): array {
    if ($campaignIds === null) return getQaStats($todayStart, $todayEnd);
    if (empty($campaignIds)) {
        return [
            'total_generated' => 0,
            'total_reviewed' => 0,
            'total_pass' => 0,
            'total_fail' => 0,
            'total_pending' => 0,
            'today_generated' => 0,
            'today_reviewed' => 0,
            'today_pass' => 0,
            'today_fail' => 0,
        ];
    }
    $ids = [];
    foreach ($campaignIds as $k => $v) {
        $ids[] = is_int($k) ? (int)$v : (int)$k;
    }
    $ids = array_values(array_filter($ids, fn($x) => $x > 0));
    if (empty($ids)) {
        return [
            'total_generated' => 0,
            'total_reviewed' => 0,
            'total_pass' => 0,
            'total_fail' => 0,
            'total_pending' => 0,
            'today_generated' => 0,
            'today_reviewed' => 0,
            'today_pass' => 0,
            'today_fail' => 0,
        ];
    }
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        SELECT
            COUNT(*) AS total_generated,
            SUM(CASE WHEN qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS total_reviewed,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS total_pass,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS total_fail,
            SUM(CASE WHEN qa_status IS NULL OR qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) AS total_pending,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS today_generated,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status IN ('Qualified','Disqualified') THEN 1 ELSE 0 END) AS today_reviewed,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS today_pass,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS today_fail
        FROM leads
        WHERE campaign_id IN ($in)
    ";
    $stmt = $conn->prepare($sql);
    $types = 'ssssssss' . str_repeat('i', count($ids));
    $params = [$todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, ...$ids];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

/**
 * Get campaign stats by date
 */
function getCampaignStatsByDate(int $campaignId, string $todayStart, string $todayEnd): array {
    $conn = getDbConnection();
    $sql = "
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) as disqualified_leads,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) as pending_leads,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as today_leads,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as today_qualified,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as today_disqualified
        FROM leads
        WHERE campaign_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssi', $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $campaignId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

/**
 * Get leads with filters and pagination
 */
function getLeads(array $filters = [], int $perPage = 25, int $page = 1): array {
    $conn = getDbConnection();
    $joins = [
        "LEFT JOIN campaigns c ON l.campaign_id = c.id",
        "LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id",
        "LEFT JOIN clients cl ON cl.id = d.client_id",
        "LEFT JOIN users u ON l.agent_id = u.id",
        "LEFT JOIN users au ON l.assigned_to_user = au.id",
        "LEFT JOIN (
            SELECT fs1.lead_id, fs1.campaign_id, fs1.data_json
            FROM form_submissions fs1
            JOIN (
                SELECT lead_id, campaign_id, MAX(id) AS max_id
                FROM form_submissions
                GROUP BY lead_id, campaign_id
            ) fs2 ON fs2.max_id = fs1.id
        ) fs ON fs.lead_id = l.id AND fs.campaign_id = l.campaign_id",
    ];
    $where = ['1=1'];
    $params = [];
    $types = '';

    if (!empty($filters['campaign_id'])) { $where[] = 'l.campaign_id = ?'; $params[] = (int)$filters['campaign_id']; $types .= 'i'; }
    if (!empty($filters['campaign_ids']) && is_array($filters['campaign_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['campaign_ids']), fn($v) => $v > 0));
        if (empty($ids)) return ['leads' => [], 'total' => 0, 'totalPages' => 1];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }
    if (!empty($filters['agent_id'])) { $where[] = 'l.agent_id = ?'; $params[] = (int)$filters['agent_id']; $types .= 'i'; }
    if (!empty($filters['assigned_to_user'])) { $where[] = 'l.assigned_to_user = ?'; $params[] = (int)$filters['assigned_to_user']; $types .= 'i'; }
    if (!empty($filters['vendor_id'])) { $where[] = 'l.vendor_id = ?'; $params[] = (int)$filters['vendor_id']; $types .= 'i'; }
    if (!empty($filters['vendor_only'])) {
        $where[] = "((l.vendor_id IS NOT NULL AND l.vendor_id > 0) OR LOWER(COALESCE(l.lead_source,'')) = 'vendor')";
    }
    if (!empty($filters['client_id'])) { $where[] = 'd.client_id = ?'; $params[] = (int)$filters['client_id']; $types .= 'i'; }
    if (!empty($filters['date_from'])) { $where[] = 'l.created_at >= ?'; $params[] = $filters['date_from'].' 00:00:00'; $types .= 's'; }
    if (!empty($filters['date_to'])) { $where[] = 'l.created_at <= ?'; $params[] = $filters['date_to'].' 23:59:59'; $types .= 's'; }
    if (!empty($filters['qa_status'])) { $where[] = 'l.qa_status = ?'; $params[] = normalizeQaStatus($filters['qa_status']); $types .= 's'; }
    if (!empty($filters['client_delivery_status'])) { $where[] = 'l.client_delivery_status = ?'; $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']); $types .= 's'; }
    if (!empty($filters['form_done'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_done']); $types .= 's'; }
    if (!empty($filters['form_filled'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_filled']); $types .= 's'; }
    if (!empty($filters['tag_id'])) {
        ensureLeadTagsSchema();
        $joins[] = "JOIN lead_tags lt ON lt.lead_id = l.id";
        $where[] = 'lt.tag_id = ?';
        $params[] = (int)$filters['tag_id'];
        $types .= 'i';
    }
    if (!empty($filters['search'])) {
        $where[] = '(l.lead_id LIKE ? OR CAST(l.id AS CHAR) LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)';
        $q = '%'.$filters['search'].'%';
        array_push($params, $q, $q, $q, $q, $q, $q);
        $types .= 'ssssss';
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = implode("\n            ", $joins);
    $offset = max(0, ($page - 1) * $perPage);

    $fromTable = 'leads l';

    $cntSql = "SELECT COUNT(DISTINCT l.id) FROM $fromTable $joinSql WHERE $whereSql";
    $cntStmt = $conn->prepare($cntSql);
    if ($types) $cntStmt->bind_param($types, ...$params);
    $cntStmt->execute();
    $total = (int)($cntStmt->get_result()->fetch_row()[0] ?? 0);
    $cntStmt->close();

    $sql = "SELECT l.*, fs.data_json AS submission_data_json, c.name AS campaign_name, u.full_name AS agent_name,
                   d.code AS campaign_code, d.client_id AS client_id,
                   cl.client_code AS client_code, cl.name AS client_name,
                   au.id AS assigned_to_id, au.full_name AS assigned_to_name, au.role AS assigned_to_role
            FROM $fromTable
            $joinSql
            WHERE $whereSql
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $params2 = $params;
    $params2[] = $perPage;
    $params2[] = $offset;
    $stmt->bind_param($types.'ii', ...$params2);
    $stmt->execute();
    $leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    foreach ($leads as &$r) {
        $r = deriveLeadDisplayFieldsFromSubmission($r, $r['submission_data_json'] ?? null);
    }
    unset($r);

    return ['leads' => $leads, 'total' => $total, 'totalPages' => max(1, (int)ceil($total/$perPage))];
}

function getLeadsNoPagination(array $filters = []): array {
    $conn = getDbConnection();
    $joins = [
        "LEFT JOIN campaigns c ON l.campaign_id = c.id",
        "LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id",
        "LEFT JOIN clients cl ON cl.id = d.client_id",
        "LEFT JOIN users u ON l.agent_id = u.id",
        "LEFT JOIN users au ON l.assigned_to_user = au.id",
        "LEFT JOIN (
            SELECT fs1.lead_id, fs1.campaign_id, fs1.data_json
            FROM form_submissions fs1
            JOIN (
                SELECT lead_id, campaign_id, MAX(id) AS max_id
                FROM form_submissions
                GROUP BY lead_id, campaign_id
            ) fs2 ON fs2.max_id = fs1.id
        ) fs ON fs.lead_id = l.id AND fs.campaign_id = l.campaign_id",
    ];
    $where = ['1=1'];
    $params = [];
    $types = '';

    if (!empty($filters['campaign_id'])) { $where[] = 'l.campaign_id = ?'; $params[] = (int)$filters['campaign_id']; $types .= 'i'; }
    if (!empty($filters['campaign_ids']) && is_array($filters['campaign_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['campaign_ids']), fn($v) => $v > 0));
        if (empty($ids)) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }
    if (!empty($filters['agent_id'])) { $where[] = 'l.agent_id = ?'; $params[] = (int)$filters['agent_id']; $types .= 'i'; }
    if (!empty($filters['assigned_to_user'])) { $where[] = 'l.assigned_to_user = ?'; $params[] = (int)$filters['assigned_to_user']; $types .= 'i'; }
    if (!empty($filters['vendor_id'])) { $where[] = 'l.vendor_id = ?'; $params[] = (int)$filters['vendor_id']; $types .= 'i'; }
    if (!empty($filters['vendor_only'])) {
        $where[] = "((l.vendor_id IS NOT NULL AND l.vendor_id > 0) OR LOWER(COALESCE(l.lead_source,'')) = 'vendor')";
    }
    if (!empty($filters['client_id'])) { $where[] = 'd.client_id = ?'; $params[] = (int)$filters['client_id']; $types .= 'i'; }
    if (!empty($filters['date_from'])) { $where[] = 'l.created_at >= ?'; $params[] = $filters['date_from'].' 00:00:00'; $types .= 's'; }
    if (!empty($filters['date_to'])) { $where[] = 'l.created_at <= ?'; $params[] = $filters['date_to'].' 23:59:59'; $types .= 's'; }
    if (!empty($filters['qa_status'])) { $where[] = 'l.qa_status = ?'; $params[] = normalizeQaStatus($filters['qa_status']); $types .= 's'; }
    if (!empty($filters['client_delivery_status'])) { $where[] = 'l.client_delivery_status = ?'; $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']); $types .= 's'; }
    if (!empty($filters['form_done'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_done']); $types .= 's'; }
    if (!empty($filters['form_filled'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_filled']); $types .= 's'; }
    if (!empty($filters['tag_id'])) {
        ensureLeadTagsSchema();
        $joins[] = "JOIN lead_tags lt ON lt.lead_id = l.id";
        $where[] = 'lt.tag_id = ?';
        $params[] = (int)$filters['tag_id'];
        $types .= 'i';
    }
    if (!empty($filters['search'])) {
        $where[] = '(l.lead_id LIKE ? OR CAST(l.id AS CHAR) LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)';
        $q = '%'.$filters['search'].'%';
        array_push($params, $q, $q, $q, $q, $q, $q);
        $types .= 'ssssss';
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = implode("\n            ", $joins);
    $sql = "SELECT l.*, fs.data_json AS submission_data_json, c.name AS campaign_name, u.full_name AS agent_name,
                   d.code AS campaign_code, d.client_id AS client_id,
                   cl.client_code AS client_code, cl.name AS client_name,
                   au.id AS assigned_to_id, au.full_name AS assigned_to_name, au.role AS assigned_to_role
            FROM leads l
            $joinSql
            WHERE $whereSql
            ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as &$r) {
        $r = deriveLeadDisplayFieldsFromSubmission($r, $r['submission_data_json'] ?? null);
    }
    unset($r);
    return $rows;
}

/**
 * Get leads count based on filters
 */
function getLeadsCount(array $filters = []): int {
    $conn = getDbConnection();
    $where = ['1=1'];
    $params = [];
    $types = '';
    if (!empty($filters['agent_id'])) { $where[] = 'agent_id = ?'; $params[] = (int)$filters['agent_id']; $types .= 'i'; }
    if (!empty($filters['qa_status'])) { $where[] = 'qa_status = ?'; $params[] = $filters['qa_status']; $types .= 's'; }
    if (!empty($filters['client_delivery_status'])) { $where[] = 'client_delivery_status = ?'; $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']); $types .= 's'; }
    if (!empty($filters['date_from'])) { $where[] = 'DATE(created_at) >= ?'; $params[] = $filters['date_from']; $types .= 's'; }
    if (!empty($filters['date_to'])) { $where[] = 'DATE(created_at) <= ?'; $params[] = $filters['date_to']; $types .= 's'; }
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM leads WHERE ".implode(' AND ', $where));
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0];
}

function updateLeadQuality($id, $status, $comments, $reviewerId, $clientComments = '', $clientDeliveryStatus = 'Pending') {
    ensureLeadsTrackingColumns();
    $conn = getDbConnection();
    $before = getLeadById((int)$id);
    $prevStatus = $before ? normalizeQaStatus((string)($before['qa_status'] ?? 'Pending')) : null;
    $prevDelivery = $before ? normalizeClientDeliveryStatus((string)($before['client_delivery_status'] ?? 'Pending')) : null;
    $stmt = $conn->prepare("UPDATE leads SET qa_status=?, qa_comment=?, qa_client_comment=?, client_delivery_status=?, qa_reviewed_by=?, qa_updated_at=NOW(), updated_by=? WHERE id=?");
    $norm = normalizeQaStatus($status);
    $delNorm = normalizeClientDeliveryStatus((string)$clientDeliveryStatus);
    $stmt->bind_param("ssssiii", $norm, $comments, $clientComments, $delNorm, $reviewerId, $reviewerId, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        syncLeadToCampaignTable((int)$id);
        logLeadActivity((int)$id, (int)$reviewerId, 'qa_updated', [
            'qa_prev_status' => $prevStatus,
            'qa_status' => $norm,
            'qa_comment' => (string)$comments,
            'qa_client_comment' => (string)$clientComments,
            'client_delivery_prev_status' => $prevDelivery,
            'client_delivery_status' => $delNorm,
        ]);
        $lead = getLeadById((int)$id);
        if ($lead) {
            $recipients = [];
            $agentId = (int)($lead['agent_id'] ?? 0);
            $assignedTo = (int)($lead['assigned_to_user'] ?? 0);
            $createdBy = (int)($lead['created_by'] ?? 0);
            foreach ([$agentId, $assignedTo, $createdBy] as $rid) {
                if ($rid > 0 && $rid !== (int)$reviewerId) $recipients[$rid] = true;
            }
            $title = 'Lead status updated';
            $leadLabel = (string)($lead['lead_id'] ?? ('#' . (string)$id));
            $campName = (string)($lead['campaign_name'] ?? '');
            $msg = $leadLabel . ($campName !== '' ? (' · ' . $campName) : '') . ' · QA: ' . $norm . ' · Delivery: ' . $delNorm;
            $link = '../leads/lead-details.php?id=' . (int)$id;
            notifyUsers(array_keys($recipients), 'lead.status_updated', $title, $msg, $link);
        }
        if ($lead && isset($lead['campaign_id'])) {
            $cid = (int)($lead['campaign_id'] ?? 0);
            if ($cid > 0) {
                $stmt2 = $conn->prepare("INSERT INTO qa_audit_logs (lead_id, prev_status, campaign_id, qa_status, qa_comment, qa_client_comment, client_delivery_status, qa_reviewed_by, reviewed_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
                if ($stmt2) {
                    $cmt = (string)$comments;
                    $ccmt = (string)$clientComments;
                    $prev = (string)($prevStatus ?? '');
                    $stmt2->bind_param('isissssi', $id, $prev, $cid, $norm, $cmt, $ccmt, $delNorm, $reviewerId);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }
        }
    }
    return $ok;
}
