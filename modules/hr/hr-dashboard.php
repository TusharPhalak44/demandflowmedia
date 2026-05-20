<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/hr-ui.php';

requireRole(['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
ensureCsrfToken();
$user = getCurrentUser();

$today = date('Y-m-d');
$now = new DateTime();
$year = (int)$now->format('Y');
$month = (int)$now->format('m');
$monthStr = $now->format('Y-m');

$isAdmin = in_array((string)($user['role'] ?? ''), ['admin'], true);
$workDate = $today;
$day = getOpenAttendanceDayForUser((int)($user['id'] ?? 0));
if ($day) {
    $workDate = (string)($day['work_date'] ?? $today);
} else {
    $day = ensureAttendanceDay((int)($user['id'] ?? 0), $workDate);
}
$pIn = $day ? (string)($day['punch_in'] ?? '') : '';
$pOut = $day ? (string)($day['punch_out'] ?? '') : '';
$status = $day ? (string)($day['status'] ?? 'Absent') : 'Absent';
$currentState = $day ? (string)($day['current_state'] ?? 'Off') : 'Off';
$workMin = $day ? (int)($day['working_minutes'] ?? 0) : 0;
$breakMin = $day ? (int)($day['break_minutes'] ?? 0) : 0;
$lateToday = $day ? (int)($day['late_minutes'] ?? 0) : 0;

$monthSum = getAttendanceMonthSummary((int)($user['id'] ?? 0), $year, $month);
$workingDays = (int)($monthSum['working_days'] ?? 0);
$present = (int)($monthSum['present_days'] ?? 0);
$half = (int)($monthSum['half_days'] ?? 0);
$absent = (int)($monthSum['absent_days'] ?? 0);
$monthLateDays = (int)($monthSum['late_days'] ?? 0);
$monthLateMinutes = (int)($monthSum['late_minutes'] ?? 0);
$paidDays = (float)($present + ($half * 0.5));
$monthPct = $workingDays > 0 ? round((($paidDays / (float)$workingDays) * 100.0), 1) : 0.0;

$payslip = getPayslip((int)($user['id'] ?? 0), $year, $month);
$payslipStatus = $payslip ? 'Generated' : 'Not Generated';
$payrollLocked = hrIsPayrollLocked($year, $month);

$upcoming = getUpcomingHolidays($today, 6, 'US');
$holidaysThisMonth = getHolidaysForMonth($year, $month, 'US');

function fmtMinutesHrDash(int $m): string {
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%dh %02dm', $h, $mm);
}
?>
<?php $pageTitle = 'HR Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <?php
        hrRenderHeader(
            [
                ['label' => 'HR'],
            ],
            'HR Dashboard',
            'Attendance, payroll, payslips and quick exports',
            [
                ['label' => 'Attendance', 'href' => 'attendance', 'icon' => 'bi-fingerprint', 'class' => 'btn-outline-primary'],
                ['label' => 'Payslips', 'href' => 'payslips', 'icon' => 'bi-receipt', 'class' => 'btn-outline-secondary'],
                $isAdmin ? ['label' => 'Payroll', 'href' => 'payroll', 'icon' => 'bi-calculator', 'class' => 'btn-outline-secondary'] : [],
            ],
            [
                ['text' => $workDate, 'class' => 'bg-light text-dark font-monospace'],
                ['text' => $payrollLocked ? 'Payroll Locked' : 'Payroll Open', 'class' => $payrollLocked ? 'bg-danger' : 'bg-secondary'],
            ]
        );
    ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card hr-card">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-fingerprint me-1"></i> Attendance Today</div>
                                            <span class="badge bg-<?php echo $status === 'Full Day' ? 'success' : ($status === 'Half Day' ? 'warning text-dark' : ($status === 'Absent' ? 'danger' : 'secondary')); ?>"><?php echo htmlspecialchars($status); ?></span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Punch In</div>
                                                <div class="hr-kpi-value font-monospace"><?php echo $pIn !== '' ? htmlspecialchars(date('H:i', strtotime($pIn))) : '—'; ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Punch Out</div>
                                                <div class="hr-kpi-value font-monospace"><?php echo $pOut !== '' ? htmlspecialchars(date('H:i', strtotime($pOut))) : '—'; ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Work</div>
                                                <div class="hr-kpi-value"><?php echo htmlspecialchars(fmtMinutesHrDash($workMin)); ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Break</div>
                                                <div class="hr-kpi-value"><?php echo htmlspecialchars(fmtMinutesHrDash($breakMin)); ?></div>
                                            </div>
                                            <div class="col-6">
                                                <div class="hr-kpi-label">Late</div>
                                                <div class="hr-kpi-value"><?php echo $lateToday > 0 ? (number_format($lateToday) . ' min') : 'On time'; ?></div>
                                            </div>
                                        </div>
                                        <div class="mt-3 d-flex flex-wrap gap-2">
                                            <a class="btn btn-outline-primary btn-sm" href="attendance"><i class="bi bi-arrow-right-circle"></i> Open</a>
                                            <?php if ($isAdmin): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="attendance-admin"><i class="bi bi-people"></i> Admin</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">Current state: <span class="fw-semibold"><?php echo htmlspecialchars($currentState); ?></span></div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-calendar2-week me-1"></i> Attendance This Month</div>
                                            <span class="badge bg-<?php echo $monthPct >= 90 ? 'success' : ($monthPct >= 70 ? 'warning text-dark' : 'secondary'); ?>"><?php echo number_format($monthPct, 1); ?>%</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-3">
                                                <div class="hr-kpi-label">Working</div>
                                                <div class="hr-kpi-value"><?php echo number_format($workingDays); ?></div>
                                            </div>
                                            <div class="col-3">
                                                <div class="hr-kpi-label">Present</div>
                                                <div class="hr-kpi-value text-success"><?php echo number_format($present); ?></div>
                                            </div>
                                            <div class="col-3">
                                                <div class="hr-kpi-label">Half</div>
                                                <div class="hr-kpi-value text-warning"><?php echo number_format($half); ?></div>
                                            </div>
                                            <div class="col-3">
                                                <div class="hr-kpi-label">Absent</div>
                                                <div class="hr-kpi-value text-danger"><?php echo number_format($absent); ?></div>
                                            </div>
                                        </div>
                                        <div class="mt-3 d-flex flex-wrap gap-2">
                                            <?php if ($isAdmin): ?>
                                                <a class="btn btn-outline-primary btn-sm" href="attendance-monthly-report?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-graph-up"></i> Report</a>
                                                <a class="btn btn-outline-secondary btn-sm" href="attendance-monthly-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> CSV</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">Paid days: <span class="fw-semibold"><?php echo number_format($paidDays, 1); ?></span> · Late: <span class="fw-semibold"><?php echo number_format($monthLateMinutes); ?></span> min (<?php echo number_format($monthLateDays); ?> days)</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-receipt me-1"></i> Payslip</div>
                                            <span class="badge bg-<?php echo $payslip ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($payslipStatus); ?></span>
                                        </div>
                                        <div class="hr-kpi-label">Month</div>
                                        <div class="fw-semibold font-monospace"><?php echo htmlspecialchars($monthStr); ?></div>
                                        <div class="mt-3 d-flex flex-wrap gap-2">
                                            <a class="btn btn-outline-primary btn-sm" href="payslips"><i class="bi bi-arrow-right-circle"></i> View Payslips</a>
                                            <?php if ($payslip): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="payslip-view?month=<?php echo urlencode($monthStr); ?>&user_id=<?php echo (int)($user['id'] ?? 0); ?>"><i class="bi bi-eye"></i> Open</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">Payroll status: <span class="fw-semibold"><?php echo $payrollLocked ? 'Locked' : 'Open'; ?></span></div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="hr-kpi h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="fw-semibold"><i class="bi bi-cash-stack me-1"></i> Quick Links</div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <a class="btn btn-outline-primary btn-sm" href="../productivity/productivity"><i class="bi bi-graph-up-arrow"></i> Productivity</a>
                                            <a class="btn btn-outline-secondary btn-sm" href="../productivity/incentives"><i class="bi bi-coin"></i> Incentives</a>
                                            <?php if ($isAdmin): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="salary-setup"><i class="bi bi-wallet2"></i> Salary Setup</a>
                                                <a class="btn btn-outline-secondary btn-sm" href="bonus-loans"><i class="bi bi-gift"></i> Bonus & Loans</a>
                                                <a class="btn btn-outline-secondary btn-sm" href="shifts"><i class="bi bi-clock-history"></i> Shifts</a>
                                                <a class="btn btn-outline-danger btn-sm" href="payroll"><i class="bi bi-calculator"></i> Payroll</a>
                                                <a class="btn btn-outline-secondary btn-sm" href="payroll-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> Payroll CSV</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 hr-kpi-sub">US holidays this month: <span class="fw-semibold"><?php echo number_format(count($holidaysThisMonth)); ?></span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card hr-card mb-3">
                                <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-calendar-event me-1"></i> Upcoming Holidays</span>
                                    <span class="badge bg-secondary"><?php echo number_format(count($upcoming)); ?></span>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($upcoming)): ?>
                                        <div class="text-muted">No upcoming holidays.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0 hr-table">
                                                <tbody>
                                                    <?php foreach ($upcoming as $h): ?>
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
                            <?php if ($isAdmin): ?>
                            <div class="card hr-card">
                                <div class="card-header bg-light fw-semibold"><i class="bi bi-lightning-charge me-1"></i> Quick Exports</div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <a class="btn btn-outline-primary btn-sm" href="attendance-export?date=<?php echo urlencode($today); ?>"><i class="bi bi-download"></i> Attendance (Today)</a>
                                        <a class="btn btn-outline-primary btn-sm" href="attendance-monthly-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> Attendance (Month)</a>
                                        <a class="btn btn-outline-primary btn-sm" href="payroll-export?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-download"></i> Payroll Summary</a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
