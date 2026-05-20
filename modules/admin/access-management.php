<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();

$conn = getDbConnection();
$message = '';
$messageType = 'success';

$flash = is_array($_SESSION['am_flash'] ?? null) ? $_SESSION['am_flash'] : null;
if ($flash) {
    unset($_SESSION['am_flash']);
    if ($message === '') {
        $message = (string)($flash['message'] ?? '');
        $messageType = (string)($flash['type'] ?? 'success');
    }
}

$baseRoles = [
    'admin' => 'System – Admin',
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
    'client_admin' => 'Client – Admin',
    'client_sdr' => 'Client – SDR',
    'vendor_admin' => 'Vendor – Admin',
    'vendor_user' => 'Vendor – User',
];
$customRoles = getCustomRolesConfig();
$roles = $baseRoles;
foreach ($customRoles as $k => $v) {
    $k = normalizeRole((string)$k);
    if ($k === '' || isset($roles[$k])) continue;
    if (!is_array($v)) continue;
    $lbl = trim((string)($v['label'] ?? ''));
    if ($lbl === '') continue;
    $roles[$k] = $lbl;
}

$basePermissions = [
    'dashboard.admin' => 'Dashboard – Admin',
    'dashboard.operations' => 'Dashboard – Operations',
    'dashboard.qa' => 'Dashboard – QA',
    'dashboard.sales' => 'Dashboard – Sales',
    'dashboard.client' => 'Dashboard – Client',
    'dashboard.vendor' => 'Dashboard – Vendor',
    'dashboard.marketing' => 'Dashboard – Marketing',
    'dashboard.access' => 'Dashboard – Access',
    'notifications.access' => 'Notifications – Access',
    'chat.access' => 'Chat – Access',

    'users.profile' => 'Users – My Profile',
    'users.internal.manage' => 'Users – Internal Manage',
    'clients.users.manage' => 'Users – Client Manage',
    'vendors.users.manage' => 'Users – Vendor Manage',

    'leads.view' => 'Leads – View',
    'leads.manage' => 'Leads – Manage',
    'leads.export' => 'Leads – Export',
    'leads.entry' => 'Leads – Entry',
    'leads.my' => 'Leads – My Leads',
    'leads.bulk_upload' => 'Leads – Bulk Upload',
    'leads.tracking' => 'Leads – Tracking',
    'leads.purge' => 'Leads – Purge',
    'leads.marketing' => 'Leads – Marketing Entry',
    'leads.email' => 'Leads – Email Leads',

    'campaigns.view' => 'Campaigns – View',
    'campaigns.manage' => 'Campaigns – Manage',
    'campaigns.export' => 'Campaigns – Export',

    'forms.manage' => 'Forms – Manage',

    'qa.access' => 'QA – Access',
    'qa.assignments' => 'QA – Assignments',

    'sales.access' => 'Sales – Access',
    'clients.access' => 'Clients – Access',
    'vendors.access' => 'Vendors – Access',
    'hr.access' => 'HR – Access',
    'revenue.access' => 'Revenue – Access',
    'productivity.access' => 'Productivity – Access',

    'admin.settings' => 'Admin – Settings',
    'admin.analytics' => 'Admin – Analytics',
    'admin.data_reset' => 'Admin – Data Reset',
];

$basePermissionGroups = [
    'Dashboard' => ['dashboard.admin','dashboard.operations','dashboard.qa','dashboard.sales','dashboard.client','dashboard.vendor','dashboard.marketing','dashboard.access'],
    'Leads' => ['leads.view','leads.manage','leads.export','leads.entry','leads.my','leads.bulk_upload','leads.tracking','leads.purge','leads.marketing','leads.email'],
    'Campaigns' => ['campaigns.view','campaigns.manage','campaigns.export'],
    'Forms' => ['forms.manage'],
    'QA' => ['qa.access','qa.assignments'],
    'Users' => ['users.profile','users.internal.manage','clients.users.manage','vendors.users.manage'],
    'Admin' => ['admin.settings','admin.analytics','admin.data_reset'],
    'Other' => ['notifications.access','chat.access','sales.access','clients.access','vendors.access','hr.access','revenue.access','productivity.access'],
];

$customPermissionsRaw = trim((string)(getAppSetting('access.custom_permissions', '') ?? ''));
$customPermissionsList = $customPermissionsRaw !== '' ? json_decode($customPermissionsRaw, true) : null;
if (!is_array($customPermissionsList)) $customPermissionsList = [];
$customPermissions = [];
$customGroups = [];
foreach ($customPermissionsList as $row) {
    if (!is_array($row)) continue;
    $k = trim((string)($row['key'] ?? ''));
    $lbl = trim((string)($row['label'] ?? ''));
    $grp = trim((string)($row['group'] ?? 'Custom'));
    if ($k === '' || $lbl === '') continue;
    $customPermissions[$k] = $lbl;
    $customGroups[$grp][] = $k;
}
$permissions = $basePermissions + $customPermissions;
$permissionGroups = $basePermissionGroups;
foreach ($customGroups as $grp => $keys) {
    $permissionGroups[$grp] = array_values(array_unique($keys));
}

$roleTemplates = [
    'client' => [
        'client_viewer' => [
            'label' => 'Client Viewer',
            'permissions' => ['dashboard.client','notifications.access','leads.view','campaigns.view'],
        ],
        'client_manager' => [
            'label' => 'Client Manager',
            'permissions' => ['dashboard.client','notifications.access','leads.view','campaigns.view','clients.users.manage'],
        ],
    ],
    'vendor' => [
        'vendor_viewer' => [
            'label' => 'Vendor Viewer',
            'permissions' => ['dashboard.vendor','notifications.access','leads.view','campaigns.view'],
        ],
        'vendor_manager' => [
            'label' => 'Vendor Manager',
            'permissions' => ['dashboard.vendor','notifications.access','leads.view','campaigns.view','vendors.users.manage'],
        ],
    ],
];

$default = [
    'admin' => ['*'],
    'director' => ['dashboard.admin','notifications.access','chat.access','admin.settings','admin.analytics','users.internal.manage','clients.users.manage','vendors.users.manage','leads.manage','leads.export','campaigns.manage','campaigns.export','forms.manage','qa.access','qa.assignments','sales.access','clients.access','vendors.access','revenue.access','productivity.access','hr.access'],
    'manager_director' => ['dashboard.admin','notifications.access','chat.access','admin.analytics','users.internal.manage','clients.users.manage','vendors.users.manage','leads.manage','leads.export','campaigns.manage','campaigns.export','forms.manage','qa.access','qa.assignments','sales.access','clients.access','vendors.access','revenue.access','productivity.access','hr.access'],
    'operations_director' => ['dashboard.operations','notifications.access','chat.access','leads.manage','leads.export','leads.entry','leads.bulk_upload','leads.tracking','campaigns.view','campaigns.export','clients.access','vendors.access'],
    'operations_manager' => ['dashboard.operations','notifications.access','chat.access','leads.manage','leads.export','leads.entry','leads.bulk_upload','leads.tracking','campaigns.view','campaigns.export','clients.access','vendors.access'],
    'operations_agent' => ['dashboard.operations','notifications.access','chat.access','leads.manage','leads.entry','leads.my','campaigns.view'],
    'email_marketing_director' => ['dashboard.marketing','notifications.access','chat.access','leads.manage','leads.entry','leads.marketing','leads.email','campaigns.view','campaigns.export'],
    'email_marketing_manager' => ['dashboard.marketing','notifications.access','chat.access','leads.manage','leads.entry','leads.marketing','leads.email','campaigns.view','campaigns.export'],
    'email_marketing_agent' => ['dashboard.marketing','notifications.access','chat.access','leads.manage','leads.entry','leads.marketing','leads.email','campaigns.view'],
    'qa_director' => ['dashboard.qa','notifications.access','chat.access','qa.access','qa.assignments','leads.view','leads.export','campaigns.view'],
    'qa_manager' => ['dashboard.qa','notifications.access','chat.access','qa.access','qa.assignments','leads.view','campaigns.view'],
    'qa_agent' => ['dashboard.qa','notifications.access','chat.access','qa.access','leads.view','campaigns.view'],
    'sales_director' => ['dashboard.sales','notifications.access','chat.access','sales.access','campaigns.view','leads.view'],
    'sales_manager' => ['dashboard.sales','notifications.access','chat.access','sales.access','campaigns.view','leads.view'],
    'sdr' => ['dashboard.sales','notifications.access','chat.access','sales.access','leads.view'],
    'client_admin' => ['dashboard.client','notifications.access','leads.view','campaigns.view','clients.users.manage'],
    'client_sdr' => ['dashboard.client','notifications.access','leads.view','campaigns.view'],
    'vendor_admin' => ['dashboard.vendor','notifications.access','leads.view','campaigns.view','vendors.users.manage'],
    'vendor_user' => ['dashboard.vendor','notifications.access','leads.view','campaigns.view'],
];

$cfg = getAccessRolePermissionsConfig();
if ($cfg === null) $cfg = $default;
foreach ($roles as $rk => $_) {
    if (!isset($cfg[$rk])) $cfg[$rk] = [];
}

$selectedRole = normalizeRole((string)($_GET['role'] ?? ''));
if ($selectedRole === '' || !array_key_exists($selectedRole, $roles)) {
    $keys = array_keys($roles);
    $selectedRole = (string)($keys[0] ?? 'admin');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? 'save');
        $redirectToRole = function(string $rk, string $msg, string $type = 'success') {
            $_SESSION['am_flash'] = ['message' => $msg, 'type' => $type];
            header('Location: access-management?role=' . rawurlencode($rk));
            exit;
        };
        if ($action === 'reset') {
            $next = $default;
            foreach ($roles as $rk => $_) {
                if (!isset($next[$rk])) $next[$rk] = [];
            }
            setAppSetting('access.role_permissions', json_encode($next, JSON_UNESCAPED_SLASHES));
            $cfg = $next;
            $_SESSION['am_flash'] = ['message' => 'Reset to defaults.', 'type' => 'success'];
            header('Location: access-management?role=' . rawurlencode($selectedRole));
            exit;
        } elseif ($action === 'add_role') {
            $rk = strtolower(trim((string)($_POST['new_role_key'] ?? '')));
            $rk = preg_replace('/\\s+/', '_', $rk);
            $rk = preg_replace('/[^a-z0-9_]/', '', $rk);
            $label = trim((string)($_POST['new_role_label'] ?? ''));
            $scope = trim((string)($_POST['new_role_scope'] ?? 'internal'));
            if (!in_array($scope, ['internal','client','vendor'], true)) $scope = 'internal';
            $template = trim((string)($_POST['new_role_template'] ?? ''));
            $copyFrom = normalizeRole((string)($_POST['copy_from_role'] ?? ''));
            if ($rk === '' || strlen($rk) < 3) {
                $message = 'Role key is required (min 3 characters).';
                $messageType = 'danger';
            } elseif (isset($baseRoles[$rk]) || isset($roles[$rk])) {
                $message = 'Role key already exists.';
                $messageType = 'danger';
            } elseif ($label === '') {
                $message = 'Role label is required.';
                $messageType = 'danger';
            } else {
                $customRoles[$rk] = ['label' => $label, 'scope' => $scope];
                setAppSetting('access.custom_roles', json_encode($customRoles, JSON_UNESCAPED_SLASHES));
                $roles[$rk] = $label;
                $src = [];
                if ($template !== '' && isset($roleTemplates[$scope]) && isset($roleTemplates[$scope][$template])) {
                    $src = (array)($roleTemplates[$scope][$template]['permissions'] ?? []);
                } elseif ($copyFrom !== '') {
                    $src = (array)($cfg[$copyFrom] ?? []);
                }
                $src = array_values(array_filter(array_map('strval', $src), fn($p) => trim($p) !== '' && trim($p) !== '*'));
                $cfg[$rk] = $src;
                setAppSetting('access.role_permissions', json_encode($cfg, JSON_UNESCAPED_SLASHES));
                $redirectToRole($rk, 'Role created.');
            }
        } elseif ($action === 'edit_role') {
            $rk = normalizeRole((string)($_POST['role_key'] ?? ''));
            $label = trim((string)($_POST['edit_role_label'] ?? ''));
            $scope = trim((string)($_POST['edit_role_scope'] ?? 'internal'));
            if (!in_array($scope, ['internal','client','vendor'], true)) $scope = 'internal';
            if ($rk === '' || !isset($customRoles[$rk])) {
                $message = 'Invalid role.';
                $messageType = 'danger';
            } elseif ($label === '') {
                $message = 'Role label is required.';
                $messageType = 'danger';
            } else {
                $customRoles[$rk]['label'] = $label;
                $customRoles[$rk]['scope'] = $scope;
                setAppSetting('access.custom_roles', json_encode($customRoles, JSON_UNESCAPED_SLASHES));
                $roles[$rk] = $label;
                $redirectToRole($rk, 'Role updated.');
            }
        } elseif ($action === 'delete_role') {
            $rk = normalizeRole((string)($_POST['role_key'] ?? ''));
            if ($rk === '' || !isset($customRoles[$rk])) {
                $message = 'Invalid role.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role = ? LIMIT 1");
                $cnt = 0;
                if ($stmt) {
                    $stmt->bind_param('s', $rk);
                    $stmt->execute();
                    $cnt = (int)(($stmt->get_result()->fetch_assoc() ?: [])['cnt'] ?? 0);
                    $stmt->close();
                }
                if ($cnt > 0) {
                    $message = 'Cannot delete role while users are assigned to it.';
                    $messageType = 'danger';
                } else {
                    unset($customRoles[$rk], $roles[$rk], $cfg[$rk]);
                    setAppSetting('access.custom_roles', json_encode($customRoles, JSON_UNESCAPED_SLASHES));
                    setAppSetting('access.role_permissions', json_encode($cfg, JSON_UNESCAPED_SLASHES));
                    $keys = array_keys($roles);
                    $selectedRole = (string)($keys[0] ?? 'admin');
                    $redirectToRole($selectedRole, 'Role deleted.');
                }
            }
        } elseif ($action === 'disable') {
            setAppSetting('access.role_permissions', '');
            $cfg = $default;
            $_SESSION['am_flash'] = ['message' => 'Permission system disabled. Role-based rules are active again.', 'type' => 'warning'];
            header('Location: access-management?role=' . rawurlencode($selectedRole));
            exit;
        } elseif ($action === 'add_permission') {
            $k = trim((string)($_POST['perm_key'] ?? ''));
            $k = preg_replace('/\\s+/', '.', strtolower($k));
            $k = preg_replace('/[^a-z0-9_.]/', '', $k);
            $lbl = trim((string)($_POST['perm_label'] ?? ''));
            $grp = trim((string)($_POST['perm_group'] ?? 'Custom'));
            if ($k === '' || $lbl === '') {
                $message = 'Permission key and label are required.';
                $messageType = 'danger';
            } elseif (isset($basePermissions[$k]) || isset($customPermissions[$k])) {
                $message = 'Permission key already exists.';
                $messageType = 'danger';
            } else {
                $customPermissionsList[] = ['key' => $k, 'label' => $lbl, 'group' => $grp];
                setAppSetting('access.custom_permissions', json_encode(array_values($customPermissionsList), JSON_UNESCAPED_SLASHES));
                $_SESSION['am_flash'] = ['message' => 'Permission added.', 'type' => 'success'];
                header('Location: access-management?role=' . rawurlencode($selectedRole));
                exit;
            }
        } elseif ($action === 'delete_permission') {
            $k = trim((string)($_POST['perm_key'] ?? ''));
            $next = [];
            foreach ($customPermissionsList as $row) {
                if (!is_array($row)) continue;
                if ((string)($row['key'] ?? '') === $k) continue;
                $next[] = $row;
            }
            setAppSetting('access.custom_permissions', json_encode(array_values($next), JSON_UNESCAPED_SLASHES));
            $_SESSION['am_flash'] = ['message' => 'Permission deleted.', 'type' => 'success'];
            header('Location: access-management?role=' . rawurlencode($selectedRole));
            exit;
        } elseif ($action === 'import') {
            $json = trim((string)($_POST['import_json'] ?? ''));
            $data = json_decode($json, true);
            if (!is_array($data)) {
                $message = 'Invalid JSON.';
                $messageType = 'danger';
            } else {
                $next = [];
                foreach ($roles as $roleKey => $label) {
                    $vals = $data[$roleKey] ?? [];
                    $list = [];
                    if (is_array($vals)) {
                        foreach ($vals as $v) {
                            $v = trim((string)$v);
                            if ($v === '*') { $list = ['*']; break; }
                            if ($v !== '' && isset($permissions[$v])) $list[] = $v;
                        }
                    }
                    if ($roleKey === 'admin') $list = ['*'];
                    $next[$roleKey] = array_values(array_unique($list));
                }
                setAppSetting('access.role_permissions', json_encode($next, JSON_UNESCAPED_SLASHES));
                $cfg = $next;
                $_SESSION['am_flash'] = ['message' => 'Imported access settings.', 'type' => 'success'];
                header('Location: access-management?role=' . rawurlencode($selectedRole));
                exit;
            }
        } else {
            $roleKey = normalizeRole((string)($_POST['role_key'] ?? $selectedRole));
            if (!array_key_exists($roleKey, $roles)) {
                $message = 'Invalid role selection.';
                $messageType = 'danger';
            } else {
                $vals = $_POST['perm'] ?? [];
                $list = [];
                if (is_array($vals)) {
                    foreach ($vals as $v) {
                        $v = trim((string)$v);
                        if ($v !== '' && isset($permissions[$v])) $list[] = $v;
                    }
                }
                if ($roleKey === 'admin') $list = ['*'];
                $cfg[$roleKey] = array_values(array_unique($list));
                setAppSetting('access.role_permissions', json_encode($cfg, JSON_UNESCAPED_SLASHES));
                $redirectToRole($roleKey, 'Access settings saved for ' . $roles[$roleKey] . '.');
            }
        }
    }
}

$isEnabled = getAccessRolePermissionsConfig() !== null;
$selectedPerms = (array)($cfg[$selectedRole] ?? []);
$isAll = in_array('*', $selectedPerms, true) || $selectedRole === 'admin';
$cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES);
$isCustomRole = isset($customRoles[$selectedRole]);
$selectedScope = 'internal';
if (str_starts_with($selectedRole, 'client_')) $selectedScope = 'client';
elseif (str_starts_with($selectedRole, 'vendor_')) $selectedScope = 'vendor';
elseif ($isCustomRole) $selectedScope = (string)($customRoles[$selectedRole]['scope'] ?? 'internal');
$roleUserCount = 0;
$stmtCnt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role = ? LIMIT 1");
if ($stmtCnt) {
    $stmtCnt->bind_param('s', $selectedRole);
    $stmtCnt->execute();
    $roleUserCount = (int)(($stmtCnt->get_result()->fetch_assoc() ?: [])['cnt'] ?? 0);
    $stmtCnt->close();
}
?>

<?php $pageTitle = 'Access Management'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="h3 mb-1">Access Management</div>
      <div class="text-muted small">Configure role permissions used across the CRM. Admin always has full access.</div>
    </div>
    <a class="btn btn-light border btn-sm" href="settings.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-3">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span>Roles</span>
          <span class="badge <?php echo $isEnabled ? 'bg-success-subtle text-success border' : 'bg-warning-subtle text-warning border'; ?>">
            <?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?>
          </span>
        </div>
        <div class="card-body">
          <div class="text-muted small mb-2">Select a role to edit permissions.</div>
          <div class="d-grid gap-2 mb-2">
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#addRoleModal">
              <i class="bi bi-plus-circle me-1"></i>Add Role
            </button>
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#createFromTemplateModal">
              <i class="bi bi-lightning-charge me-1"></i>Create From Template
            </button>
          </div>
          <div class="list-group">
            <?php foreach ($roles as $rk => $rl): ?>
              <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $rk === $selectedRole ? 'active' : ''; ?>"
                 href="?role=<?php echo htmlspecialchars($rk); ?>">
                <span><?php echo htmlspecialchars($rl); ?></span>
                <?php if ($rk === 'admin'): ?>
                  <span class="badge bg-light text-dark border">All</span>
                <?php elseif (isset($customRoles[$rk])): ?>
                  <?php $sc = (string)($customRoles[$rk]['scope'] ?? 'internal'); ?>
                  <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(ucfirst($sc)); ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light fw-semibold">Tools</div>
        <div class="card-body d-grid gap-2">
          <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#permissionsModal"><i class="bi bi-key me-1"></i>Manage Permissions</button>
          <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#exportModal"><i class="bi bi-braces me-1"></i>Export</button>
          <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import</button>
          <form method="post" class="d-grid gap-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="disable">Disable Permissions</button>
            <button class="btn btn-outline-danger btn-sm" type="submit" name="action" value="reset">Reset Defaults</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-9">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
          <span>Edit Permissions</span>
          <div class="d-flex gap-2 align-items-center">
            <div class="input-group input-group-sm" style="width: 260px;">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control" id="permSearch" placeholder="Search permission...">
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
              <div class="fw-semibold"><?php echo htmlspecialchars($roles[$selectedRole] ?? $selectedRole); ?></div>
              <div class="text-muted small">Permissions apply across UI + backend when enforcement is enabled.</div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <select class="form-select form-select-sm" id="copyFromRole" style="width: 240px;" <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>
                <option value="">Copy permissions from…</option>
                <?php foreach ($roles as $rk => $rl): ?>
                  <?php if ($rk === $selectedRole) continue; ?>
                  <option value="<?php echo htmlspecialchars($rk); ?>"><?php echo htmlspecialchars($rl); ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-outline-secondary btn-sm" type="button" id="btnCopyRole" <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>Copy</button>
              <button class="btn btn-light border btn-sm" type="button" id="btnSelectAll" <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>Select All</button>
              <button class="btn btn-light border btn-sm" type="button" id="btnClearAll" <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>Clear All</button>
              <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#roleDetailsModal">View Role</button>
              <?php if ($isCustomRole): ?>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#editRoleModal">Edit Role</button>
              <?php endif; ?>
              <?php if ($isCustomRole): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                  <input type="hidden" name="action" value="delete_role">
                  <input type="hidden" name="role_key" value="<?php echo htmlspecialchars($selectedRole); ?>">
                  <button class="btn btn-outline-danger btn-sm" type="submit">Delete Role</button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <form method="post" id="rolePermForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="role_key" value="<?php echo htmlspecialchars($selectedRole); ?>">

            <ul class="nav nav-tabs" role="tablist">
              <?php $ti = 0; foreach ($permissionGroups as $gName => $gList): ?>
                <li class="nav-item" role="presentation">
                  <button class="nav-link <?php echo $ti === 0 ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-<?php echo htmlspecialchars($gName); ?>" type="button" role="tab">
                    <?php echo htmlspecialchars($gName); ?>
                  </button>
                </li>
              <?php $ti++; endforeach; ?>
            </ul>

            <div class="tab-content border border-top-0 rounded-bottom p-3">
              <?php $ti = 0; foreach ($permissionGroups as $gName => $gList): ?>
                <div class="tab-pane fade <?php echo $ti === 0 ? 'show active' : ''; ?>" id="tab-<?php echo htmlspecialchars($gName); ?>" role="tabpanel">
                  <div class="d-flex justify-content-end gap-2 mb-3">
                    <button type="button" class="btn btn-light border btn-sm" data-group-all="<?php echo htmlspecialchars($gName); ?>" <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>All</button>
                    <button type="button" class="btn btn-light border btn-sm" data-group-none="<?php echo htmlspecialchars($gName); ?>" <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>None</button>
                  </div>
                  <div class="row g-2">
                    <?php foreach ($gList as $permKey): ?>
                      <?php if (!isset($permissions[$permKey])) continue; ?>
                      <?php $checked = $isAll || in_array($permKey, $selectedPerms, true); ?>
                      <div class="col-12 col-md-6 perm-item" data-perm-text="<?php echo htmlspecialchars(strtolower($permKey . ' ' . $permissions[$permKey])); ?>">
                        <div class="border rounded p-2 d-flex align-items-center justify-content-between">
                          <div class="me-2">
                            <div class="fw-semibold small"><?php echo htmlspecialchars($permissions[$permKey]); ?></div>
                            <div class="text-muted" style="font-size:0.72rem;"><?php echo htmlspecialchars($permKey); ?></div>
                          </div>
                          <div class="form-check form-switch m-0">
                            <input class="form-check-input perm-checkbox" type="checkbox"
                              name="perm[]"
                              value="<?php echo htmlspecialchars($permKey); ?>"
                              data-group="<?php echo htmlspecialchars($gName); ?>"
                              <?php echo $checked ? 'checked' : ''; ?>
                              <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php $ti++; endforeach; ?>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button class="btn btn-primary btn-sm" type="submit" <?php echo $selectedRole === 'admin' ? 'disabled' : ''; ?>>Save Role</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="addRoleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Add New Role</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="add_role">
          <div class="mb-3">
            <label class="form-label">Role Key</label>
            <input type="text" class="form-control" name="new_role_key" placeholder="example: operations_supervisor" required>
            <div class="text-muted small mt-1">Lowercase letters/numbers/underscore only.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Role Label</label>
            <input type="text" class="form-control" name="new_role_label" placeholder="Example: Operations – Supervisor" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Scope</label>
            <select class="form-select" name="new_role_scope" id="newRoleScope">
              <option value="internal" selected>Internal</option>
              <option value="client">Client</option>
              <option value="vendor">Vendor</option>
            </select>
            <div class="text-muted small mt-1">Scope controls where the role is available in user creation pages.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Template</label>
            <select class="form-select" name="new_role_template" id="newRoleTemplate">
              <option value="">Blank</option>
              <?php foreach ($roleTemplates as $scopeKey => $tpls): ?>
                <?php foreach ($tpls as $tplKey => $tpl): ?>
                  <option value="<?php echo htmlspecialchars($tplKey); ?>" data-scope="<?php echo htmlspecialchars($scopeKey); ?>">
                    <?php echo htmlspecialchars((string)($tpl['label'] ?? $tplKey)); ?>
                  </option>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </select>
            <div class="text-muted small mt-1">Choose a preset to auto-fill permissions for this scope.</div>
          </div>
          <div class="mb-0">
            <label class="form-label">Copy From</label>
            <select class="form-select" name="copy_from_role">
              <option value="">Start empty</option>
              <?php foreach ($roles as $rk => $rl): ?>
                <option value="<?php echo htmlspecialchars($rk); ?>"><?php echo htmlspecialchars($rl); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Create Role</button>
        </div>
      </form>
    </div>
  </div>
</div>
<div class="modal fade" id="permissionsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h5 class="modal-title">Manage Permissions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-lg-5">
            <div class="border rounded p-3 bg-light">
              <div class="fw-semibold mb-2">Add Permission</div>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add_permission">
                <div class="mb-2">
                  <label class="form-label small text-muted">Key</label>
                  <input class="form-control form-control-sm" name="perm_key" placeholder="example: mymodule.access" required>
                </div>
                <div class="mb-2">
                  <label class="form-label small text-muted">Label</label>
                  <input class="form-control form-control-sm" name="perm_label" placeholder="Example: My Module – Access" required>
                </div>
                <div class="mb-3">
                  <label class="form-label small text-muted">Group</label>
                  <input class="form-control form-control-sm" name="perm_group" placeholder="Example: My Module" value="Custom">
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Add</button>
              </form>
            </div>
          </div>
          <div class="col-lg-7">
            <div class="fw-semibold mb-2">Custom Permissions</div>
            <?php if (empty($customPermissionsList)): ?>
              <div class="text-muted">No custom permissions added.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>Key</th>
                      <th>Label</th>
                      <th>Group</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($customPermissionsList as $row): ?>
                      <?php if (!is_array($row)) continue; ?>
                      <?php $k = (string)($row['key'] ?? ''); ?>
                      <tr>
                        <td class="text-muted small"><?php echo htmlspecialchars($k); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars((string)($row['label'] ?? '')); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars((string)($row['group'] ?? 'Custom')); ?></td>
                        <td class="text-end">
                          <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="delete_permission">
                            <input type="hidden" name="perm_key" value="<?php echo htmlspecialchars($k); ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="createFromTemplateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="post" id="createFromTemplateForm">
        <div class="modal-header">
          <h5 class="modal-title">Create Role From Template</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="add_role">

          <div class="row g-2">
            <div class="col-12">
              <label class="form-label">Scope</label>
              <select class="form-select" id="tplScope" name="new_role_scope">
                <option value="client">Client</option>
                <option value="vendor">Vendor</option>
                <option value="internal">Internal</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Template</label>
              <select class="form-select" id="tplTemplate" name="new_role_template">
                <option value="">Choose template…</option>
                <?php foreach ($roleTemplates as $scopeKey => $tpls): ?>
                  <?php foreach ($tpls as $tplKey => $tpl): ?>
                    <option value="<?php echo htmlspecialchars($tplKey); ?>" data-scope="<?php echo htmlspecialchars($scopeKey); ?>" data-label="<?php echo htmlspecialchars((string)($tpl['label'] ?? $tplKey)); ?>">
                      <?php echo htmlspecialchars((string)($tpl['label'] ?? $tplKey)); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </select>
              <div class="text-muted small mt-1">This will auto-fill permissions from the template.</div>
            </div>

            <div class="col-12">
              <label class="form-label">Role Label</label>
              <input type="text" class="form-control" id="tplRoleLabel" name="new_role_label" placeholder="Example: Client Viewer" required>
            </div>
            <div class="col-12">
              <label class="form-label">Role Key</label>
              <input type="text" class="form-control" id="tplRoleKey" name="new_role_key" placeholder="example: client_viewer_custom" required>
              <div class="text-muted small mt-1">Auto-suggested from scope + template. You can edit it.</div>
            </div>

            <input type="hidden" name="copy_from_role" value="">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Create Role</button>
        </div>
      </form>
    </div>
  </div>
</div>
<div class="modal fade" id="roleDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h5 class="modal-title">Role Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="text-muted small">Role Key</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($selectedRole); ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Scope</div>
            <div class="fw-semibold"><?php echo htmlspecialchars(ucfirst($selectedScope)); ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Label</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($roles[$selectedRole] ?? $selectedRole); ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small">Users Assigned</div>
            <div class="fw-semibold"><?php echo number_format((int)$roleUserCount); ?></div>
          </div>
        </div>
        <hr>
        <div class="fw-semibold mb-2">Permissions</div>
        <?php if ($selectedRole === 'admin'): ?>
          <div class="text-muted">Admin has full access (*).</div>
        <?php else: ?>
          <?php
            $selectedSet = [];
            foreach ($selectedPerms as $p) { $selectedSet[(string)$p] = true; }
          ?>
          <div class="accordion" id="permAcc">
            <?php $gi = 0; foreach ($permissionGroups as $gName => $gList): ?>
              <?php
                $items = [];
                foreach ($gList as $k) {
                  if (isset($selectedSet[$k]) && isset($permissions[$k])) $items[$k] = $permissions[$k];
                }
                if (empty($items)) { $gi++; continue; }
              ?>
              <div class="accordion-item">
                <h2 class="accordion-header" id="h-<?php echo $gi; ?>">
                  <button class="accordion-button <?php echo $gi === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#c-<?php echo $gi; ?>">
                    <?php echo htmlspecialchars($gName); ?>
                    <span class="badge bg-light text-dark border ms-2"><?php echo number_format(count($items)); ?></span>
                  </button>
                </h2>
                <div id="c-<?php echo $gi; ?>" class="accordion-collapse collapse <?php echo $gi === 0 ? 'show' : ''; ?>" data-bs-parent="#permAcc">
                  <div class="accordion-body">
                    <div class="row g-2">
                      <?php foreach ($items as $k => $lbl): ?>
                        <div class="col-12 col-md-6">
                          <div class="border rounded p-2">
                            <div class="fw-semibold small"><?php echo htmlspecialchars($lbl); ?></div>
                            <div class="text-muted" style="font-size:0.72rem;"><?php echo htmlspecialchars($k); ?></div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php $gi++; endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php if ($isCustomRole): ?>
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Edit Role</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="edit_role">
          <input type="hidden" name="role_key" value="<?php echo htmlspecialchars($selectedRole); ?>">
          <div class="mb-3">
            <label class="form-label">Role Key</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($selectedRole); ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Role Label</label>
            <input type="text" class="form-control" name="edit_role_label" value="<?php echo htmlspecialchars($roles[$selectedRole] ?? ''); ?>" required>
          </div>
          <div class="mb-0">
            <label class="form-label">Scope</label>
            <select class="form-select" name="edit_role_scope">
              <option value="internal" <?php echo $selectedScope === 'internal' ? 'selected' : ''; ?>>Internal</option>
              <option value="client" <?php echo $selectedScope === 'client' ? 'selected' : ''; ?>>Client</option>
              <option value="vendor" <?php echo $selectedScope === 'vendor' ? 'selected' : ''; ?>>Vendor</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h5 class="modal-title">Export Access Settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted small mb-2">Copy this JSON for backup or transfer to another environment.</div>
        <textarea class="form-control" rows="10" readonly><?php echo htmlspecialchars(json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Import Access Settings</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="import">
          <div class="text-muted small mb-2">Paste a previously exported JSON configuration.</div>
          <textarea class="form-control" name="import_json" rows="10" placeholder="{...}"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const cfg = <?php echo $cfgJson ? $cfgJson : '{}'; ?>;
  const roleKey = <?php echo json_encode($selectedRole); ?>;

  const q = (sel) => document.querySelector(sel);
  const qa = (sel) => Array.from(document.querySelectorAll(sel));

  const scopeSel = q('#newRoleScope');
  const tplSel = q('#newRoleTemplate');
  const filterTemplates = () => {
    if (!scopeSel || !tplSel) return;
    const scope = String(scopeSel.value || 'internal');
    const opts = Array.from(tplSel.querySelectorAll('option'));
    let firstVisible = '';
    opts.forEach((opt, idx) => {
      const v = String(opt.value || '');
      const os = String(opt.getAttribute('data-scope') || '');
      const visible = (v === '') || (os === scope);
      opt.hidden = !visible;
      if (visible && firstVisible === '') firstVisible = v;
    });
    if (tplSel.options[tplSel.selectedIndex] && tplSel.options[tplSel.selectedIndex].hidden) {
      tplSel.value = firstVisible;
    }
  };
  if (scopeSel) scopeSel.addEventListener('change', filterTemplates);
  filterTemplates();

  const slugify = (s) => {
    s = String(s || '').toLowerCase().trim();
    s = s.replace(/[^a-z0-9\s_-]/g, '');
    s = s.replace(/\s+/g, '_');
    s = s.replace(/_+/g, '_');
    s = s.replace(/^_+|_+$/g, '');
    return s;
  };

  const tplScope = q('#tplScope');
  const tplTemplate = q('#tplTemplate');
  const tplLabel = q('#tplRoleLabel');
  const tplKey = q('#tplRoleKey');
  const syncTpl = () => {
    if (!tplScope || !tplTemplate) return;
    const scope = String(tplScope.value || 'client');
    Array.from(tplTemplate.querySelectorAll('option')).forEach(opt => {
      const v = String(opt.value || '');
      const os = String(opt.getAttribute('data-scope') || '');
      opt.hidden = (v !== '' && os !== scope);
    });
    const selOpt = tplTemplate.options[tplTemplate.selectedIndex];
    const tLabel = selOpt ? String(selOpt.getAttribute('data-label') || '') : '';
    if (tplLabel && (tplLabel.value || '').trim() === '' && tLabel) tplLabel.value = tLabel;
    const base = slugify((tplLabel && tplLabel.value) ? tplLabel.value : tLabel);
    if (tplKey && (tplKey.value || '').trim() === '' && base) {
      tplKey.value = scope + '_' + base;
    }
  };
  if (tplScope) tplScope.addEventListener('change', syncTpl);
  if (tplTemplate) tplTemplate.addEventListener('change', () => { if (tplLabel) tplLabel.value = ''; if (tplKey) tplKey.value = ''; syncTpl(); });
  if (tplLabel) tplLabel.addEventListener('input', () => { if (tplKey) tplKey.value = ''; syncTpl(); });
  syncTpl();

  const setAll = (val) => {
    qa('.perm-checkbox').forEach(cb => { if (!cb.disabled) cb.checked = val; });
  };
  const setGroup = (group, val) => {
    qa('.perm-checkbox[data-group="'+group+'"]').forEach(cb => { if (!cb.disabled) cb.checked = val; });
  };

  const applyRolePerms = (sourceRole) => {
    if (!sourceRole) return;
    const list = Array.isArray(cfg[sourceRole]) ? cfg[sourceRole] : [];
    if (list.includes('*')) { setAll(true); return; }
    setAll(false);
    qa('.perm-checkbox').forEach(cb => {
      if (!cb.disabled) cb.checked = list.includes(cb.value);
    });
  };

  const search = q('#permSearch');
  if (search) {
    search.addEventListener('input', () => {
      const term = String(search.value || '').trim().toLowerCase();
      qa('.perm-item').forEach(el => {
        const t = String(el.getAttribute('data-perm-text') || '');
        el.style.display = (!term || t.includes(term)) ? '' : 'none';
      });
    });
  }

  const btnCopy = q('#btnCopyRole');
  if (btnCopy) {
    btnCopy.addEventListener('click', () => {
      const src = q('#copyFromRole');
      const v = src ? String(src.value || '') : '';
      applyRolePerms(v);
    });
  }

  const btnAll = q('#btnSelectAll');
  if (btnAll) btnAll.addEventListener('click', () => setAll(true));
  const btnNone = q('#btnClearAll');
  if (btnNone) btnNone.addEventListener('click', () => setAll(false));

  qa('[data-group-all]').forEach(btn => btn.addEventListener('click', () => setGroup(btn.getAttribute('data-group-all'), true)));
  qa('[data-group-none]').forEach(btn => btn.addEventListener('click', () => setGroup(btn.getAttribute('data-group-none'), false)));
});
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
