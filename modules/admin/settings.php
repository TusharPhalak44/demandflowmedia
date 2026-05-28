<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();
ensureDatabaseSchema();
ensureAppSettingsSchema();
ensureUserIpAccessSchema();

$conn = getDbConnection();
$message = '';
$messageType = 'success';

$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_global_security') {
            $enabled = !empty($_POST['ip_access_enabled']) ? '1' : '0';
            $trust = !empty($_POST['ip_access_trust_xff']) ? '1' : '0';
            $localBypass = !empty($_POST['ip_access_localhost_bypass']) ? '1' : '0';
            $bypassRoles = trim((string)($_POST['ip_access_bypass_roles'] ?? ''));
            setAppSetting('security.ip_access.enabled', $enabled);
            setAppSetting('security.ip_access.trust_xff', $trust);
            setAppSetting('security.ip_access.allow_localhost_bypass', $localBypass);
            setAppSetting('security.ip_access.bypass_roles', $bypassRoles);
            $message = 'Settings saved.';
            $messageType = 'success';
        } elseif ($action === 'save_ui') {
            $theme = (string)($_POST['ui_theme_default'] ?? 'light');
            $theme = $theme === 'dark' ? 'dark' : 'light';
            $allowOverride = !empty($_POST['ui_allow_user_override']) ? '1' : '0';
            $normalizeHex = function($v, string $fallback): string {
                $v = trim((string)$v);
                if ($v === '') return $fallback;
                if ($v[0] !== '#') $v = '#' . $v;
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) return $fallback;
                return strtoupper($v);
            };
            $accentLight = $normalizeHex($_POST['ui_accent_light'] ?? '', '#0EA5E9');
            $accentDark = $normalizeHex($_POST['ui_accent_dark'] ?? '', '#00FFFF');
            setAppSetting('ui.theme.default', $theme);
            setAppSetting('ui.theme.allow_user_override', $allowOverride);
            setAppSetting('ui.theme.accent_light', $accentLight);
            setAppSetting('ui.theme.accent_dark', $accentDark);
            $message = 'UI settings saved.';
            $messageType = 'success';
        } elseif ($action === 'save_attendance_policy') {
            $grace = (int)($_POST['attendance_grace_minutes'] ?? 10);
            $halfAt = (int)($_POST['attendance_late_halfday_at'] ?? 3);
            $absAt = (int)($_POST['attendance_late_absent_at'] ?? 4);
            if ($grace < 0) $grace = 0;
            if ($grace > 120) $grace = 120;
            if ($halfAt < 1) $halfAt = 1;
            if ($halfAt > 31) $halfAt = 31;
            if ($absAt < $halfAt) $absAt = $halfAt;
            if ($absAt > 31) $absAt = 31;
            setAppSetting('attendance.grace_minutes', (string)$grace);
            setAppSetting('attendance.late_halfday_at', (string)$halfAt);
            setAppSetting('attendance.late_absent_at', (string)$absAt);
            $message = 'Attendance policy saved.';
            $messageType = 'success';
        } elseif ($action === 'save_invoice_numbering') {
            $mode = (string)($_POST['invoice_numbering_mode'] ?? 'sequence');
            if (!in_array($mode, ['sequence','legacy'], true)) $mode = 'sequence';
            setAppSetting('invoice.numbering.mode', $mode);
            setAppSetting('invoice.numbering.prefix', (string)($_POST['invoice_prefix'] ?? 'INV'));
            setAppSetting('invoice.numbering.separator', (string)($_POST['invoice_separator'] ?? '-'));
            setAppSetting('invoice.numbering.padding', (string)(int)($_POST['invoice_padding'] ?? 4));
            setAppSetting('invoice.numbering.reset_monthly', !empty($_POST['invoice_reset_monthly']) ? '1' : '0');
            setAppSetting('invoice.numbering.date_format', (string)($_POST['invoice_date_format'] ?? 'Ym'));
            $message = 'Invoice numbering saved.';
            $messageType = 'success';
        } elseif ($action === 'save_lead_rules') {
            setAppSetting('leads.lifecycle.qa_statuses', trim((string)($_POST['qa_statuses'] ?? '')));
            setAppSetting('leads.lifecycle.delivery_statuses', trim((string)($_POST['delivery_statuses'] ?? '')));
            setAppSetting('leads.lifecycle.defaults', trim((string)($_POST['lead_defaults'] ?? '')));
            $message = 'Lead lifecycle rules saved.';
            $messageType = 'success';
        } elseif ($action === 'save_notifications') {
            setAppSetting('notifications.in_app.enabled', !empty($_POST['notif_in_app']) ? '1' : '0');
            setAppSetting('notifications.sound.enabled', !empty($_POST['notif_sound']) ? '1' : '0');
            $message = 'Notification settings saved.';
            $messageType = 'success';
        } elseif ($action === 'save_notification_defaults') {
            $eventTypes = [
                'campaign.created' => 'New campaign created',
                'campaign.assigned' => 'Campaign allocated',
                'campaign.end_warning' => 'Campaign end date warning',
                'campaign.pacing_risk' => 'Low leads pacing alert',
                'campaign.updated' => 'Campaign updated',
                'lead.created' => 'New lead uploaded',
                'lead.updated' => 'Lead updated',
                'lead.status_updated' => 'Lead status updated',
                'chat.message' => 'New chat message',
                'chat.group_message' => 'New group message',
                'sales.followup_reminder' => 'Sales follow-up reminder',
                'invoice.created' => 'Invoice created',
                'invoice.status_changed' => 'Invoice status updated',
                'invoice.paid' => 'Invoice marked paid',
            ];
            $defaults = [];
            foreach ($eventTypes as $type => $_label) {
                $enabled = !empty($_POST['def_enabled'][$type]) ? 1 : 0;
                $mode = (string)($_POST['def_mode'][$type] ?? 'instant');
                if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                $toast = !empty($_POST['def_toast'][$type]) ? 1 : 0;
                $defaults[$type] = ['enabled' => $enabled, 'mode' => $mode, 'toast' => $toast];
            }
            $ok = setAppSetting('notifications.default_prefs', json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $message = $ok ? 'Default notification preferences saved.' : 'Failed to save defaults.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'apply_notification_defaults') {
            $overwrite = !empty($_POST['overwrite_existing']) ? 1 : 0;
            $raw = (string)(getAppSetting('notifications.default_prefs', '') ?? '');
            $defs = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($defs) || empty($defs)) {
                $message = 'Save default preferences first.';
                $messageType = 'danger';
            } else {
                $usersRs = $conn->query("SELECT id FROM users WHERE is_active = 1");
                $userIds = [];
                if ($usersRs) {
                    foreach ($usersRs->fetch_all(MYSQLI_ASSOC) ?: [] as $r) {
                        $uid = (int)($r['id'] ?? 0);
                        if ($uid > 0) $userIds[] = $uid;
                    }
                }
                $applied = 0;
                if (!empty($userIds)) {
                    if ($overwrite) {
                        $stmt = $conn->prepare("
                            INSERT INTO notification_preferences (user_id, type, delivery_mode, is_enabled, show_toast)
                            VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                delivery_mode=VALUES(delivery_mode),
                                is_enabled=VALUES(is_enabled),
                                show_toast=VALUES(show_toast),
                                updated_at=NOW()
                        ");
                        if ($stmt) {
                            foreach ($userIds as $uid) {
                                foreach ($defs as $type => $cfg) {
                                    $t = (string)$type;
                                    if ($t === '') continue;
                                    $en = (int)($cfg['enabled'] ?? 1);
                                    $mode = (string)($cfg['mode'] ?? 'instant');
                                    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                                    $toast = (int)($cfg['toast'] ?? 0);
                                    $stmt->bind_param('issii', $uid, $t, $mode, $en, $toast);
                                    if ($stmt->execute()) $applied++;
                                }
                            }
                            $stmt->close();
                        }
                    } else {
                        $stmt = $conn->prepare("
                            INSERT IGNORE INTO notification_preferences (user_id, type, delivery_mode, is_enabled, show_toast)
                            VALUES (?,?,?,?,?)
                        ");
                        if ($stmt) {
                            foreach ($userIds as $uid) {
                                foreach ($defs as $type => $cfg) {
                                    $t = (string)$type;
                                    if ($t === '') continue;
                                    $en = (int)($cfg['enabled'] ?? 1);
                                    $mode = (string)($cfg['mode'] ?? 'instant');
                                    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                                    $toast = (int)($cfg['toast'] ?? 0);
                                    $stmt->bind_param('issii', $uid, $t, $mode, $en, $toast);
                                    if ($stmt->execute() && $stmt->affected_rows > 0) $applied++;
                                }
                            }
                            $stmt->close();
                        }
                    }
                }
                $message = 'Defaults applied: ' . number_format($applied) . ($overwrite ? ' (overwritten)' : ' (only missing)');
                $messageType = 'success';
            }
        } elseif ($action === 'save_dashboard_banners') {
            setAppSetting('dashboard.banner.incentives.enabled', !empty($_POST['banner_incentives_enabled']) ? '1' : '0');
            setAppSetting('dashboard.banner.incentives.items', trim((string)($_POST['banner_incentives_items'] ?? '')));
            setAppSetting('dashboard.banner.birthdays.enabled', !empty($_POST['banner_birthdays_enabled']) ? '1' : '0');
            setAppSetting('dashboard.banner.anniversaries.enabled', !empty($_POST['banner_anniversaries_enabled']) ? '1' : '0');
            $message = 'Dashboard banners saved.';
            $messageType = 'success';
        } elseif ($action === 'save_user_ip') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $mode = (string)($_POST['mode'] ?? 'open');
            $ips = (string)($_POST['allowed_ips'] ?? '');
            if ($uid <= 0) {
                $message = 'Select a user.';
                $messageType = 'danger';
            } else {
                $ok = setUserIpAccessPolicy($uid, $mode, $ips);
                $message = $ok ? 'User IP policy saved.' : 'Failed to save user policy.';
                $messageType = $ok ? 'success' : 'danger';
                $selectedUserId = $uid;
            }
        } elseif ($action === 'maintenance_clear_cache') {
            $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
            $tmpDir = $projectRoot . DIRECTORY_SEPARATOR . 'tmp';
            $sessionDir = $tmpDir . DIRECTORY_SEPARATOR . 'sessions';
            $keepSession = $sessionDir . DIRECTORY_SEPARATOR . 'sess_' . session_id();

            $deletedSessions = 0;
            $deletedTmpFiles = 0;
            $opcacheReset = null;

            if (!empty($_POST['clear_tmp_reports'])) {
                $patterns = [
                    $tmpDir . DIRECTORY_SEPARATOR . '*.md',
                    $tmpDir . DIRECTORY_SEPARATOR . '*.log',
                    $tmpDir . DIRECTORY_SEPARATOR . '*.tmp',
                ];
                foreach ($patterns as $p) {
                    foreach (glob($p) ?: [] as $f) {
                        if (is_file($f) && @unlink($f)) $deletedTmpFiles++;
                    }
                }
            }

            if (!empty($_POST['clear_sessions'])) {
                foreach (glob($sessionDir . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $f) {
                    if (!is_file($f)) continue;
                    if (strcasecmp($f, $keepSession) === 0) continue;
                    if (@unlink($f)) $deletedSessions++;
                }
            }

            if (!empty($_POST['reset_opcache'])) {
                if (function_exists('opcache_reset')) {
                    $opcacheReset = (bool)@opcache_reset();
                } else {
                    $opcacheReset = false;
                }
            }

            $messageParts = [];
            if (!empty($_POST['clear_tmp_reports'])) $messageParts[] = 'Temp files deleted: ' . number_format($deletedTmpFiles);
            if (!empty($_POST['clear_sessions'])) $messageParts[] = 'Session files deleted: ' . number_format($deletedSessions);
            if (!empty($_POST['reset_opcache'])) $messageParts[] = 'OPcache reset: ' . ($opcacheReset ? 'Yes' : 'No');
            $message = !empty($messageParts) ? implode(' · ', $messageParts) : 'No cleanup option selected.';
            $messageType = !empty($messageParts) ? 'success' : 'danger';
        }
    }
}

$projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$tmpDir = $projectRoot . DIRECTORY_SEPARATOR . 'tmp';
$sessionDir = $tmpDir . DIRECTORY_SEPARATOR . 'sessions';
$sessionFilesCount = count(glob($sessionDir . DIRECTORY_SEPARATOR . 'sess_*') ?: []);
$tmpReportFilesCount = 0;
foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $f) {
    if (is_file($f)) $tmpReportFilesCount++;
}
$opcacheAvailable = function_exists('opcache_reset');

$ipEnabled = (string)(getAppSetting('security.ip_access.enabled', '0') ?? '0') === '1';
$trustXff = (string)(getAppSetting('security.ip_access.trust_xff', '0') ?? '0') === '1';
$ipLocalhostBypass = (string)(getAppSetting('security.ip_access.allow_localhost_bypass', '0') ?? '0') === '1';
$ipBypassRoles = (string)(getAppSetting('security.ip_access.bypass_roles', '') ?? '');
$detectedRemoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$detectedXff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
$detectedXffFirst = '';
if ($detectedXff !== '') {
    $parts = array_map('trim', explode(',', $detectedXff));
    $detectedXffFirst = (string)($parts[0] ?? '');
}
$uiThemeDefault = (string)(getAppSetting('ui.theme.default', 'light') ?? 'light');
$uiThemeDefault = $uiThemeDefault === 'dark' ? 'dark' : 'light';
$uiAllowOverride = (string)(getAppSetting('ui.theme.allow_user_override', '1') ?? '1') === '1';
$uiAccentLight = (string)(getAppSetting('ui.theme.accent_light', '#0EA5E9') ?? '#0EA5E9');
$uiAccentDark = (string)(getAppSetting('ui.theme.accent_dark', '#00FFFF') ?? '#00FFFF');
$attendancePolicy = getAttendancePolicySettings();
$invMode = (string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence');
if (!in_array($invMode, ['sequence','legacy'], true)) $invMode = 'sequence';
$invSet = getInvoiceNumberingSettings();
$leadQaStatuses = (string)(getAppSetting('leads.lifecycle.qa_statuses', '') ?? '');
$leadDeliveryStatuses = (string)(getAppSetting('leads.lifecycle.delivery_statuses', '') ?? '');
$leadDefaults = (string)(getAppSetting('leads.lifecycle.defaults', '') ?? '');
$notifInApp = (string)(getAppSetting('notifications.in_app.enabled', '1') ?? '1') === '1';
$notifSound = (string)(getAppSetting('notifications.sound.enabled', '0') ?? '0') === '1';
$notifDefaultPrefsRaw = (string)(getAppSetting('notifications.default_prefs', '') ?? '');
$notifDefaultPrefs = $notifDefaultPrefsRaw !== '' ? json_decode($notifDefaultPrefsRaw, true) : null;
if (!is_array($notifDefaultPrefs)) $notifDefaultPrefs = [];
$notifEventTypes = [
    'campaign.created' => 'New campaign created',
    'campaign.assigned' => 'Campaign allocated',
    'campaign.end_warning' => 'Campaign end date warning',
    'campaign.pacing_risk' => 'Low leads pacing alert',
    'campaign.updated' => 'Campaign updated',
    'lead.created' => 'New lead uploaded',
    'lead.updated' => 'Lead updated',
    'lead.status_updated' => 'Lead status updated',
    'chat.message' => 'New chat message',
    'chat.group_message' => 'New group message',
    'sales.followup_reminder' => 'Sales follow-up reminder',
    'invoice.created' => 'Invoice created',
    'invoice.status_changed' => 'Invoice status updated',
    'invoice.paid' => 'Invoice marked paid',
];
$bannerIncentivesEnabled = (string)(getAppSetting('dashboard.banner.incentives.enabled', '1') ?? '1') === '1';
$bannerIncentivesItems = (string)(getAppSetting('dashboard.banner.incentives.items', '') ?? '');
$bannerBirthdaysEnabled = (string)(getAppSetting('dashboard.banner.birthdays.enabled', '1') ?? '1') === '1';
$bannerAnniversariesEnabled = (string)(getAppSetting('dashboard.banner.anniversaries.enabled', '1') ?? '1') === '1';

$users = [];
$rs = $conn->query("SELECT id, full_name, role, is_active FROM users WHERE is_active = 1 ORDER BY full_name");
if ($rs) $users = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$userLabelById = [];
foreach ($users as $u) {
    $uid = (int)($u['id'] ?? 0);
    if ($uid > 0) $userLabelById[$uid] = (string)($u['full_name'] ?? '') . ' · ' . (string)($u['role'] ?? '');
}

$selectedPolicy = $selectedUserId > 0 ? getUserIpAccessPolicy($selectedUserId) : ['mode' => 'open', 'allowed_ips' => []];
$selectedAllowed = is_array($selectedPolicy['allowed_ips'] ?? null) ? implode("\n", $selectedPolicy['allowed_ips']) : '';

$policyRows = [];
$rs = $conn->query("
    SELECT p.user_id, p.mode, p.allowed_ips, p.updated_at, u.full_name, u.role
    FROM user_ip_access p
    LEFT JOIN users u ON u.id = p.user_id
    ORDER BY p.updated_at DESC
    LIMIT 200
");
if ($rs) $policyRows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$pageTitle = 'Settings';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <div class="h3 mb-1">System Settings</div>
            <div class="text-muted small">Manage global application configurations and integrations</div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm d-flex align-items-center mb-4">
            <i class="bi bi-info-circle-fill me-2 fs-5"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Sidebar Navigation -->
        <div class="col-md-3 mb-4">
            <div class="card border-0 shadow-sm sticky-top" style="top: 1rem; z-index: 10;">
                <div class="card-body p-2">
                    <ul class="nav nav-pills flex-column gap-1" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active text-start px-3 py-2 w-100 rounded" data-bs-toggle="tab" data-bs-target="#tab-security" type="button" role="tab">
                                <i class="bi bi-shield-lock me-2"></i>Security
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 rounded" data-bs-toggle="tab" data-bs-target="#tab-ui" type="button" role="tab">
                                <i class="bi bi-palette2 me-2"></i>Appearance & UI
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 rounded" data-bs-toggle="tab" data-bs-target="#tab-ops" type="button" role="tab">
                                <i class="bi bi-sliders me-2"></i>Operations
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 rounded" data-bs-toggle="tab" data-bs-target="#tab-billing" type="button" role="tab">
                                <i class="bi bi-receipt-cutoff me-2"></i>Billing
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 rounded" data-bs-toggle="tab" data-bs-target="#tab-maintenance" type="button" role="tab">
                                <i class="bi bi-wrench-adjustable-circle me-2"></i>Maintenance
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="col-md-9">
            <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-security" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-shield-lock me-1"></i>Security</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_global_security">
                                <div class="col-12">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="fw-semibold mb-1"><i class="bi bi-router me-1"></i>Your current request IP</div>
                                        <div class="small text-muted">
                                            Remote: <span class="fw-semibold text-dark"><?php echo htmlspecialchars($detectedRemoteIp !== '' ? $detectedRemoteIp : 'unknown'); ?></span>
                                            <?php if ($detectedXffFirst !== ''): ?>
                                                · XFF: <span class="fw-semibold text-dark"><?php echo htmlspecialchars($detectedXffFirst); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-muted mt-1">Tip: If you open via http://localhost, Remote is usually 127.0.0.1. To test real LAN IP, open via http://YOUR-LAN-IP/leads</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ip_access_enabled" name="ip_access_enabled" value="1" <?php echo $ipEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ip_access_enabled">
                                            Enable user IP access control (Live CRM)
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-1">When enabled, users configured as Static IP will be blocked outside their allowed IP list.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ip_access_trust_xff" name="ip_access_trust_xff" value="1" <?php echo $trustXff ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ip_access_trust_xff">
                                            Trust X-Forwarded-For header (proxy/VPN)
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-1">Enable only if you are behind a trusted reverse proxy that sets X-Forwarded-For.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ip_access_localhost_bypass" name="ip_access_localhost_bypass" value="1" <?php echo $ipLocalhostBypass ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ip_access_localhost_bypass">
                                            Allow localhost bypass (127.0.0.1 / ::1)
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-1">Recommended for development. Disable on production if you want strict blocking.</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Bypass roles (CSV)</label>
                                    <input class="form-control form-control-sm" name="ip_access_bypass_roles" value="<?php echo htmlspecialchars($ipBypassRoles); ?>" placeholder="e.g. admin,director">
                                    <div class="text-muted small mt-1">Leave empty to enforce IP rules for all roles (including admin).</div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2 me-1"></i>Save</button>
                                </div>
                            </form>

                            <hr class="my-4">

                            <div class="fw-semibold mb-2">User IP Access</div>
                            <form method="post" class="row g-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_user_ip">
                                <div class="col-12">
                                    <label class="form-label small text-muted">User</label>
                                    <select class="form-select form-select-sm" name="user_id" onchange="location.href='settings.php?user_id='+this.value">
                                        <option value="">Select user</option>
                                        <?php foreach ($users as $u): ?>
                                            <?php $uid = (int)($u['id'] ?? 0); ?>
                                            <option value="<?php echo $uid; ?>" <?php echo $uid === $selectedUserId ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string)($u['full_name'] ?? '') . ' · ' . (string)($u['role'] ?? '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Mode</label>
                                    <select class="form-select form-select-sm" name="mode" <?php echo $selectedUserId > 0 ? '' : 'disabled'; ?>>
                                        <option value="open" <?php echo (($selectedPolicy['mode'] ?? 'open') === 'open') ? 'selected' : ''; ?>>Open</option>
                                        <option value="static" <?php echo (($selectedPolicy['mode'] ?? 'open') === 'static') ? 'selected' : ''; ?>>Static IP</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small text-muted">Allowed IPs (one per line)</label>
                                    <textarea class="form-control form-control-sm" name="allowed_ips" rows="3" placeholder="e.g. 203.0.113.10&#10;or 203.0.113.0/24&#10;or 192.168.1.*" <?php echo $selectedUserId > 0 ? '' : 'disabled'; ?>><?php echo htmlspecialchars($selectedAllowed); ?></textarea>
                                    <div class="text-muted small mt-1">Supports exact IP, CIDR (x.x.x.x/24), and wildcard (192.168.1.*).</div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit" <?php echo $selectedUserId > 0 ? '' : 'disabled'; ?>><i class="bi bi-save2 me-1"></i>Save User Policy</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-ui" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-palette2 me-1"></i>Appearance</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_ui">
                                <div class="col-md-6">
                                    <label class="form-label">Default Theme</label>
                                    <select class="form-select form-select-sm" name="ui_theme_default">
                                        <option value="light" <?php echo $uiThemeDefault === 'light' ? 'selected' : ''; ?>>Light</option>
                                        <option value="dark" <?php echo $uiThemeDefault === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                    </select>
                                    <div class="text-muted small mt-1">Applied when user has no theme saved locally.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">User Override</label>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="ui_allow_user_override" name="ui_allow_user_override" value="1" <?php echo $uiAllowOverride ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ui_allow_user_override">Allow users to switch theme on their browser</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Accent Color (Light)</label>
                                    <input class="form-control form-control-sm form-control-color" type="color" name="ui_accent_light" value="<?php echo htmlspecialchars($uiAccentLight); ?>" title="Pick accent color for light theme">
                                    <div class="text-muted small mt-1">Updates neon borders, active rings, and primary buttons for Light theme.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Accent Color (Dark)</label>
                                    <input class="form-control form-control-sm form-control-color" type="color" name="ui_accent_dark" value="<?php echo htmlspecialchars($uiAccentDark); ?>" title="Pick accent color for dark theme">
                                    <div class="text-muted small mt-1">Updates neon borders, active rings, and primary buttons for Dark theme.</div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-bell me-1"></i>Notifications</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_notifications">
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notif_in_app" name="notif_in_app" value="1" <?php echo $notifInApp ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notif_in_app">Enable in-app notifications</label>
                                    </div>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="notif_sound" name="notif_sound" value="1" <?php echo $notifSound ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notif_sound">Enable notification sound</label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-sliders me-1"></i>Default Notification Preferences</div>
                        <div class="card-body">
                            <div class="text-muted small mb-3">These defaults are used when a user has not saved their own preferences.</div>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_notification_defaults">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Event</th>
                                                <th style="width:110px;" class="text-center">Enabled</th>
                                                <th style="width:160px;">Delivery</th>
                                                <th style="width:140px;" class="text-center">Popup (Toast)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($notifEventTypes as $type => $label): ?>
                                                <?php
                                                    $d = is_array($notifDefaultPrefs[$type] ?? null) ? $notifDefaultPrefs[$type] : null;
                                                    $enabled = $d ? ((int)($d['enabled'] ?? 1) === 1) : true;
                                                    $mode = $d ? (string)($d['mode'] ?? 'instant') : 'instant';
                                                    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                                                    $toast = $d ? ((int)($d['toast'] ?? 0) === 1) : in_array($type, ['campaign.end_warning','campaign.pacing_risk','sales.followup_reminder','chat.message','chat.group_message','lead.created','lead.updated'], true);
                                                ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars($label); ?><div class="text-muted small"><?php echo htmlspecialchars($type); ?></div></td>
                                                    <td class="text-center">
                                                        <input class="form-check-input" type="checkbox" name="def_enabled[<?php echo htmlspecialchars($type); ?>]" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                                                    </td>
                                                    <td>
                                                        <select class="form-select form-select-sm" name="def_mode[<?php echo htmlspecialchars($type); ?>]">
                                                            <option value="instant" <?php echo $mode === 'instant' ? 'selected' : ''; ?>>Instant</option>
                                                            <option value="digest" <?php echo $mode === 'digest' ? 'selected' : ''; ?>>Digest</option>
                                                        </select>
                                                    </td>
                                                    <td class="text-center">
                                                        <input class="form-check-input" type="checkbox" name="def_toast[<?php echo htmlspecialchars($type); ?>]" value="1" <?php echo $toast ? 'checked' : ''; ?>>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save Defaults</button>
                                </div>
                            </form>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="apply_notification_defaults">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border rounded p-3 bg-light">
                                    <div>
                                        <div class="fw-semibold">Apply defaults to users</div>
                                        <div class="text-muted small">Writes the defaults into user preferences (useful for existing users).</div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="overwrite_existing" name="overwrite_existing" value="1">
                                            <label class="form-check-label" for="overwrite_existing">Overwrite existing</label>
                                        </div>
                                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-upload me-1"></i>Apply</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-megaphone me-1"></i>Dashboard Banners</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_dashboard_banners">
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="banner_incentives_enabled" name="banner_incentives_enabled" value="1" <?php echo $bannerIncentivesEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="banner_incentives_enabled">Show incentive earners banner</label>
                                    </div>
                                    <label class="form-label mt-2">Today Earners (one per line: Name|Amount)</label>
                                    <textarea class="form-control form-control-sm" name="banner_incentives_items" rows="4" placeholder="Ali|500&#10;Sara|300"><?php echo htmlspecialchars($bannerIncentivesItems); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="banner_birthdays_enabled" name="banner_birthdays_enabled" value="1" <?php echo $bannerBirthdaysEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="banner_birthdays_enabled">Show birthdays banner</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="banner_anniversaries_enabled" name="banner_anniversaries_enabled" value="1" <?php echo $bannerAnniversariesEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="banner_anniversaries_enabled">Show work anniversaries banner</label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-ops" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-clock-history me-1"></i>Attendance Policy</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_attendance_policy">
                                <div class="col-md-4">
                                    <label class="form-label">Late Buffer (minutes)</label>
                                    <input class="form-control form-control-sm" type="number" name="attendance_grace_minutes" value="<?php echo (int)($attendancePolicy['grace_minutes'] ?? 10); ?>" min="0" max="120">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Half Day at (late count)</label>
                                    <input class="form-control form-control-sm" type="number" name="attendance_late_halfday_at" value="<?php echo (int)($attendancePolicy['late_halfday_at'] ?? 3); ?>" min="1" max="31">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Absent at (late count)</label>
                                    <input class="form-control form-control-sm" type="number" name="attendance_late_absent_at" value="<?php echo (int)($attendancePolicy['late_absent_at'] ?? 4); ?>" min="1" max="31">
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Lead Lifecycle</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_lead_rules">
                                <div class="col-12">
                                    <label class="form-label">QA Statuses (CSV or lines)</label>
                                    <textarea class="form-control form-control-sm" name="qa_statuses" rows="2" placeholder="Pending, Qualified, Disqualified"><?php echo htmlspecialchars($leadQaStatuses); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Delivery Statuses (CSV or lines)</label>
                                    <textarea class="form-control form-control-sm" name="delivery_statuses" rows="2" placeholder="Pending, Delivered"><?php echo htmlspecialchars($leadDeliveryStatuses); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Defaults (free text)</label>
                                    <textarea class="form-control form-control-sm" name="lead_defaults" rows="2" placeholder="Define default workflow rules"><?php echo htmlspecialchars($leadDefaults); ?></textarea>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-billing" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-receipt-cutoff me-1"></i>Invoice Numbering</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_invoice_numbering">
                                <div class="col-md-4">
                                    <label class="form-label">Mode</label>
                                    <select class="form-select form-select-sm" name="invoice_numbering_mode">
                                        <option value="sequence" <?php echo $invMode === 'sequence' ? 'selected' : ''; ?>>Sequence</option>
                                        <option value="legacy" <?php echo $invMode === 'legacy' ? 'selected' : ''; ?>>Legacy</option>
                                    </select>
                                    <div class="text-muted small mt-1">Sequence uses app settings counter.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Prefix</label>
                                    <input class="form-control form-control-sm" name="invoice_prefix" value="<?php echo htmlspecialchars((string)($invSet['prefix'] ?? 'INV')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Separator</label>
                                    <input class="form-control form-control-sm" name="invoice_separator" value="<?php echo htmlspecialchars((string)($invSet['separator'] ?? '-')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Padding</label>
                                    <input class="form-control form-control-sm" type="number" name="invoice_padding" value="<?php echo (int)($invSet['padding'] ?? 4); ?>" min="1" max="12">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Date Format</label>
                                    <input class="form-control form-control-sm" name="invoice_date_format" value="<?php echo htmlspecialchars((string)($invSet['date_format'] ?? 'Ym')); ?>">
                                    <div class="text-muted small mt-1">PHP date() format, e.g. Ym, Y-m.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Reset Monthly</label>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="invoice_reset_monthly" name="invoice_reset_monthly" value="1" <?php echo !empty($invSet['reset_monthly']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="invoice_reset_monthly">Reset counter each month</label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-maintenance" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-wrench-adjustable-circle me-1"></i>Maintenance</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="fw-semibold mb-1">Cache / Temp Summary</div>
                                        <div class="small text-muted d-flex flex-wrap gap-2">
                                            <span class="badge bg-light text-dark border">Sessions: <?php echo number_format($sessionFilesCount); ?></span>
                                            <span class="badge bg-light text-dark border">Temp reports: <?php echo number_format($tmpReportFilesCount); ?></span>
                                            <span class="badge bg-light text-dark border">OPcache: <?php echo $opcacheAvailable ? 'Available' : 'Not available'; ?></span>
                                        </div>
                                        <div class="small text-muted mt-2">Clearing sessions logs out other users. Temp cleanup removes audit files stored in /tmp.</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="fw-semibold mb-1">Data Reset</div>
                                        <div class="small text-muted mb-2">Delete campaigns/forms/leads data and drop campaign lead tables for clean testing.</div>
                                        <?php if (isAdmin() || hasRole(['director','manager_director','operations_director','operations_manager'])): ?>
                                            <a class="btn btn-outline-danger btn-sm" href="data-reset.php">
                                                <i class="bi bi-exclamation-triangle me-1"></i>Open Data Reset
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Not permitted.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="fw-semibold mb-1">Access Management</div>
                                        <div class="small text-muted mb-2">Configure role permissions for modules that support permission checks.</div>
                                        <?php if (isAdmin()): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="access-management.php">
                                                <i class="bi bi-shield-lock me-1"></i>Open Access Management
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Not permitted.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="maintenance_clear_cache">
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="clear_tmp_reports" name="clear_tmp_reports" value="1" checked>
                                                <label class="form-check-label" for="clear_tmp_reports">Delete temp report files (tmp/*.md)</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="clear_sessions" name="clear_sessions" value="1">
                                                <label class="form-check-label" for="clear_sessions">Clear session cache (logs out other users)</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="reset_opcache" name="reset_opcache" value="1" <?php echo $opcacheAvailable ? '' : 'disabled'; ?>>
                                                <label class="form-check-label" for="reset_opcache">Reset PHP OPcache</label>
                                            </div>
                                        </div>
                                        <div class="col-12 d-flex justify-content-end">
                                            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash3 me-1"></i>Run Cleanup</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
        </div> <!-- End of col-md-9 -->
    </div> <!-- End of row -->
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
