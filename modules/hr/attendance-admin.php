<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

$allowedRoles = function_exists('getKnownRoles') ? getKnownRoles() : ['admin'];
requireRole($allowedRoles);
ensureCsrfToken();
$user = getCurrentUser();

$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_day') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $workDate = (string)($_POST['work_date'] ?? '');
        $pIn = trim((string)($_POST['punch_in'] ?? ''));
        $pOut = trim((string)($_POST['punch_out'] ?? ''));
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $message = 'Invalid request.';
            $messageType = 'danger';
        } else {
            $day = ensureAttendanceDay($userId, $workDate);
            if (!$day) {
                $message = 'Attendance record not found.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $toDb = function(string $d, string $t): ?string {
                    $t = trim($t);
                    if ($t === '') return null;
                    if (!preg_match('/^\d{2}:\d{2}$/', $t)) return null;
                    try {
                        $dt = new DateTimeImmutable($d . ' ' . $t . ':00', hrDisplayTz());
                        return $dt->setTimezone(hrBaseTz())->format('Y-m-d H:i:s');
                    } catch (Throwable $e) {
                        return null;
                    }
                };
                $pInDb = $toDb($workDate, $pIn);
                $pOutDb = $toDb($workDate, $pOut);
                if ($pInDb !== null && $pOutDb !== null) {
                    $inDt = hrParseBase($pInDb);
                    $outDt = hrParseBase($pOutDb);
                    if ($inDt && $outDt && $outDt <= $inDt) {
                        $pOutDb = $outDt->modify('+1 day')->format('Y-m-d H:i:s');
                    }
                }
                $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_in = ?, punch_out = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $dayId = (int)$day['id'];
                    $stmt->bind_param('ssi', $pInDb, $pOutDb, $dayId);
                    $stmt->execute();
                    $stmt->close();
                    recomputeAttendanceDay($dayId);
                    $message = 'Attendance updated.';
                    $messageType = 'success';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_status') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $workDate = (string)($_POST['work_date'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        $allowed = ['Full Day','Half Day','Absent','Paid Leave','Unpaid Leave'];
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || !in_array($status, $allowed, true)) {
            $message = 'Invalid request.';
            $messageType = 'danger';
        } else {
            $day = ensureAttendanceDay($userId, $workDate);
            if (!$day) {
                $message = 'Attendance record not found.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $dayId = (int)($day['id'] ?? 0);
                if ($status === 'Absent' || $status === 'Paid Leave' || $status === 'Unpaid Leave') {
                    $stmt = $conn->prepare("
                        UPDATE hr_attendance_days
                        SET
                            punch_in = NULL,
                            punch_out = NULL,
                            current_state = 'Off',
                            break_minutes = 0,
                            working_minutes = 0,
                            late_minutes = 0,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('si', $status, $dayId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Status updated.' : 'Failed to update status.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                } elseif ($status === 'Half Day') {
                    $defaultMin = 210;
                    $stmt = $conn->prepare("
                        UPDATE hr_attendance_days
                        SET
                            current_state = 'Off',
                            break_minutes = 0,
                            working_minutes = CASE WHEN COALESCE(working_minutes, 0) > 0 THEN working_minutes ELSE ? END,
                            status = 'Half Day',
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ii', $defaultMin, $dayId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        $message = $ok ? 'Status updated.' : 'Failed to update status.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                } else {
                    $shift = getUserShiftForDate($userId, $workDate);
                    $shiftStart = substr((string)($shift['start_time'] ?? '09:30:00'), 0, 8);
                    $shiftEnd = substr((string)($shift['end_time'] ?? '18:30:00'), 0, 8);
                    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftStart)) $shiftStart = '09:30:00';
                    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftEnd)) $shiftEnd = '18:30:00';

                    $pInDb = $workDate . ' ' . $shiftStart;
                    $pOutDb = $workDate . ' ' . $shiftEnd;
                    if ($shiftEnd <= $shiftStart) {
                        $pOutDb = date('Y-m-d H:i:s', strtotime($pOutDb . ' +1 day'));
                    }

                    $stmt = $conn->prepare("
                        UPDATE hr_attendance_days
                        SET
                            punch_in = ?,
                            punch_out = ?,
                            current_state = 'Off',
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ssi', $pInDb, $pOutDb, $dayId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        if ($ok) recomputeAttendanceDay($dayId);
                        $message = $ok ? 'Status updated.' : 'Failed to update status.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_month') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $monthStr = (string)($_POST['month'] ?? '');
        $status = (string)($_POST['status'] ?? 'Full Day');
        $workingOnly = (int)($_POST['working_only'] ?? 0) === 1;
        $overwrite = (int)($_POST['overwrite'] ?? 0) === 1;
        $allowed = ['Full Day','Half Day','Absent','Paid Leave','Unpaid Leave'];

        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $monthStr) || !in_array($status, $allowed, true)) {
            $message = 'Invalid request.';
            $messageType = 'danger';
        } else {
            $yy = (int)substr($monthStr, 0, 4);
            $mo = (int)substr($monthStr, 5, 2);
            if ($yy < 1970 || $yy > 2100 || $mo < 1 || $mo > 12) {
                $message = 'Invalid month.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $stmtU = $conn->prepare("SELECT id FROM users WHERE id = ? AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) LIMIT 1");
                $existsUser = false;
                if ($stmtU) {
                    $stmtU->bind_param('i', $userId);
                    $stmtU->execute();
                    $existsUser = (bool)($stmtU->get_result()->fetch_assoc() ?: null);
                    $stmtU->close();
                }
                if (!$existsUser) {
                    $message = 'User not found.';
                    $messageType = 'danger';
                } else {
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mo, $yy);
                    $holidaySet = [];
                    if ($workingOnly) {
                        foreach (getHolidaysForMonth($yy, $mo, 'US') as $h) {
                            $d = (string)($h['holiday_date'] ?? '');
                            if ($d !== '') $holidaySet[$d] = true;
                        }
                    }

                    $updated = 0;
                    $created = 0;
                    $skipped = 0;

                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $ts = mktime(0, 0, 0, $mo, $day, $yy);
                        if ($ts === false) { $skipped++; continue; }
                        $workDate = date('Y-m-d', $ts);
                        if ($workingOnly) {
                            $weekday = (int)date('N', $ts);
                            if ($weekday > 5) { $skipped++; continue; }
                            if (isset($holidaySet[$workDate])) { $skipped++; continue; }
                        }

                        $before = getAttendanceDay($userId, $workDate);
                        if (!$overwrite && $before) {
                            $beforeStatus = (string)($before['status'] ?? 'Absent');
                            $beforeIn = (string)($before['punch_in'] ?? '');
                            $beforeOut = (string)($before['punch_out'] ?? '');
                            if ($beforeStatus !== 'Absent' || $beforeIn !== '' || $beforeOut !== '') {
                                $skipped++;
                                continue;
                            }
                        }

                        $dayRow = ensureAttendanceDay($userId, $workDate);
                        if (!$dayRow) { $skipped++; continue; }
                        if (!$before) $created++;
                        $dayId = (int)($dayRow['id'] ?? 0);
                        if ($dayId <= 0) { $skipped++; continue; }

                        if ($status === 'Absent' || $status === 'Paid Leave' || $status === 'Unpaid Leave') {
                            $stmt = $conn->prepare("
                                UPDATE hr_attendance_days
                                SET
                                    punch_in = NULL,
                                    punch_out = NULL,
                                    current_state = 'Off',
                                    break_minutes = 0,
                                    working_minutes = 0,
                                    late_minutes = 0,
                                    status = ?,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            if ($stmt) {
                                $stmt->bind_param('si', $status, $dayId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) $updated++; else $skipped++;
                            } else {
                                $skipped++;
                            }
                        } elseif ($status === 'Half Day') {
                            $defaultMin = 210;
                            $stmt = $conn->prepare("
                                UPDATE hr_attendance_days
                                SET
                                    current_state = 'Off',
                                    break_minutes = 0,
                                    working_minutes = CASE WHEN COALESCE(working_minutes, 0) > 0 THEN working_minutes ELSE ? END,
                                    status = 'Half Day',
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            if ($stmt) {
                                $stmt->bind_param('ii', $defaultMin, $dayId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) $updated++; else $skipped++;
                            } else {
                                $skipped++;
                            }
                        } else {
                            $shift = getUserShiftForDate($userId, $workDate);
                            $shiftStart = substr((string)($shift['start_time'] ?? '09:30:00'), 0, 8);
                            $shiftEnd = substr((string)($shift['end_time'] ?? '18:30:00'), 0, 8);
                            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftStart)) $shiftStart = '09:30:00';
                            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftEnd)) $shiftEnd = '18:30:00';

                            $pInDb = $workDate . ' ' . $shiftStart;
                            $pOutDb = $workDate . ' ' . $shiftEnd;
                            if ($shiftEnd <= $shiftStart) {
                                $pOutDb = date('Y-m-d H:i:s', strtotime($pOutDb . ' +1 day'));
                            }

                            $stmt = $conn->prepare("
                                UPDATE hr_attendance_days
                                SET
                                    punch_in = ?,
                                    punch_out = ?,
                                    current_state = 'Off',
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            if ($stmt) {
                                $stmt->bind_param('ssi', $pInDb, $pOutDb, $dayId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) {
                                    recomputeAttendanceDay($dayId);
                                    $updated++;
                                } else {
                                    $skipped++;
                                }
                            } else {
                                $skipped++;
                            }
                        }
                    }

                    $message = "Monthly attendance updated. Updated: {$updated}, Created: {$created}, Skipped: {$skipped}.";
                    $messageType = $updated > 0 || $created > 0 ? 'success' : 'warning';
                }
            }
        }
    }
}

$rows = getAttendanceDashboard($date);
$yy = (int)substr($date, 0, 4);
$mo = (int)substr($date, 5, 2);
$selMonthStr = sprintf('%04d-%02d', $yy, $mo);
$holidays = getHolidaysForMonth($yy, $mo, 'US');
$fullCount = 0;
$halfCount = 0;
$absCount = 0;
$plCount = 0;
$ulCount = 0;
$inProg = 0;
$presentLate = 0;
foreach ($rows as $r) {
    $st = (string)($r['status'] ?? 'Absent');
    if ($st === 'Full Day') $fullCount++;
    elseif ($st === 'Half Day') $halfCount++;
    elseif ($st === 'Paid Leave') $plCount++;
    elseif ($st === 'Unpaid Leave') $ulCount++;
    elseif ($st === 'In Progress') $inProg++;
    else $absCount++;

    $pIn = (string)($r['punch_in'] ?? '');
    $late = (int)($r['late_minutes'] ?? 0);
    if ($pIn !== '' && $late > 0 && !in_array($st, ['Paid Leave','Unpaid Leave','paid_leave','unpaid_leave'], true)) {
        $presentLate++;
    }
}
?>
<?php $pageTitle = 'Attendance Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Attendance Admin'],
            ],
            'Attendance Dashboard',
            'Daily attendance overview and corrections',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
                ['label' => 'Monthly', 'href' => 'attendance-monthly-report?month=' . urlencode($selMonthStr), 'icon' => 'bi-calendar3', 'class' => 'btn-outline-secondary'],
                ['label' => 'Export', 'href' => 'attendance-export?date=' . urlencode($date), 'icon' => 'bi-download', 'class' => 'btn-outline-secondary'],
            ]
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <div class="row g-3 mb-3">
                        <div class="col-lg-4">
                            <div class="card hr-card h-100">
                                <div class="card-header bg-light fw-semibold d-flex align-items-center gap-2">
                                    <i class="bi bi-calendar3"></i>
                                    <span>Daily</span>
                                </div>
                                <div class="card-body">
                                    <form method="get" class="row g-2 align-items-end">
                                        <div class="col-12">
                                            <label class="form-label small text-muted">Date</label>
                                            <input type="date" name="date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date); ?>">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-funnel"></i> Load</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="card hr-card h-100">
                                <div class="card-header bg-light fw-semibold d-flex align-items-center gap-2">
                                    <i class="bi bi-calendar2-plus"></i>
                                    <span>Monthly Bulk Entry (Single Agent)</span>
                                </div>
                                <div class="card-body">
                                    <form method="post" class="row g-2 align-items-end">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="bulk_month">
                                        <div class="col-md-5">
                                            <label class="form-label small text-muted">Employee</label>
                                            <select class="form-select form-select-sm" name="user_id" required>
                                                <option value="">Select agent</option>
                                                <?php foreach ($rows as $uRow): ?>
                                                    <option value="<?php echo (int)($uRow['user_id'] ?? 0); ?>">
                                                        <?php
                                                            $nm = (string)($uRow['full_name'] ?? '');
                                                            $role = (string)($uRow['role'] ?? '');
                                                            echo htmlspecialchars($nm . ($role !== '' ? ' (' . $role . ')' : ''));
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted">Month</label>
                                            <input type="month" class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($selMonthStr); ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Set As</label>
                                            <select class="form-select form-select-sm" name="status">
                                                <option value="Full Day" selected>Full Day (Shift)</option>
                                                <option value="Half Day">Half Day</option>
                                                <option value="Paid Leave">Paid Leave</option>
                                                <option value="Unpaid Leave">Unpaid Leave</option>
                                                <option value="Absent">Absent</option>
                                            </select>
                                        </div>
                                        <div class="col-md-8 d-flex flex-wrap gap-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="working_only" value="1" id="workingOnly" checked>
                                                <label class="form-check-label small" for="workingOnly">Working days only</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="overwriteDays">
                                                <label class="form-check-label small" for="overwriteDays">Overwrite existing</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-check2-circle"></i> Apply</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php hrKpi('Full Day', (string)number_format($fullCount), 'bi-check2-circle', '', 'text-success'); ?>
                        </div>
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php hrKpi('Half Day', (string)number_format($halfCount), 'bi-hourglass-split', '', 'text-warning'); ?>
                        </div>
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php hrKpi('Paid Leave', (string)number_format($plCount), 'bi-calendar2-plus', '', 'text-success'); ?>
                        </div>
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php hrKpi('Unpaid Leave', (string)number_format($ulCount), 'bi-calendar2-x', '', 'text-danger'); ?>
                        </div>
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php hrKpi('Absent', (string)number_format($absCount), 'bi-x-circle', '', 'text-danger'); ?>
                        </div>
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php hrKpi('In Progress', (string)number_format($inProg), 'bi-arrow-repeat'); ?>
                        </div>
                        <div class="col-6 col-md-4 col-lg-3">
                            <?php hrKpi('Present Late', (string)number_format($presentLate), 'bi-alarm', '', 'text-warning'); ?>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-9">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle hr-table">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Job Title</th>
                                            <th>Shift</th>
                                            <th>Punch In</th>
                                            <th>Punch Out</th>
                                            <th>State</th>
                                            <th class="text-end">Break (min)</th>
                                            <th class="text-end">Work (min)</th>
                                            <th class="text-end">Late (min)</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r): ?>
                                            <?php
                                                $pIn = (string)($r['punch_in'] ?? '');
                                                $pOut = (string)($r['punch_out'] ?? '');
                                                $pInV = $pIn !== '' ? hrFormatForDisplay($pIn, 'H:i') : '';
                                                $pOutV = $pOut !== '' ? hrFormatForDisplay($pOut, 'H:i') : '';
                                                $shiftStartTime = (string)($r['shift_start_time'] ?? '');
                                                $shiftStartV = $shiftStartTime !== '' ? substr($shiftStartTime, 0, 5) : '';
                                                $graceM = (int)($r['grace_minutes'] ?? 0);
                                                $st = (string)($r['status'] ?? 'Absent');
                                                $stCls = $st === 'Full Day'
                                                    ? 'bg-success'
                                                    : ($st === 'Half Day'
                                                        ? 'bg-warning text-dark'
                                                        : ($st === 'Paid Leave'
                                                            ? 'bg-success-subtle text-success border'
                                                            : ($st === 'Unpaid Leave'
                                                                ? 'bg-danger-subtle text-danger border'
                                                                : ($st === 'Absent' ? 'bg-danger' : 'bg-secondary'))));
                                                $jt = trim((string)($r['job_title'] ?? ''));
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></td>
                                                <td class="text-muted small"><?php echo htmlspecialchars($jt !== '' ? $jt : ''); ?></td>
                                                <td class="text-muted small">
                                                    <?php
                                                        if ($shiftStartV === '' && $graceM <= 0) echo '—';
                                                        else echo htmlspecialchars(($shiftStartV !== '' ? $shiftStartV : '—') . ($graceM > 0 ? ' (+' . $graceM . 'm)' : ''));
                                                    ?>
                                                </td>
                                                <td class="font-monospace"><?php echo $pInV !== '' ? htmlspecialchars($pInV) : '—'; ?></td>
                                                <td class="font-monospace"><?php echo $pOutV !== '' ? htmlspecialchars($pOutV) : '—'; ?></td>
                                                <td><?php echo htmlspecialchars((string)($r['current_state'] ?? 'Off')); ?></td>
                                                <td class="text-end"><?php echo (int)($r['break_minutes'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int)($r['working_minutes'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int)($r['late_minutes'] ?? 0); ?></td>
                                                <td><span class="badge <?php echo $stCls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                                                <td class="text-end">
                                                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#edit<?php echo (int)$r['user_id']; ?>" aria-expanded="false" title="Set Status"><i class="bi bi-person-check"></i></button>
                                                </td>
                                            </tr>
                                            <tr class="collapse" id="edit<?php echo (int)$r['user_id']; ?>">
                                                <td colspan="11">
                                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                                                            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($date); ?>">
                                                            <input type="hidden" name="status" value="Full Day">
                                                            <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Full Day</button>
                                                        </form>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                                                            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($date); ?>">
                                                            <input type="hidden" name="status" value="Half Day">
                                                            <button class="btn btn-warning btn-sm" type="submit"><i class="bi bi-hourglass-split"></i> Half Day</button>
                                                        </form>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                                                            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($date); ?>">
                                                            <input type="hidden" name="status" value="Absent">
                                                            <button class="btn btn-danger btn-sm" type="submit"><i class="bi bi-x-circle"></i> Absent</button>
                                                        </form>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                                                            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($date); ?>">
                                                            <input type="hidden" name="status" value="Paid Leave">
                                                            <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-calendar2-plus"></i> Paid Leave</button>
                                                        </form>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                                                            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($date); ?>">
                                                            <input type="hidden" name="status" value="Unpaid Leave">
                                                            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-calendar2-x"></i> Unpaid Leave</button>
                                                        </form>
                                                    </div>
                                                    <form method="post" class="row g-2 align-items-end">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="action" value="edit_day">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                                                        <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($date); ?>">
                                                        <div class="col-md-2">
                                                            <label class="form-label small text-muted">Punch In</label>
                                                            <input class="form-control form-control-sm font-monospace" name="punch_in" value="<?php echo htmlspecialchars($pInV); ?>" placeholder="09:30">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label small text-muted">Punch Out</label>
                                                            <input class="form-control form-control-sm font-monospace" name="punch_out" value="<?php echo htmlspecialchars($pOutV); ?>" placeholder="18:30">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Save</button>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($rows)): ?>
                                            <tr><td colspan="10" class="text-center text-muted">No users</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="col-lg-3">
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-calendar-event me-1"></i> US Holidays</span>
                                    <span class="badge bg-secondary"><?php echo number_format(count($holidays)); ?></span>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($holidays)): ?>
                                        <div class="text-muted">No holidays in this month.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0 hr-table">
                                                <tbody>
                                                    <?php foreach ($holidays as $h): ?>
                                                        <tr>
                                                            <td class="font-monospace"><?php echo htmlspecialchars((string)($h['holiday_date'] ?? '')); ?></td>
                                                            <td class="text-muted small"><?php echo htmlspecialchars((string)($h['name'] ?? '')); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
