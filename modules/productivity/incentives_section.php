<?php
if (!isset($s) || !is_array($s)) { http_response_code(404); exit; }
$target = isset($s['target']) && is_array($s['target']) ? $s['target'] : [];
$period = isset($s['period']) && is_array($s['period']) ? $s['period'] : [];
$stats = isset($s['stats']) && is_array($s['stats']) ? $s['stats'] : [];
$incentives = isset($s['incentives']) && is_array($s['incentives']) ? $s['incentives'] : [];
$dailyRows = isset($s['daily']) && is_array($s['daily']) ? $s['daily'] : [];

$overallPercent = (float)($stats['overall_percent'] ?? 0);
$daysMetDaily = (int)($stats['days_met_daily'] ?? 0);
$daysElapsed = (int)($period['days_elapsed'] ?? 0);

$dailyIncTotal = (int)($incentives['daily_total'] ?? 0);
$monthlyInc = (int)($incentives['monthly_bonus'] ?? 0);
$totalInc = (int)($incentives['total'] ?? 0);
?>

<?php if (empty($target)): ?>
    <div class="alert alert-warning">No target assigned for this month yet.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-info-circle me-1"></i> Incentive Rules</div>
                <div class="card-body">
                    <div class="fw-semibold mb-2">Daily</div>
                    <ul class="mb-3">
                        <li>Rs. 500 when Daily % reaches 100%.</li>
                        <li>Extra bonus starts only after hitting 100% for the day.</li>
                        <li>Extra rates: MQL Rs. 100, BANT Rs. 250, Appointment Rs. 1,000.</li>
                        <li>Email Marketing has no extra-per-lead bonus.</li>
                    </ul>
                    <div class="fw-semibold mb-2">Monthly</div>
                    <ul class="mb-0">
                        <li>Rs. 10,000 when Overall % is 90% or more.</li>
                        <li>Overall % is based on Working Days (Mon–Fri excluding US holidays).</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-receipt me-1"></i> Month Totals</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Daily Incentives</div>
                                <div class="fs-5 fw-semibold text-success">Rs. <?php echo number_format($dailyIncTotal, 0); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Monthly Incentive</div>
                                <div class="fs-5 fw-semibold">Rs. <?php echo number_format($monthlyInc, 0); ?></div>
                                <div class="text-muted small">Overall: <?php echo number_format($overallPercent, 1); ?>%</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Total Incentives</div>
                                <div class="fs-5 fw-semibold">Rs. <?php echo number_format($totalInc, 0); ?></div>
                                <div class="text-muted small">Days Met: <?php echo number_format($daysMetDaily); ?> / <?php echo number_format($daysElapsed); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 small text-muted">
                        Base = Rs. 500 when Met. Extra = bonus after reaching target. Total = Base + Extra.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-list-check me-1"></i> Daily Incentive Breakdown</div>
        <div class="card-body">
            <?php if (empty($dailyRows)): ?>
                <div class="text-muted">No activity recorded yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Email</th>
                                <th class="text-end">MQL</th>
                                <th class="text-end">BANT</th>
                                <th class="text-end">Appt</th>
                                <th class="text-end">%</th>
                                <th class="text-center">Met</th>
                                <th class="text-end">Base</th>
                                <th class="text-end">Extra</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                                $sumEmail = 0;
                                $sumMql = 0;
                                $sumBant = 0;
                                $sumAppt = 0;
                                $sumBase = 0;
                                $sumExtra = 0;
                                $sumTotal = 0;
                            ?>
                            <?php foreach ($dailyRows as $row): ?>
                                <?php
                                    $date = (string)($row['date'] ?? '');
                                    $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
                                    $email = (int)($counts['Email Marketing'] ?? 0);
                                    $mql = (int)($counts['Marketing Qualified Leads'] ?? 0);
                                    $bant = (int)($counts['BANT'] ?? 0);
                                    $appt = (int)($counts['Appointment Generation'] ?? 0);
                                    $pct = (float)($row['daily_percent'] ?? 0);
                                    $met = !empty($row['met_daily_target']);
                                    $base = (int)($row['base_incentive'] ?? 0);
                                    $extra = (int)($row['extra_incentive'] ?? 0);
                                    $total = (int)($row['daily_incentive'] ?? 0);
                                    $sumEmail += $email;
                                    $sumMql += $mql;
                                    $sumBant += $bant;
                                    $sumAppt += $appt;
                                    $sumBase += $base;
                                    $sumExtra += $extra;
                                    $sumTotal += $total;
                                    $extraCounts = is_array($row['extra_counts'] ?? null) ? $row['extra_counts'] : [];
                                    $extraText = [];
                                    foreach ([
                                        'Marketing Qualified Leads' => 'MQL',
                                        'BANT' => 'BANT',
                                        'Appointment Generation' => 'Appt',
                                    ] as $k => $label) {
                                        $c = (int)($extraCounts[$k] ?? 0);
                                        if ($c > 0) $extraText[] = $label . ': ' . $c;
                                    }
                                ?>
                                <tr>
                                    <td class="font-monospace"><?php echo htmlspecialchars($date); ?></td>
                                    <td class="text-end"><?php echo $email; ?></td>
                                    <td class="text-end"><?php echo $mql; ?></td>
                                    <td class="text-end"><?php echo $bant; ?></td>
                                    <td class="text-end"><?php echo $appt; ?></td>
                                    <td class="text-end"><?php echo number_format($pct, 1); ?>%</td>
                                    <td class="text-center">
                                        <?php if ($met): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">Rs. <?php echo number_format($base, 0); ?></td>
                                    <td class="text-end">
                                        Rs. <?php echo number_format($extra, 0); ?>
                                        <?php if (!empty($extraText)): ?>
                                            <div class="text-muted small"><?php echo htmlspecialchars(implode(' · ', $extraText)); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-semibold">Rs. <?php echo number_format($total, 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold">
                                <td>Total</td>
                                <td class="text-end"><?php echo $sumEmail; ?></td>
                                <td class="text-end"><?php echo $sumMql; ?></td>
                                <td class="text-end"><?php echo $sumBant; ?></td>
                                <td class="text-end"><?php echo $sumAppt; ?></td>
                                <td class="text-end"><?php echo number_format($overallPercent, 1); ?>%</td>
                                <td class="text-center"><?php echo number_format($daysMetDaily); ?></td>
                                <td class="text-end">Rs. <?php echo number_format($sumBase, 0); ?></td>
                                <td class="text-end">Rs. <?php echo number_format($sumExtra, 0); ?></td>
                                <td class="text-end">Rs. <?php echo number_format($sumTotal, 0); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
