<?php
/**
 * Authentication System
 * 
 * Handles user authentication, session management, and role-based access control
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Set secure session parameters before starting session
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set('session.save_handler', 'files');
    ini_set('session.upload_progress.enabled', '0');

    $projectRoot = dirname(__DIR__);
    $fallback = $projectRoot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0775, true);
    }
    $uploadTmp = $projectRoot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadTmp)) {
        @mkdir($uploadTmp, 0775, true);
    }
    ini_set('upload_tmp_dir', $uploadTmp);
    $iniUploadTmp = trim((string)ini_get('upload_tmp_dir'));
    if ($iniUploadTmp !== '') {
        $p = $iniUploadTmp;
        if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $p) && preg_match('/^[\\\\\\/]/', $p)) {
            $root = realpath($projectRoot) ?: $projectRoot;
            $drive = substr($root, 0, 2);
            if (preg_match('/^[A-Za-z]:$/', $drive)) {
                $p = $drive . $p;
            }
        }
        if (!is_dir($p)) {
            @mkdir($p, 0777, true);
        }
    }
    ini_set('session.save_path', $fallback);
    session_save_path($fallback);
    $sessionOptions = ['save_path' => $fallback];
    
    session_start($sessionOptions);
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutes
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

/**
 * Detect if the current request is an AJAX/XHR request
 * @return bool
 */
function isAjaxRequest(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
}

// Include database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isAjaxRequest()) {
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $pathLower = strtolower($path);
    $isAuthPage = str_contains($pathLower, '/modules/auth/');
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
    if (!$isAuthPage && $userId > 0 && $postedCsrf !== '' && $sessionCsrf !== '' && hash_equals($sessionCsrf, $postedCsrf)) {
        $filesSig = [];
        foreach ($_FILES as $k => $f) {
            if (!is_array($f)) continue;
            if (is_array($f['name'] ?? null)) {
                $filesSig[$k] = ['multi' => true, 'count' => is_array($f['name']) ? count($f['name']) : 0];
            } else {
                $filesSig[$k] = [
                    'name' => (string)($f['name'] ?? ''),
                    'size' => (int)($f['size'] ?? 0),
                    'error' => (int)($f['error'] ?? 0),
                    'type' => (string)($f['type'] ?? ''),
                ];
            }
        }
        $postCopy = $_POST;
        unset($postCopy['csrf_token']);
        $keyBase = json_encode([
            'u' => $userId,
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'post' => $postCopy,
            'files' => $filesSig,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', (string)$keyBase);
        $now = time();
        if (!isset($_SESSION['__recent_posts']) || !is_array($_SESSION['__recent_posts'])) {
            $_SESSION['__recent_posts'] = [];
        }
        foreach ($_SESSION['__recent_posts'] as $h => $ts) {
            if (!is_int($ts) || ($now - $ts) > 600) unset($_SESSION['__recent_posts'][$h]);
        }
        if (isset($_SESSION['__recent_posts'][$hash]) && ($now - (int)$_SESSION['__recent_posts'][$hash]) < 15) {
            if (!isset($_SESSION['app_flash_toasts']) || !is_array($_SESSION['app_flash_toasts'])) $_SESSION['app_flash_toasts'] = [];
            $_SESSION['app_flash_toasts'][] = ['type' => 'warning', 'title' => 'Duplicate submission', 'message' => 'This form was already submitted.'];
            $redirect = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $redirect = str_replace(["\r", "\n"], '', $redirect);
            header('Location: ' . $redirect);
            exit;
        }
        $_SESSION['__recent_posts'][$hash] = $now;
        if (count($_SESSION['__recent_posts']) > 80) {
            asort($_SESSION['__recent_posts']);
            $_SESSION['__recent_posts'] = array_slice($_SESSION['__recent_posts'], -60, 60, true);
        }
    }
}

// Ensure database schema is up to date (once per session to minimize overhead)
if (!isset($_SESSION['schema_verified_v3'])) {
    ensureDatabaseSchema();
    $_SESSION['schema_verified_v3'] = true;
}

/**
 * Authenticate user with username and password
 * 
 * @param string $username Username
 * @param string $password Plain text password
 * @return array|bool User data array on success, false on failure
 */
function loginUser($username, $password) {
    $conn = getDbConnection();
    
    // Input validation
    if (empty($username) || empty($password)) {
        return false;
    }
    
    // Rate limiting - prevent brute force attacks
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $attempts = 0;
    // Count recent attempts for this IP and username within 15 minutes
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE ip_address = ? AND username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    if ($stmt) {
        $stmt->bind_param("ss", $ip, $username);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                if ($row && isset($row['attempts'])) {
                    $attempts = (int)$row['attempts'];
                }
            }
        }
        $stmt->close();
    } else {
        // If the table doesn't exist or prepare fails, default to allowing attempts
        error_log("login_attempts check prepare failed: " . $conn->error);
    }
    
    if ($attempts >= 5) {
        // Log the blocked attempt
        error_log("Login blocked for IP $ip and username $username due to too many attempts");
        // Persist error reason for UI feedback
        $_SESSION['login_error'] = 'too_many_attempts';
        return false;
    }
    
    // Prepare statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    if (!$stmt) {
        error_log("users lookup prepare failed: " . $conn->error);
        $_SESSION['login_error'] = 'system_error';
        return false;
    }
    $stmt->bind_param("s", $username);
    if (!$stmt->execute()) {
        error_log("users lookup execute failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            $enabled = (string)(getAppSetting('security.ip_access.enabled', '0') ?? '0');
            if ($enabled === '1') {
                $uid = (int)($user['id'] ?? 0);
                $role = (string)($user['role'] ?? '');
                if ($uid > 0) {
                    $policy = getUserIpAccessPolicy($uid);
                    if (($policy['mode'] ?? 'open') === 'static') {
                        $bypassRaw = (string)(getAppSetting('security.ip_access.bypass_roles', '') ?? '');
                        $bypassRaw = str_replace(["\r\n", "\r"], "\n", $bypassRaw);
                        $bypassParts = array_filter(array_map('trim', preg_split('/[,\n]+/', $bypassRaw) ?: []));
                        $bypassRoles = array_map('strtolower', $bypassParts);
                        if ($role === '' || !in_array(strtolower($role), $bypassRoles, true)) {
                            $trustXff = (string)(getAppSetting('security.ip_access.trust_xff', '0') ?? '0');
                            $ipEffective = '';
                            $xffFirst = '';
                            if ($trustXff === '1') {
                                $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
                                if ($xff !== '') {
                                    $parts = array_map('trim', explode(',', $xff));
                                    $cand = (string)($parts[0] ?? '');
                                    $canon = function_exists('canonicalizeIp') ? canonicalizeIp($cand) : null;
                                    if ($canon !== null) $xffFirst = $canon;
                                }
                            }
                            if ($xffFirst !== '') $ipEffective = $xffFirst;
                            if ($ipEffective === '') {
                                $cand = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                                $canon = function_exists('canonicalizeIp') ? canonicalizeIp($cand) : null;
                                if ($canon !== null) $ipEffective = $canon;
                            }

                            $allowLocalBypass = (string)(getAppSetting('security.ip_access.allow_localhost_bypass', '0') ?? '0') === '1';
                            if (!$allowLocalBypass || !in_array($ipEffective, ['127.0.0.1','::1'], true)) {
                                $allowed = $policy['allowed_ips'] ?? [];
                                if (!is_array($allowed)) $allowed = [];
                                $reason = '';
                                if ($ipEffective === '') $reason = 'IP could not be detected';
                                elseif (empty($allowed)) $reason = 'No allowed IPs configured for this user';
                                elseif (!isIpAllowedByPolicy($ipEffective, $allowed)) $reason = 'Your IP is not in the allowed list';
                                if ($reason !== '') {
                                    $_SESSION['login_error'] = 'ip_restricted';
                                    $_SESSION['ip_restricted_context'] = [
                                        'ip' => $ipEffective,
                                        'user_id' => $uid,
                                        'role' => $role,
                                        'time' => time(),
                                        'reason' => $reason,
                                        'trust_xff' => $trustXff,
                                        'xff_first' => $xffFirst,
                                    ];
                                    return false;
                                }
                            }
                        }
                    }
                }
            }

            // Remove password from array before storing in session
            unset($user['password']);
            
            // Store user data in session
            $_SESSION['user'] = $user;
            $_SESSION['is_logged_in'] = true;
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Clear any failed login attempts for this IP
            $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND username = ?");
            if ($stmt) {
                $stmt->bind_param("ss", $ip, $username);
                $stmt->execute();
                $stmt->close();
            }
            
            // Log successful login
            $stmt = $conn->prepare("INSERT INTO user_sessions (user_id, ip_address, user_agent, login_time) VALUES (?, ?, ?, NOW())");
            if ($stmt) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $userId = (int)($user['id'] ?? 0);
                $ipLog = isset($ipEffective) && is_string($ipEffective) && $ipEffective !== '' ? $ipEffective : $ip;
                $stmt->bind_param("iss", $userId, $ipLog, $userAgent);
                $stmt->execute();
                $stmt->close();
            }
            
            return $user;
        }
        // Wrong password
        $_SESSION['login_error'] = 'invalid_credentials';
    } else {
        // No such active user
        $_SESSION['login_error'] = 'invalid_credentials';
    }
    
    // Log failed login attempt
    $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, username, attempt_time) VALUES (?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param("ss", $ip, $username);
        $stmt->execute();
        $stmt->close();
    }
    
    return false;
}

/**
 * Check if user is logged in
 * 
 * @return bool True if logged in, false otherwise
 */
function isLoggedIn() {
    // Check basic session variables
    if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
        return false;
    }
    
    // Check session timeout (30 minutes of inactivity)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        logoutUser();
        return false;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    return true;
}

/**
 * Get current logged in user data
 * 
 * @return array|null User data array or null if not logged in
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $user = $_SESSION['user'] ?? null;
    
    // Refresh session if missing profile fields (due to old login)
    if ($user && (!isset($user['employee_id']) || !array_key_exists('profile_pic', $user))) {
        refreshUserSession((int)$user['id']);
        $user = $_SESSION['user'] ?? null;
    }
    
    return $user;
}

/**
 * Check if current user has specific role
 * 
 * @param string|array $roles Role or array of roles to check
 * @return bool True if user has role, false otherwise
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    if (is_array($roles)) {
        return in_array($user['role'], $roles);
    }
    
    return $user['role'] === $roles;
}

/**
 * Check if user is admin
 * 
 * @return bool True if admin, false otherwise
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Check if user is QA
 * 
 * @return bool True if QA, false otherwise
 */
function isQA() {
    return hasRole(['qa', 'qa_agent', 'qa_manager', 'qa_director']);
}

/**
 * Check if user is agent
 * 
 * @return bool True if agent, false otherwise
 */
function isAgent() {
    return hasRole(['agent', 'operations_agent', 'operations_manager', 'operations_director']);
}

function isOperationsAgent() {
    return hasRole(['operations_agent', 'agent']);
}

function isOperationsManager() {
    return hasRole('operations_manager');
}

function isOperationsDirector() {
    return hasRole(['operations_director', 'director', 'manager_director']);
}

/**
 * Check if user is form filler
 * 
 * @return bool True if form filler, false otherwise
 */
function isFormFiller() {
    return hasRole(['form_filler', 'email_marketing_executive', 'email_marketing_agent', 'email_marketing_manager', 'email_marketing_director']);
}

function isEmailMarketingExecutive() {
    return hasRole(['email_marketing_executive', 'form_filler', 'email_marketing_agent']);
}

function isDirector() {
    return hasRole(['director', 'manager_director']);
}

function isManagerDirector() {
    return hasRole('manager_director');
}

function isSalesDirector() {
    return hasRole('sales_director');
}

function isSalesManager() {
    return hasRole('sales_manager');
}

function isSDR() {
    return hasRole('sdr');
}

function isSales() {
    return isSalesDirector() || isSalesManager() || isSDR();
}

function isClientAdmin() {
    return hasRole('client_admin');
}

function isClientSDR() {
    return hasRole('client_sdr');
}

function isVendorAdmin() {
    return hasRole('vendor_admin');
}

function isVendorUser() {
    return hasRole('vendor_user');
}

function isVendor() {
    $u = getCurrentUser() ?: [];
    $vid = (int)($u['vendor_id'] ?? 0);
    return hasRole(['vendor_admin', 'vendor_user']) || $vid > 0;
}

function isClient() {
    $u = getCurrentUser() ?: [];
    $cid = (int)($u['client_id'] ?? 0);
    return hasRole(['client_admin', 'client_sdr']) || $cid > 0;
}

function getRoleLabelFull(string $role): string {
    $role = normalizeRole($role);
    $custom = getCustomRolesConfig();
    if (isset($custom[$role])) {
        $row = $custom[$role];
        if (is_array($row)) {
            $lbl = trim((string)($row['label'] ?? ''));
            if ($lbl !== '') return $lbl;
        } else {
            $lbl = trim((string)$row);
            if ($lbl !== '') return $lbl;
        }
    }
    $map = [
        'admin' => 'System – Admin',

        'client_admin' => 'Client – Admin',
        'client_sdr' => 'Client – SDR',

        'vendor_admin' => 'Vendor – Admin',
        'vendor_user' => 'Vendor – User',

        'director' => 'Management – Director',
        'manager_director' => 'Management – Manager Director',
        'operations_director' => 'Operations – Director',
        'operations_manager' => 'Operations – Manager',
        'operations_agent' => 'Operations – Agent',

        'email_marketing_director' => 'Email Marketing – Director',
        'email_marketing_manager' => 'Email Marketing – Manager',
        'email_marketing_agent' => 'Email Marketing – Agent',

        'qa_director' => 'Quality Assurance – Director',
        'qa_manager' => 'Quality Assurance – Manager',
        'qa_agent' => 'Quality Assurance – Agent',

        'sales_director' => 'Sales – Director',
        'sales_manager' => 'Sales – Manager',
        'sdr' => 'Sales – SDR',
    ];
    return $map[$role] ?? strtoupper($role);
}

function getRoleLabelShort(string $role): string {
    $full = getRoleLabelFull($role);
    return str_replace(' – ', ' ', $full);
}

function formatUserNameWithRole(string $fullName, string $role): string {
    $name = trim($fullName) !== '' ? $fullName : 'User';
    $label = getRoleLabelShort($role);
    return $label !== '' ? ($name.' – '.$label) : $name;
}

function getRoleBadgeClass(string $role): string {
    $role = normalizeRole($role);
    $map = [
        'admin' => 'bg-danger',

        'client_admin' => 'bg-dark',
        'client_sdr' => 'bg-dark',

        'vendor_admin' => 'bg-secondary',
        'vendor_user' => 'bg-secondary',

        'director' => 'bg-success',
        'manager_director' => 'bg-success',
        'operations_director' => 'bg-success',
        'operations_manager' => 'bg-success',
        'operations_agent' => 'bg-success',

        'email_marketing_director' => 'bg-primary',
        'email_marketing_manager' => 'bg-primary',
        'email_marketing_agent' => 'bg-primary',

        'qa_director' => 'bg-info',
        'qa_manager' => 'bg-info',
        'qa_agent' => 'bg-info',

        'sales_director' => 'bg-warning text-dark',
        'sales_manager' => 'bg-warning text-dark',
        'sdr' => 'bg-warning text-dark',
    ];
    return $map[$role] ?? 'bg-secondary';
}

function normalizeRole(string $role): string {
    $role = trim($role);
    $map = [
        'agent' => 'operations_agent',
        'email_marketing_executive' => 'email_marketing_agent',
        'form_filler' => 'email_marketing_agent',
        'qa' => 'qa_agent',
    ];
    return $map[$role] ?? $role;
}

function getRoleAliases(string $role): array {
    $role = normalizeRole($role);
    $aliases = [
        'operations_agent' => ['operations_agent', 'agent'],
        'email_marketing_agent' => ['email_marketing_agent', 'email_marketing_executive', 'form_filler'],
        'qa_agent' => ['qa_agent', 'qa'],
    ];
    return $aliases[$role] ?? [$role];
}

function getCustomRolesConfig(): array {
    if (!function_exists('getAppSetting')) return [];
    $raw = (string)(getAppSetting('access.custom_roles', '') ?? '');
    $raw = trim($raw);
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ($data as $k => $v) {
        $k = trim((string)$k);
        if ($k === '') continue;
        $out[$k] = $v;
    }
    return $out;
}

function getDefaultAccessRolePermissionsConfig(): array {
    return [
        'admin' => ['*'],
        'director' => ['dashboard.admin','notifications.access','chat.access','admin.settings','admin.analytics','users.internal.manage','clients.users.manage','vendors.users.manage','leads.manage','leads.export','campaigns.manage','campaigns.export','forms.manage','qa.access','qa.assignments','sales.access','clients.access','vendors.access','revenue.access','hr.access','hr.attendance','hr.leaves','hr.payslips','hr.attendance_admin','hr.shifts','hr.payroll'],
        'manager_director' => ['dashboard.admin','notifications.access','chat.access','admin.analytics','users.internal.manage','clients.users.manage','vendors.users.manage','leads.manage','leads.export','campaigns.manage','campaigns.export','forms.manage','qa.access','qa.assignments','sales.access','clients.access','vendors.access','revenue.access','hr.access','hr.attendance','hr.leaves','hr.payslips','hr.attendance_admin','hr.shifts','hr.payroll'],
        'operations_director' => ['dashboard.operations','notifications.access','chat.access','leads.manage','leads.export','leads.entry','leads.bulk_upload','leads.tracking','campaigns.view','campaigns.export','clients.access','vendors.access','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'operations_manager' => ['dashboard.operations','notifications.access','chat.access','leads.manage','leads.export','leads.entry','leads.bulk_upload','leads.tracking','campaigns.view','campaigns.export','clients.access','vendors.access','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'operations_agent' => ['dashboard.operations','notifications.access','chat.access','leads.manage','leads.entry','leads.my','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'agent' => ['dashboard.operations','notifications.access','chat.access','leads.entry','leads.my','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'email_marketing_director' => ['dashboard.marketing','notifications.access','chat.access','leads.manage','leads.entry','leads.marketing','leads.email','campaigns.view','campaigns.export','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'email_marketing_manager' => ['dashboard.marketing','notifications.access','chat.access','leads.manage','leads.entry','leads.marketing','leads.email','campaigns.view','campaigns.export','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'email_marketing_agent' => ['dashboard.marketing','notifications.access','chat.access','leads.manage','leads.entry','leads.marketing','leads.email','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'email_marketing_executive' => ['dashboard.marketing','notifications.access','chat.access','leads.manage','leads.entry','leads.marketing','leads.email','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'form_filler' => ['dashboard.marketing','notifications.access','chat.access','leads.entry','leads.marketing','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'qa_director' => ['dashboard.qa','notifications.access','chat.access','qa.access','qa.assignments','leads.view','leads.export','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'qa_manager' => ['dashboard.qa','notifications.access','chat.access','qa.access','qa.assignments','leads.view','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'qa_agent' => ['dashboard.qa','notifications.access','chat.access','qa.access','leads.view','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'qa' => ['dashboard.qa','notifications.access','chat.access','qa.access','leads.view','campaigns.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'sales_director' => ['dashboard.sales','notifications.access','chat.access','sales.access','campaigns.view','leads.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'sales_manager' => ['dashboard.sales','notifications.access','chat.access','sales.access','campaigns.view','leads.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'sdr' => ['dashboard.sales','notifications.access','chat.access','sales.access','leads.view','hr.access','hr.attendance','hr.leaves','hr.payslips'],
        'client_admin' => ['dashboard.client','notifications.access','leads.view','campaigns.view','clients.users.manage'],
        'client_sdr' => ['dashboard.client','notifications.access','leads.view','campaigns.view'],
        'vendor_admin' => ['dashboard.vendor','notifications.access','leads.view','leads.entry','leads.bulk_upload','leads.my','campaigns.view','vendors.users.manage','vendors.access','revenue.access'],
        'vendor_user' => ['dashboard.vendor','notifications.access','leads.view','leads.entry','leads.bulk_upload','leads.my','campaigns.view'],
    ];
}

function getAccessRolePermissionsConfig(): array {
    if (isset($GLOBALS['__access_role_permissions_cache']) && is_array($GLOBALS['__access_role_permissions_cache'])) {
        return $GLOBALS['__access_role_permissions_cache'];
    }
    $data = null;
    $loadedFromSetting = false;
    if (function_exists('getAppSetting')) {
        $raw = (string)(getAppSetting('access.role_permissions', '') ?? '');
        $raw = trim($raw);
        if ($raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) { $data = $tmp; $loadedFromSetting = true; }
        }
    }
    if (!is_array($data)) $data = getDefaultAccessRolePermissionsConfig();
    $known = function_exists('getKnownRoles') ? getKnownRoles() : [];
    if (!is_array($known)) $known = [];
    if (function_exists('getCustomRolesConfig')) {
        $custom = getCustomRolesConfig();
        if (is_array($custom)) {
            foreach ($custom as $rk => $rv) {
                $rk = normalizeRole((string)$rk);
                if ($rk !== '' && !in_array($rk, $known, true)) $known[] = $rk;
            }
        }
    }
    foreach ($known as $rk) {
        $rk = normalizeRole((string)$rk);
        if ($rk === '') continue;
        if (!array_key_exists($rk, $data)) $data[$rk] = [];
    }
    if (!isset($data['admin']) || !is_array($data['admin'])) $data['admin'] = ['*'];
    if (!in_array('*', $data['admin'], true)) $data['admin'] = ['*'];

    if ($loadedFromSetting) {
        $custom = function_exists('getCustomRolesConfig') ? getCustomRolesConfig() : [];
        if (!is_array($custom)) $custom = [];
        $changed = false;
        foreach ($data as $rk => $list) {
            $rkNorm = normalizeRole((string)$rk);
            if ($rkNorm === '' || $rkNorm === 'admin') continue;
            $scope = 'internal';
            if (str_starts_with($rkNorm, 'client_')) $scope = 'client';
            elseif (str_starts_with($rkNorm, 'vendor_')) $scope = 'vendor';
            elseif (isset($custom[$rkNorm]) && is_array($custom[$rkNorm])) $scope = (string)($custom[$rkNorm]['scope'] ?? 'internal');
            if ($scope === 'internal') continue;
            if (!is_array($list)) continue;
            $next = [];
            foreach ($list as $p) {
                $p = trim((string)$p);
                if ($p === '') continue;
                if (str_starts_with($p, 'hr.')) continue;
                $next[] = $p;
            }
            $next = array_values(array_unique($next));
            if ($next !== $list) {
                $data[$rk] = $next;
                $changed = true;
            }
        }
        if ($changed && function_exists('setAppSetting')) {
            setAppSetting('access.role_permissions', json_encode($data, JSON_UNESCAPED_SLASHES));
        }
    }

    $GLOBALS['__access_role_permissions_cache'] = $data;
    return $data;
}

function roleHasPermission(string $role, string $permission, array $cfg): bool {
    $role = normalizeRole($role);
    $permission = trim($permission);
    if ($permission === '') return false;
    $requested = permissionAliases($permission);
    $perms = $cfg[$role] ?? null;
    if (!is_array($perms)) return false;
    foreach ($perms as $p) {
        $p = trim((string)$p);
        if ($p === '*') return true;
        foreach ($requested as $req) {
            if ($p === $req) return true;
        }
        if (str_ends_with($p, '.*')) {
            $prefix = substr($p, 0, -2);
            if ($prefix !== '') {
                foreach ($requested as $req) {
                    if (str_starts_with($req, $prefix . '.')) return true;
                }
            }
        }
    }
    return false;
}

function permissionAliases(string $permission): array {
    $permission = trim($permission);
    if ($permission === '') return [];

    $aliases = [$permission];
    $legacy = [
        'users.internal.manage' => ['users.manage'],
        'clients.users.manage' => ['users.manage'],
        'vendors.users.manage' => ['users.manage'],
    ];
    foreach ($legacy as $newKey => $oldKeys) {
        if ($permission === $newKey) {
            foreach ($oldKeys as $ok) $aliases[] = $ok;
        }
        foreach ($oldKeys as $ok) {
            if ($permission === $ok) $aliases[] = $newKey;
        }
    }
    if (in_array($permission, ['hr.attendance','hr.leaves','hr.payslips'], true)) {
        $aliases[] = 'hr.access';
    }
    return array_values(array_unique($aliases));
}

function userHasPermission(string $permission): bool {
    $user = getCurrentUser();
    $role = normalizeRole((string)($user['role'] ?? ''));
    if ($role === 'admin') return true;
    $cfg = getAccessRolePermissionsConfig();
    if ($cfg === null) return false;
    return roleHasPermission($role, $permission, $cfg);
}

function requirePermissionOrRole(string $permission, array $fallbackRoles): void {
    requireLogin();
    $cfg = getAccessRolePermissionsConfig();
    if ($cfg === null) {
        if (hasRole($fallbackRoles)) return;
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : 'unknown';
        $requestedRoles = !empty($fallbackRoles) ? implode(', ', $fallbackRoles) : '(none)';
        error_log("Unauthorized access attempt by user $userId for roles: $requestedRoles on " . ($_SERVER['REQUEST_URI'] ?? ''));
        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You are not authorized to perform this action.']);
            exit;
        }
        $_SESSION['access_denied_context'] = [
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'required_roles' => array_values($fallbackRoles),
            'required_permission' => null,
            'time' => time(),
        ];
        header("Location: " . appBasePath() . "/modules/auth/access-denied");
        exit;
    }

    if (userHasPermission($permission)) return;

    $user = getCurrentUser();
    $userId = $user ? $user['id'] : 'unknown';
    error_log("Unauthorized access attempt by user $userId for permission: $permission on " . ($_SERVER['REQUEST_URI'] ?? ''));
    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not authorized to perform this action.']);
        exit;
    }
    $_SESSION['access_denied_context'] = [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'required_roles' => array_values($fallbackRoles),
        'required_permission' => $permission,
        'time' => time(),
    ];
    header("Location: " . appBasePath() . "/modules/auth/access-denied");
    exit;
}

/**
 * Require user to be logged in
 * Redirects to login page if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
            exit;
        } else {
            // Store the requested URL for redirect after login
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header("Location: " . appBasePath() . "/modules/auth/login");
            exit;
        }
    }

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $pathLower = strtolower($path);
    $isIpRestrictedRoute = str_contains($pathLower, '/modules/auth/ip-restricted');
    $isLogoutRoute = str_contains($pathLower, '/modules/auth/logout') || $script === 'logout.php';
    if (!$isIpRestrictedRoute && !$isLogoutRoute) {
        $enabled = (string)(getAppSetting('security.ip_access.enabled', '0') ?? '0');
        if ($enabled === '1') {
            $user = getCurrentUser() ?: [];
            $uid = (int)($user['id'] ?? 0);
            $role = (string)($user['role'] ?? '');
            if ($uid > 0) {
                $policy = getUserIpAccessPolicy($uid);
                if (($policy['mode'] ?? 'open') === 'static') {
                    $bypassRaw = (string)(getAppSetting('security.ip_access.bypass_roles', '') ?? '');
                    $bypassRaw = str_replace(["\r\n", "\r"], "\n", $bypassRaw);
                    $bypassParts = array_filter(array_map('trim', preg_split('/[,\n]+/', $bypassRaw) ?: []));
                    $bypassRoles = array_map('strtolower', $bypassParts);
                    if ($role !== '' && in_array(strtolower($role), $bypassRoles, true)) return;

                    $trustXff = (string)(getAppSetting('security.ip_access.trust_xff', '0') ?? '0');
                    $ip = '';
                    $xffFirst = '';
                    if ($trustXff === '1') {
                        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
                        if ($xff !== '') {
                            $parts = array_map('trim', explode(',', $xff));
                            $cand = (string)($parts[0] ?? '');
                            $canon = function_exists('canonicalizeIp') ? canonicalizeIp($cand) : null;
                            if ($canon !== null) $xffFirst = $canon;
                        }
                    }
                    if ($xffFirst !== '') $ip = $xffFirst;
                    if ($ip === '') {
                        $cand = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                        $canon = function_exists('canonicalizeIp') ? canonicalizeIp($cand) : null;
                        if ($canon !== null) $ip = $canon;
                    }

                    $allowLocalBypass = (string)(getAppSetting('security.ip_access.allow_localhost_bypass', '0') ?? '0') === '1';
                    if ($allowLocalBypass && in_array($ip, ['127.0.0.1','::1'], true)) return;

                    $allowed = $policy['allowed_ips'] ?? [];
                    if (!is_array($allowed)) $allowed = [];
                    $reason = '';
                    if ($ip === '') $reason = 'IP could not be detected';
                    elseif (empty($allowed)) $reason = 'No allowed IPs configured for this user';
                    elseif (!isIpAllowedByPolicy($ip, $allowed)) $reason = 'Your IP is not in the allowed list';
                    if ($reason !== '') {
                        $_SESSION['ip_restricted_context'] = [
                            'ip' => $ip,
                            'user_id' => $uid,
                            'role' => $role,
                            'time' => time(),
                            'reason' => $reason,
                            'allowed_ips' => $allowed,
                            'trust_xff' => $trustXff,
                            'xff_first' => $xffFirst,
                        ];
                        header("Location: " . appBasePath() . "/modules/auth/ip-restricted");
                        exit;
                    }
                }
            }
        }
    }
}

/**
 * Require user to have specific role
 * Redirects to access denied page if not authorized
 * 
 * @param string|array $roles Role or array of roles required
 */
function requireRole($roles) {
    requireLogin();
    $permission = inferPermissionKeyForRequest();
    if ($permission !== null && str_starts_with($permission, 'hr.') && (function_exists('isClient') && isClient() || function_exists('isVendor') && isVendor())) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You are not authorized to perform this action.']);
            exit;
        }
        $_SESSION['access_denied_context'] = [
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'required_roles' => [],
            'required_permission' => $permission,
            'time' => time(),
        ];
        header("Location: " . appBasePath() . "/modules/auth/access-denied");
        exit;
    }
    if ($permission === 'leads.view') {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = strtolower($path);
        if (str_contains($path, '/modules/leads/view') || str_contains($path, '/modules/leads/details') || str_contains($path, '/modules/leads/get_lead_details')) {
            if (userHasPermission('leads.my') || userHasPermission('leads.manage') || userHasPermission('qa.access')) return;
        }
    }
    if ($permission !== null && userHasPermission($permission)) return;
    $user = getCurrentUser();
    $userId = $user ? $user['id'] : 'unknown';
    $permTxt = $permission !== null ? $permission : '(unmapped)';
    error_log("Unauthorized access attempt by user $userId for permission: $permTxt on " . ($_SERVER['REQUEST_URI'] ?? ''));
    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not authorized to perform this action.']);
        exit;
    }
    $_SESSION['access_denied_context'] = [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'required_roles' => [],
        'required_permission' => $permission,
        'time' => time(),
    ];
    header("Location: " . appBasePath() . "/modules/auth/access-denied");
    exit;
}

function inferPermissionKeyForRequest(): ?string {
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $path = '/' . ltrim($path, '/');
    $path = preg_replace('/\\/+/', '/', $path);

    $lower = strtolower($path);
    $pos = strpos($lower, '/modules/');
    if ($pos === false) return null;
    $rel = substr($lower, $pos + 9);
    $rel = trim($rel, '/');
    if ($rel === '') return null;

    $parts = explode('/', $rel);
    $module = $parts[0] ?? '';
    $rest = implode('/', array_slice($parts, 1));

    if ($module === '' || $module === 'auth') return null;

    if ($module === 'dashboard') {
        if (str_contains($rest, 'admin-dashboard') || str_contains($rest, 'debug_admin_dashboard')) return 'dashboard.admin';
        if (str_contains($rest, 'operations-dashboard') || str_contains($rest, 'agent-dashboard')) return 'dashboard.operations';
        if (str_contains($rest, 'qa-dashboard')) return 'dashboard.qa';
        if (str_contains($rest, 'sales-dashboard')) return 'dashboard.sales';
        if (str_contains($rest, 'client-dashboard')) return 'dashboard.client';
        if (str_contains($rest, 'vendor-dashboard')) return 'dashboard.vendor';
        if (str_contains($rest, 'email-marketing-dashboard') || str_contains($rest, 'form-filler-dashboard')) return 'dashboard.marketing';
        return 'dashboard.access';
    }
    if ($module === 'notifications') return 'notifications.access';
    if ($module === 'chat') return 'chat.access';
    if ($module === 'hr') {
        if (preg_match('/(^|\\/)(attendance-admin|attendance-export|attendance-monthly-report|attendance-monthly-export)(\\/|$)/', $rest)) return 'hr.attendance_admin';
        if (preg_match('/(^|\\/)(attendance)(\\/|$)/', $rest)) return 'hr.attendance';
        if (preg_match('/(^|\\/)(paid-leaves)(\\/|$)/', $rest)) return 'hr.leaves';
        if (preg_match('/(^|\\/)(payslips|payslip-view|payslip-pdf)(\\/|$)/', $rest)) return 'hr.payslips';
        if (preg_match('/(^|\\/)(payroll|payroll-export|salary-setup|bonus-loans)(\\/|$)/', $rest)) return 'hr.payroll';
        if (preg_match('/(^|\\/)(shifts)(\\/|$)/', $rest)) return 'hr.shifts';
        if (preg_match('/(^|\\/)(hr-dashboard)(\\/|$)/', $rest)) return 'hr.access';
        return 'hr.access';
    }
    if ($module === 'revenue') return 'revenue.access';
    if ($module === 'sales') return 'sales.access';

    if ($module === 'admin') {
        if (str_starts_with($rest, 'analytics/')) return 'admin.analytics';
        if (str_contains($rest, 'data-reset')) return 'admin.data_reset';
        return 'admin.settings';
    }

    if ($module === 'users') {
        if (str_contains($rest, 'profile')) return 'users.profile';
        return 'users.internal.manage';
    }

    if ($module === 'clients') {
        if (str_contains($rest, 'client-users')) return 'clients.users.manage';
        if (str_contains($rest, 'client-leads') || str_contains($rest, 'client-lead-details')) return 'leads.view';
        if (str_contains($rest, 'client-campaign')) return 'campaigns.view';
        return 'clients.access';
    }

    if ($module === 'vendors') {
        if (str_contains($rest, 'vendor-users')) return 'vendors.users.manage';
        if (str_contains($rest, 'vendor-leads')) return 'leads.view';
        if (str_contains($rest, 'vendor-revenue')) return 'revenue.access';
        if (str_contains($rest, 'vendor-billing')) return 'vendors.access';
        if (str_contains($rest, 'vendor-campaign')) return 'campaigns.view';
        return 'vendors.access';
    }

    if ($module === 'forms') {
        return 'forms.manage';
    }

    if ($module === 'qa') {
        if (str_starts_with($rest, 'assignments') || str_contains($rest, 'assignments.php')) return 'qa.assignments';
        return 'qa.access';
    }

    if ($module === 'campaigns') {
        if (preg_match('/(^|\\/)(manage|campaigns-manage\\.php|create|edit|delete|allocation|assign-sdr|options-source)(\\/|$)/', $rest)) return 'campaigns.manage';
        if (str_contains($rest, 'export')) return 'campaigns.export';
        return 'campaigns.view';
    }

    if ($module === 'leads') {
        if (preg_match('/(^|\\/)(purge|leads-purge|leads-purge\\.php)(\\/|$)/', $rest)) return 'leads.purge';
        if (preg_match('/(^|\\/)(bulk|bulk-upload|bulk-upload\\.php)(\\/|$)/', $rest)) return 'leads.bulk_upload';
        if (preg_match('/(^|\\/)(tracking|lead-tracking|lead-tracking\\.php)(\\/|$)/', $rest)) return 'leads.tracking';
        if (preg_match('/(^|\\/)(entry|agent|agent\\.php)(\\/|$)/', $rest)) return 'leads.entry';
        if (preg_match('/(^|\\/)(my|my-leads|my-leads\\.php)(\\/|$)/', $rest)) return 'leads.my';
        if (preg_match('/(^|\\/)(marketing|form-filler|form-filler\\.php)(\\/|$)/', $rest)) return 'leads.marketing';
        if (preg_match('/(^|\\/)(email|email-leads|email-leads\\.php)(\\/|$)/', $rest)) return 'leads.email';
        if (preg_match('/(^|\\/)(lead-details|lead-details\\.php|get_lead_details|get_lead_details\\.php)(\\/|$)/', $rest)) return 'leads.view';
        if (preg_match('/(^|\\/)(leads-edit\\.php|leads-edit|edit)(\\/|$)/', $rest)) return 'leads.manage';
        if (str_contains($rest, 'export')) return 'leads.export';
        if (preg_match('/(^|\\/)(view)(\\/|$)/', $rest)) return 'leads.view';
        return 'leads.view';
    }

    return $module . '.access';
}

/**
 * Check if user can access specific resource
 * 
 * @param string $resource Resource identifier
 * @param int|null $resourceId Optional resource ID for ownership checks
 * @return bool True if user can access resource, false otherwise
 */
function canAccess($resource, $resourceId = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    
    switch ($resource) {
        case 'leads':
            // Admins and QA can access all leads
            if (isAdmin() || isQA()) {
                return true;
            }
            // Agents can only access their own leads
            if ((isAgent() || isFormFiller()) && $resourceId) {
                $conn = getDbConnection();
                $stmt = $conn->prepare("SELECT agent_id FROM leads WHERE id = ?");
                $stmt->bind_param("i", $resourceId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    return $row['agent_id'] == $user['id'];
                }
            }
            return false;
            
        case 'users':
            return isAdmin() || hasRole(['client_admin','vendor_admin']);
            
        case 'campaigns':
            // Admins can manage campaigns, others can view
            return isAdmin() || isQA() || isAgent() || isFormFiller();
            
        case 'reports':
            // Admins and QA can access reports
            return isAdmin() || isQA();
            
        default:
            return false;
    }
}

/**
 * Log out current user
 */
function logoutUser() {
    // Log the logout
    if (isset($_SESSION['user'])) {
        $conn = getDbConnection();
        // Use two-step select-then-update to avoid SQL limitations and prepare failures
        $selectStmt = $conn->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND logout_time IS NULL ORDER BY login_time DESC LIMIT 1");
        if ($selectStmt) {
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $selectStmt->bind_param("i", $userId);
            if ($selectStmt->execute()) {
                $result = $selectStmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $sessionId = (int)$row['id'];
                    $updateStmt = $conn->prepare("UPDATE user_sessions SET logout_time = NOW() WHERE id = ?");
                    if ($updateStmt) {
                        $updateStmt->bind_param("i", $sessionId);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                }
            }
            $selectStmt->close();
        }
    }
    
    // Unset all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

/**
 * Get user's role display name
 * 
 * @param string $role Role identifier
 * @return string Display name for role
 */
function getRoleDisplayName($role) {
    $roleNames = [
        'admin' => 'Administrator',
        'qa' => 'Quality Assurance',
        'agent' => 'Agent',
        'form_filler' => 'Form Filler'
    ];
    
    return $roleNames[$role] ?? ucfirst($role);
}

function getKnownRoles(): array {
    return [
        'admin',

        'client_admin',
        'client_sdr',

        'vendor_admin',
        'vendor_user',

        'director',
        'manager_director',
        'operations_director',
        'operations_manager',
        'operations_agent',
        'agent',

        'email_marketing_director',
        'email_marketing_manager',
        'email_marketing_agent',
        'email_marketing_executive',
        'form_filler',

        'qa_director',
        'qa_manager',
        'qa_agent',
        'qa',

        'sales_director',
        'sales_manager',
        'sdr',
    ];
}

/**
 * Check if user account is locked
 * 
 * @param string $username Username to check
 * @return bool True if account is locked, false otherwise
 */
function isAccountLocked($username) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT is_locked, locked_until FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if ($row['is_locked']) {
            // Check if lock has expired
            if ($row['locked_until'] && strtotime($row['locked_until']) < time()) {
                // Unlock the account
                $stmt = $conn->prepare("UPDATE users SET is_locked = 0, locked_until = NULL WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                return false;
            }
            return true;
        }
    }
    
    return false;
}
