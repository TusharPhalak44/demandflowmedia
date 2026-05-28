<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requireRole(['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();
$user = getCurrentUser();

$today = date('Y-m-d');
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid security token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'punch_in') {
            $ok = punchIn((int)$user['id']);
            $message = $ok ? 'Punched in.' : 'Failed to punch in.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'punch_out') {
            $ok = punchOut((int)$user['id']);
            $message = $ok ? 'Punched out.' : 'Failed to punch out.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'start_state') {
            $state = (string)($_POST['state'] ?? '');
            $res = startAttendanceState((int)$user['id'], $state);
            $ok = !empty($res['ok']);
            $message = $ok ? 'Status updated.' : (string)($res['error'] ?? 'Failed to update status.');
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'end_state') {
            $ok = endAttendanceState((int)$user['id']);
            $message = $ok ? 'Status ended.' : 'Failed to end status.';
            $messageType = $ok ? 'success' : 'danger';
        }
    }
}

$workDate = $today;
$day = getOpenAttendanceDayForUser((int)$user['id']);
if ($day) {
    $workDate = (string)($day['work_date'] ?? $today);
} else {
    $day = ensureAttendanceDay((int)$user['id'], $workDate);
}
$open = $day ? getOpenAttendanceState((int)$day['id']) : null;
$currentState = $day ? (string)($day['current_state'] ?? 'Off') : 'Off';
$status = $day ? (string)($day['status'] ?? 'Absent') : 'Absent';
$breakMin = $day ? (int)($day['break_minutes'] ?? 0) : 0;
$workMin = $day ? (int)($day['working_minutes'] ?? 0) : 0;
$lateMin = $day ? (int)($day['late_minutes'] ?? 0) : 0;

$pIn = $day ? (string)($day['punch_in'] ?? '') : '';
$pOut = $day ? (string)($day['punch_out'] ?? '') : '';

$statusBadgeClass = $status === 'Full Day'
    ? 'bg-success'
    : ($status === 'Half Day'
        ? 'bg-warning text-dark'
        : ($status === 'Paid Leave'
            ? 'bg-success-subtle text-success border'
            : ($status === 'Unpaid Leave'
                ? 'bg-danger-subtle text-danger border'
                : ($status === 'In Progress'
                    ? 'bg-info text-dark'
                    : ($status === 'Absent' ? 'bg-danger' : 'bg-secondary')))));

$stateLabel = $currentState;
$stateBadgeClass = 'bg-secondary';
if ($currentState !== 'Off') {
    $stateBadgeClass = 'bg-primary';
} else {
    if ($pIn === '' && $pOut === '') {
        $stateLabel = 'Not Started';
        $stateBadgeClass = 'bg-secondary';
    } elseif ($pIn !== '' && $pOut === '') {
        $stateLabel = 'Checked In';
        $stateBadgeClass = 'bg-info text-dark';
    } elseif ($pIn !== '' && $pOut !== '') {
        $stateLabel = 'Checked Out';
        $stateBadgeClass = 'bg-dark';
    }
}
$lateBadgeText = $lateMin > 0 ? ('Late +' . number_format($lateMin) . 'm') : '';

$yy = (int)substr($workDate, 0, 4);
$mo = (int)substr($workDate, 5, 2);
$holidays = getHolidaysForMonth($yy, $mo, 'US');

$shift = getUserShiftForDate((int)($user['id'] ?? 0), $workDate);
$shiftName = (string)($shift['name'] ?? '');
$shiftStart = substr((string)($shift['start_time'] ?? ''), 0, 5);
$shiftEnd = substr((string)($shift['end_time'] ?? ''), 0, 5);
$policy = getAttendancePolicySettings();
$shiftGrace = (int)($policy['grace_minutes'] ?? 10);
$effectiveGrace = $day ? (int)($day['grace_minutes'] ?? $shiftGrace) : $shiftGrace;

$allowedBreak = 75;
$usedB1 = $day ? getBreakMinutesUsed((int)$day['id'], 'Break1') : 0;
$usedB2 = $day ? getBreakMinutesUsed((int)$day['id'], 'Break2') : 0;
$usedLunch = $day ? getBreakMinutesUsed((int)$day['id'], 'Lunch') : 0;
$usedTotal = min($allowedBreak, min(15, $usedB1) + min(15, $usedB2) + min(45, $usedLunch));

function fmtMinutes(int $m): string {
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%dh %02dm', $h, $mm);
}
?>
<?php $pageTitle = 'My Attendance'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <?php
        $headerBadges = [
            ['text' => $workDate, 'class' => 'bg-light text-dark font-monospace'],
            ['text' => $status, 'class' => $statusBadgeClass],
            ['text' => $stateLabel, 'class' => $stateBadgeClass],
        ];
        if ($lateBadgeText !== '') {
            $headerBadges[] = ['text' => $lateBadgeText, 'class' => 'bg-danger'];
        }
        hrRenderHeader(
            [
                ['label' => 'HR', 'href' => 'hr-dashboard'],
                ['label' => 'Attendance'],
            ],
            'My Attendance',
            'Punch in/out, track breaks & working hours',
            [
                ['label' => 'HR Dashboard', 'href' => 'hr-dashboard', 'icon' => 'bi-columns-gap', 'class' => 'btn-outline-primary'],
            ],
            $headerBadges
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <?php hrKpi('Punch In', $pIn !== '' ? hrFormatForDisplay($pIn, 'H:i') : '—', 'bi-box-arrow-in-right'); ?>
                                </div>
                                <div class="col-md-6">
                                    <?php hrKpi('Punch Out', $pOut !== '' ? hrFormatForDisplay($pOut, 'H:i') : '—', 'bi-box-arrow-right'); ?>
                                </div>
                                <div class="col-md-6">
                                    <?php hrKpi('Working Hours', fmtMinutes($workMin), 'bi-briefcase', 'Break: ' . fmtMinutes($breakMin)); ?>
                                </div>
                                <div class="col-md-6">
                                    <?php hrKpi('Break Used', fmtMinutes($usedTotal) . ' / ' . fmtMinutes($allowedBreak), 'bi-cup-hot', 'Tea1: ' . (int)min(15,$usedB1) . 'm · Tea2: ' . (int)min(15,$usedB2) . 'm · Lunch: ' . (int)min(45,$usedLunch) . 'm'); ?>
                                </div>
                            </div>

                            <div class="card hr-card mt-3">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-lightning-charge me-1"></i> Actions</span>
                                    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                                        <span class="badge <?php echo htmlspecialchars($stateBadgeClass); ?>"><?php echo htmlspecialchars($stateLabel); ?></span>
                                        <?php if ($lateBadgeText !== ''): ?>
                                            <span class="badge bg-danger"><?php echo htmlspecialchars($lateBadgeText); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="punch_in">
                                                <button class="btn btn-success btn-sm w-100" type="submit" <?php echo $pIn !== '' ? 'disabled' : ''; ?>><i class="bi bi-box-arrow-in-right"></i> Punch In</button>
                                            </form>
                                        </div>
                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="punch_out">
                                                <button class="btn btn-danger btn-sm w-100" type="submit" <?php echo ($pIn === '' || $pOut !== '') ? 'disabled' : ''; ?>><i class="bi bi-box-arrow-right"></i> Punch Out</button>
                                            </form>
                                        </div>
                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="end_state">
                                                <button class="btn btn-outline-dark btn-sm w-100" type="submit" <?php echo ($pIn === '' || $pOut !== '' || !$open) ? 'disabled' : ''; ?>><i class="bi bi-stop-circle"></i> End Current</button>
                                            </form>
                                        </div>

                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Working">
                                                <button class="btn btn-outline-primary btn-sm w-100" type="submit" <?php echo $pIn === '' || $pOut !== '' ? 'disabled' : ''; ?>><i class="bi bi-briefcase"></i> Working</button>
                                            </form>
                                        </div>
                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Meeting">
                                                <button class="btn btn-outline-secondary btn-sm w-100" type="submit" <?php echo $pIn === '' || $pOut !== '' ? 'disabled' : ''; ?>><i class="bi bi-people"></i> Meeting</button>
                                            </form>
                                        </div>
                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Lunch">
                                                <button class="btn btn-outline-warning btn-sm w-100" type="submit" <?php echo ($pIn === '' || $pOut !== '' || $usedLunch >= 45) ? 'disabled' : ''; ?>><i class="bi bi-egg-fried"></i> Lunch</button>
                                            </form>
                                        </div>

                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Break1">
                                                <button class="btn btn-outline-warning btn-sm w-100" type="submit" <?php echo ($pIn === '' || $pOut !== '' || $usedB1 >= 15) ? 'disabled' : ''; ?>><i class="bi bi-cup-hot"></i> Tea 1</button>
                                            </form>
                                        </div>
                                        <div class="col-md-4">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="start_state">
                                                <input type="hidden" name="state" value="Break2">
                                                <button class="btn btn-outline-warning btn-sm w-100" type="submit" <?php echo ($pIn === '' || $pOut !== '' || $usedB2 >= 15) ? 'disabled' : ''; ?>><i class="bi bi-cup-straw"></i> Tea 2</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="mt-2 small text-muted">Policy: Tea1 15m, Tea2 15m, Lunch 45m (max 75m/day).</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card hr-card mb-3">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-activity me-1"></i> Status</span>
                                    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                                        <span class="badge <?php echo htmlspecialchars($statusBadgeClass); ?>"><?php echo htmlspecialchars($status); ?></span>
                                        <?php if ($lateBadgeText !== ''): ?>
                                            <span class="badge bg-danger"><?php echo htmlspecialchars($lateBadgeText); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="text-muted small">Today</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($stateLabel); ?></div>
                                        <span class="badge <?php echo htmlspecialchars($stateBadgeClass); ?>"><?php echo htmlspecialchars($stateLabel); ?></span>
                                    </div>
                                    <div class="text-muted small mt-2">Break Used</div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(fmtMinutes($usedTotal)); ?> / <?php echo htmlspecialchars(fmtMinutes($allowedBreak)); ?></div>
                                    <div class="text-muted small mt-2">Late Coming</div>
                                    <div class="fw-semibold <?php echo $lateMin > 0 ? 'text-danger' : ''; ?>"><?php echo $lateMin > 0 ? (number_format($lateMin) . ' min late') : 'On time'; ?></div>
                                    <div class="text-muted small mt-2">Shift</div>
                                    <div class="fw-semibold">
                                        <?php if ($shiftName !== '' && $shiftStart !== '' && $shiftEnd !== ''): ?>
                                            <?php
                                                $s1 = '';
                                                $s2 = '';
                                                try {
                                                    $stRaw = (string)($shift['start_time'] ?? '');
                                                    $enRaw = (string)($shift['end_time'] ?? '');
                                                    $st = strlen($stRaw) === 5 ? ($stRaw . ':00') : $stRaw;
                                                    $en = strlen($enRaw) === 5 ? ($enRaw . ':00') : $enRaw;
                                                    $sd = new DateTimeImmutable($workDate . ' ' . $st, hrBaseTz());
                                                    $ed = new DateTimeImmutable($workDate . ' ' . $en, hrBaseTz());
                                                    if ($ed <= $sd) $ed = $ed->modify('+1 day');
                                                    $s1 = $sd->setTimezone(hrDisplayTz())->format('H:i');
                                                    $s2 = $ed->setTimezone(hrDisplayTz())->format('H:i');
                                                } catch (Throwable $e) {
                                                    $s1 = '';
                                                    $s2 = '';
                                                }
                                            ?>
                                            <?php echo htmlspecialchars($shiftName); ?> · <?php echo htmlspecialchars(($s1 !== '' && $s2 !== '') ? ($s1 . '–' . $s2) : ($shiftStart . '–' . $shiftEnd)); ?> · Grace <?php echo number_format($effectiveGrace); ?>m
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

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
