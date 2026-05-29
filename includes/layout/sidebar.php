<nav class="sidebar-nav">
  <?php $sidebarUser = function_exists('getCurrentUser') ? getCurrentUser() : []; ?>
  <?php $sidebarVendorId = (int)($sidebarUser['vendor_id'] ?? 0); ?>
  <?php $sidebarClientId = (int)($sidebarUser['client_id'] ?? 0); ?>
  <?php $m = isset($layoutModulesBase) ? (string)$layoutModulesBase : (appBasePath() . '/modules'); ?>
  <?php $isInternal = !(function_exists('isVendor') && isVendor()) && !(function_exists('isClient') && isClient()); ?>
  <?php $dash = isset($layoutDashUrl) ? (string)$layoutDashUrl : ($m . '/dashboard/admin-dashboard'); ?>
  <?php $permEnabled = function_exists('getAccessRolePermissionsConfig') && getAccessRolePermissionsConfig() !== null; ?>
  <?php $canPerm = function(string $p) use ($permEnabled): bool { return !$permEnabled || (function_exists('userHasPermission') && userHasPermission($p)); }; ?>
  <?php $canAny = function(array $ps) use ($canPerm): bool { foreach ($ps as $p) { if ($canPerm($p)) return true; } return false; }; ?>

  <div class="sidebar-section">Dashboard</div>
  <?php if ($canAny(['dashboard.admin','dashboard.operations','dashboard.qa','dashboard.sales','dashboard.client','dashboard.vendor','dashboard.marketing','dashboard.access'])): ?>
    <a class="sidebar-link <?php echo in_array($layoutPage, ['admin-dashboard.php','operations-dashboard.php','qa-dashboard.php','email-marketing-dashboard.php','sales-dashboard.php','vendor-dashboard.php','client-dashboard.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($dash); ?>">
      <i class="bi bi-speedometer2"></i><span>Dashboard</span>
    </a>
  <?php endif; ?>
  <?php if ($isInternal && (isAdmin() || isDirector() || hasRole('operations_director','operations_manager') || $canPerm('admin.analytics'))): ?>
    <?php if ($canPerm('admin.analytics')): ?>
    <?php $analyticsActive = in_array($layoutPage, ['campaigns.php','agents.php','sales.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $analyticsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navAnalytics" role="button" aria-expanded="<?php echo $analyticsActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-graph-up-arrow"></i><span>Analytics</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $analyticsActive ? 'show' : ''; ?>" id="navAnalytics">
      <a class="sidebar-sublink <?php echo $layoutPage === 'campaigns.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/analytics/campaigns"><i class="bi bi-megaphone"></i><span>Campaigns</span></a>
      <a class="sidebar-sublink <?php echo $layoutPage === 'agents.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/analytics/agents"><i class="bi bi-people"></i><span>Agents</span></a>
      <a class="sidebar-sublink <?php echo $layoutPage === 'sales.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/analytics/sales"><i class="bi bi-funnel"></i><span>Sales</span></a>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (function_exists('isVendor') && isVendor() && $canAny(['vendors.access','campaigns.view','leads.view','leads.entry','leads.my','revenue.access'])): ?>
    <div class="sidebar-section">Vendor</div>
    <?php if ($canPerm('leads.entry')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'agent.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/entry"><i class="bi bi-plus-circle"></i><span>Add Lead</span></a>
    <?php endif; ?>
    <?php if ($canPerm('leads.my')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'my-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/my"><i class="bi bi-list-check"></i><span>My Leads</span></a>
    <?php endif; ?>
    <?php if ($canPerm('campaigns.view')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-campaigns.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-campaigns"><i class="bi bi-megaphone"></i><span>Campaigns</span></a>
    <?php endif; ?>
    <?php if ($canPerm('leads.view')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-leads"><i class="bi bi-list-task"></i><span>Leads</span></a>
    <?php endif; ?>
    <?php if ($canPerm('revenue.access')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-revenue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-revenue"><i class="bi bi-coin"></i><span>Revenue</span></a>
    <?php endif; ?>
    <?php if ($canPerm('vendors.access')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'vendor-billing.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-billing"><i class="bi bi-receipt"></i><span>Billing</span></a>
    <?php endif; ?>
  <?php elseif (function_exists('isClient') && isClient() && $canAny(['clients.access','campaigns.view','leads.view'])): ?>
    <div class="sidebar-section">Client</div>
    <?php if ($canPerm('campaigns.view')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'client-campaigns.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-campaigns"><i class="bi bi-megaphone"></i><span>Campaigns</span></a>
    <?php endif; ?>
    <?php if ($canPerm('leads.view')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'client-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-leads"><i class="bi bi-list-task"></i><span>Leads</span></a>
    <?php endif; ?>
    <?php if ($canPerm('clients.access')): ?>
      <a class="sidebar-link <?php echo $layoutPage === 'client-billing.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-billing"><i class="bi bi-receipt"></i><span>Billing</span></a>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isInternal && (isSales() || isAdmin() || isDirector() || $canPerm('sales.access'))): ?>
    <?php if ($canPerm('sales.access')): ?>
    <?php $salesActive = in_array($layoutPage, ['sales-dashboard.php','dashboard.php','targets.php','leads.php','lead-create.php','accounts.php','account-create.php','manager-map.php','activity.php','lead-view.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $salesActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navSales" role="button" aria-expanded="<?php echo $salesActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-graph-up"></i><span>Sales</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $salesActive ? 'show' : ''; ?>" id="navSales">
      <a class="sidebar-sublink <?php echo in_array($layoutPage, ['sales-dashboard.php','dashboard.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/dashboard/sales-dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a>
      <?php if (isAdmin() || isSalesDirector()): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'targets.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/targets"><i class="bi bi-bullseye"></i><span>Targets</span></a>
      <?php endif; ?>
      <a class="sidebar-sublink <?php echo $layoutPage === 'leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/leads"><i class="bi bi-funnel"></i><span>Prospects</span></a>
      <a class="sidebar-sublink <?php echo $layoutPage === 'lead-create.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/lead-create"><i class="bi bi-plus-circle"></i><span>Add Prospect</span></a>
      <a class="sidebar-sublink <?php echo in_array($layoutPage, ['accounts.php','account-create.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/accounts"><i class="bi bi-building"></i><span>Accounts</span></a>
      <?php if (isSalesDirector()): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'manager-map.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/sales/manager-map"><i class="bi bi-diagram-3"></i><span>Manager ↔ SDR</span></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isInternal && (isAdmin() || isDirector() || $canAny(['clients.access','vendors.access']))): ?>
    <?php if ($canAny(['clients.access','vendors.access'])): ?>
    <?php $dirsActive = in_array($layoutPage, ['clients.php','vendors.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $dirsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navDirectories" role="button" aria-expanded="<?php echo $dirsActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-building"></i><span>Directories</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $dirsActive ? 'show' : ''; ?>" id="navDirectories">
      <a class="sidebar-sublink <?php echo $layoutPage === 'clients.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/clients"><i class="bi bi-building-check"></i><span>Clients</span></a>
      <a class="sidebar-sublink <?php echo $layoutPage === 'vendors.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendors"><i class="bi bi-truck"></i><span>Vendors</span></a>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isInternal && (isAdmin() || isDirector() || isSales() || isQA() || hasRole(['operations_director','operations_manager','operations_agent','agent','email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive']) || $canAny(['campaigns.view','campaigns.manage','forms.manage']))): ?>
    <?php if ($canAny(['campaigns.view','campaigns.manage','forms.manage'])): ?>
    <?php $campActive = in_array($layoutPage, ['campaign-dashboard.php','campaigns-manage.php','campaign-create.php','campaign-delivery.php','campaign-edit.php','campaign-details.php','campaign-leads.php','forms-manage.php','form-edit.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $campActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navCampaigns" role="button" aria-expanded="<?php echo $campActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-megaphone"></i><span>Campaigns</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $campActive ? 'show' : ''; ?>" id="navCampaigns">
      <?php if ($canPerm('campaigns.view')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaign-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canPerm('campaigns.view') || $canPerm('campaigns.manage')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaigns-manage.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/list"><i class="bi bi-list-ul"></i><span>View Campaigns</span></a>
      <?php endif; ?>
      <?php if ($canPerm('campaigns.manage')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaign-create.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/create"><i class="bi bi-plus-square"></i><span>Create Campaign</span></a>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaign-allocation.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/allocation"><i class="bi bi-bullseye"></i><span>Campaign Allocation</span></a>
        <a class="sidebar-sublink <?php echo $layoutPage === 'campaign-delivery.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/campaigns/files"><i class="bi bi-folder2-open"></i><span>Campaign Files</span></a>
      <?php endif; ?>
      <?php if ($canPerm('forms.manage')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'forms-manage.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/forms/forms-manage"><i class="bi bi-ui-checks-grid"></i><span>Lead Forms</span></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isInternal && (isAgent() || isAdmin() || isDirector() || isFormFiller() || isQA() || $canAny(['leads.view','leads.manage','leads.entry','leads.my','leads.export']))): ?>
    <?php if ($canAny(['leads.view','leads.manage','leads.entry','leads.my','leads.export'])): ?>
    <?php $leadsActive = in_array($layoutPage, ['operations-assign.php','agent.php','my-leads.php','form-filler.php','email-leads.php','bulk-upload.php','leads-edit.php','lead-details.php','get_lead_details.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $leadsActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navLeads" role="button" aria-expanded="<?php echo $leadsActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-journal-text"></i><span>Leads</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $leadsActive ? 'show' : ''; ?>" id="navLeads">
      <?php if ($canPerm('leads.entry')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'agent.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/entry"><i class="bi bi-keyboard"></i><span>Lead Entry</span></a>
      <?php endif; ?>
      <?php if ($canPerm('leads.my')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'my-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/my"><i class="bi bi-list-check"></i><span>My Leads</span></a>
      <?php endif; ?>
      <?php if ($canPerm('leads.marketing')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'form-filler.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/marketing"><i class="bi bi-pencil-square"></i><span>Marketing Entry</span></a>
      <?php endif; ?>
      <?php if ($canPerm('leads.email')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'email-leads.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/email"><i class="bi bi-envelope-at"></i><span>Email Leads</span></a>
      <?php endif; ?>
      <?php if ($canPerm('leads.bulk_upload')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'bulk-upload.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/bulk"><i class="bi bi-upload"></i><span>Bulk Upload</span></a>
      <?php endif; ?>
      <?php if ($canPerm('leads.manage')): ?>
        <?php $leadsMode = (string)($_GET['mode'] ?? ''); ?>
        <?php $manageLeadsActive = ($layoutPage === 'leads-edit.php' && $leadsMode !== 'vendor'); ?>
        <a class="sidebar-sublink <?php echo $manageLeadsActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/list"><i class="bi bi-clipboard-data"></i><span>Manage Leads</span></a>
      <?php endif; ?>
      <?php if ($canPerm('leads.manage')): ?>
        <?php $vendorLeadsActive = ($layoutPage === 'leads-edit.php' && $leadsMode === 'vendor'); ?>
        <a class="sidebar-sublink <?php echo $vendorLeadsActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/vendors"><i class="bi bi-truck"></i><span>Vendor Leads</span></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isInternal && $canAny(['tasks.access','tasks.create','tasks.manage','tasks.reports'])): ?>
    <?php $tasksActive = in_array($layoutPage, ['tasks.php','task-create.php','task-view.php','reports.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $tasksActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navTasks" role="button" aria-expanded="<?php echo $tasksActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-kanban"></i><span>Tasks</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $tasksActive ? 'show' : ''; ?>" id="navTasks">
      <?php if ($canPerm('tasks.access')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'tasks.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/tasks"><i class="bi bi-list-task"></i><span>Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canPerm('tasks.create')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'task-create.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/tasks/create"><i class="bi bi-plus-circle"></i><span>Create Task</span></a>
      <?php endif; ?>
      <?php if ($canPerm('tasks.reports')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'reports.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/tasks/reports"><i class="bi bi-bar-chart"></i><span>Reports</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($isInternal && (isQA() || isAdmin() || $canPerm('qa.access'))): ?>
    <?php if ($canPerm('qa.access')): ?>
    <?php $qaActive = in_array($layoutPage, ['qa-dashboard.php','qa-audit.php','qa-request-assignment.php','qa-assign.php','dashboard','audit','request','assignments','export.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $qaActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navQA" role="button" aria-expanded="<?php echo $qaActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-shield-check"></i><span>QA</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $qaActive ? 'show' : ''; ?>" id="navQA">
      <a class="sidebar-sublink <?php echo in_array($layoutPage, ['qa-dashboard.php','dashboard'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a>
      <a class="sidebar-sublink <?php echo in_array($layoutPage, ['qa-audit.php','audit'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/audit"><i class="bi bi-clipboard-check"></i><span>Audit Queue</span></a>
      <?php if (isQA() && !isAdmin()): ?>
        <a class="sidebar-sublink <?php echo in_array($layoutPage, ['qa-request-assignment.php','request'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/request"><i class="bi bi-send"></i><span>Request Assignment</span></a>
      <?php endif; ?>
      <?php if ($canPerm('qa.assignments')): ?>
        <a class="sidebar-sublink <?php echo in_array($layoutPage, ['qa-assign.php','assignments'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/qa/assignments"><i class="bi bi-person-check"></i><span>Assignments</span></a>
      <?php endif; ?>
      <?php if ($canPerm('leads.export')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'export.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/leads/export"><i class="bi bi-file-earmark-arrow-down"></i><span>Reports</span></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isInternal && $canAny(['hr.access','hr.attendance','hr.leaves','hr.payslips','hr.attendance_admin','hr.shifts','hr.payroll'])): ?>
    <?php $hrActive = in_array($layoutPage, ['hr-dashboard.php','attendance.php','paid-leaves.php','payslips.php','attendance-admin.php','attendance-export.php','attendance-monthly-report.php','attendance-monthly-export.php','shifts.php','salary-setup.php','bonus-loans.php','payroll.php','payroll-export.php','payslip-view.php','payslip-pdf.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $hrActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navHR" role="button" aria-expanded="<?php echo $hrActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-person-badge"></i><span>HR & Payroll</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $hrActive ? 'show' : ''; ?>" id="navHR">
      <?php if ($canPerm('hr.access')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'hr-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/hr-dashboard"><i class="bi bi-columns-gap"></i><span>Dashboard</span></a>
      <?php endif; ?>
      <?php if ($canAny(['hr.attendance','hr.access'])): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'attendance.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/attendance"><i class="bi bi-fingerprint"></i><span>Attendance</span></a>
      <?php endif; ?>
      <?php if ($canAny(['hr.leaves','hr.access'])): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'paid-leaves.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/paid-leaves"><i class="bi bi-calendar2-week"></i><span>Paid Leaves</span></a>
      <?php endif; ?>
      <?php if ($canAny(['hr.payslips','hr.access'])): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'payslips.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/payslips"><i class="bi bi-receipt"></i><span>Payslips</span></a>
      <?php endif; ?>
      <?php if ($canPerm('hr.attendance_admin')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'attendance-admin.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/attendance-admin"><i class="bi bi-calendar2-check"></i><span>Attendance Admin</span></a>
      <?php endif; ?>
      <?php if ($canPerm('hr.shifts')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'shifts.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/shifts"><i class="bi bi-clock-history"></i><span>Shifts</span></a>
      <?php endif; ?>
      <?php if ($canPerm('hr.payroll')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'salary-setup.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/salary-setup"><i class="bi bi-wallet2"></i><span>Salary Setup</span></a>
        <a class="sidebar-sublink <?php echo $layoutPage === 'bonus-loans.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/bonus-loans"><i class="bi bi-gift"></i><span>Bonus & Loans</span></a>
        <a class="sidebar-sublink <?php echo $layoutPage === 'payroll.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/hr/payroll"><i class="bi bi-calculator"></i><span>Payroll</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($isInternal && (isAdmin() || isDirector() || hasRole(['manager_director','sales_director','operations_director']) || $canPerm('revenue.access'))): ?>
    <?php if ($canPerm('revenue.access')): ?>
    <?php $revActive = in_array($layoutPage, ['revenue-dashboard.php','revenue.php','expenses.php','invoices.php','invoice-edit.php','billto-templates.php','agent-roi.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $revActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navRevenue" role="button" aria-expanded="<?php echo $revActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-cash-coin"></i><span>Revenue</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $revActive ? 'show' : ''; ?>" id="navRevenue">
      <a class="sidebar-sublink <?php echo $layoutPage === 'revenue-dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/revenue-dashboard"><i class="bi bi-speedometer"></i><span>Dashboard</span></a>
      <a class="sidebar-sublink <?php echo $layoutPage === 'revenue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/revenue"><i class="bi bi-currency-dollar"></i><span>Campaign Revenue</span></a>
      <a class="sidebar-sublink <?php echo $layoutPage === 'expenses.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/expenses"><i class="bi bi-receipt-cutoff"></i><span>Expenses</span></a>
      <a class="sidebar-sublink <?php echo in_array($layoutPage, ['invoices.php','invoice-edit.php'], true) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/invoices"><i class="bi bi-file-earmark-text"></i><span>Invoices</span></a>
      <a class="sidebar-sublink <?php echo $layoutPage === 'billto-templates.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/revenue/billto-templates"><i class="bi bi-journal-bookmark"></i><span>Bill To Templates</span></a>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isInternal && $canPerm('chat.access')): ?>
    <div class="sidebar-section">Communication</div>
    <a class="sidebar-link <?php echo $layoutPage === 'chat.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/chat/chat"><i class="bi bi-chat-dots"></i><span>Chat</span></a>
  <?php endif; ?>

  <?php if ($isInternal && (isAdmin() || $canAny(['users.internal.manage','clients.users.manage','vendors.users.manage','admin.settings','admin.analytics','admin.call_history']))): ?>
    <?php if ($canAny(['users.internal.manage','clients.users.manage','vendors.users.manage','admin.settings','admin.analytics','admin.call_history'])): ?>
    <?php $sysActive = in_array($layoutPage, ['manage-users.php','vendor-users.php','client-users.php','db-diagnostics.php','team-management.php','settings.php'], true); ?>
    <a class="sidebar-link sidebar-toggle <?php echo $sysActive ? 'active' : ''; ?>" data-bs-toggle="collapse" href="#navSystem" role="button" aria-expanded="<?php echo $sysActive ? 'true' : 'false'; ?>">
      <span class="sidebar-title"><i class="bi bi-gear"></i><span>System</span></span>
      <i class="bi bi-chevron-down sidebar-chevron"></i>
    </a>
    <div class="collapse sidebar-submenu <?php echo $sysActive ? 'show' : ''; ?>" id="navSystem">
      <?php if ($canPerm('users.internal.manage')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'manage-users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/users/manage-users"><i class="bi bi-people"></i><span>Internal Users</span></a>
      <?php endif; ?>
      <?php if ($canPerm('admin.settings')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'team-management.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/team-management"><i class="bi bi-diagram-3"></i><span>Teams</span></a>
        <a class="sidebar-sublink <?php echo $layoutPage === 'settings.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/settings"><i class="bi bi-sliders"></i><span>Settings</span></a>
      <?php endif; ?>
      <?php if ((hasRole('vendor_admin') || isVendor()) && $sidebarVendorId > 0 && $canPerm('vendors.users.manage')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'vendor-users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/vendors/vendor-users?vendor_id=<?php echo (int)$sidebarVendorId; ?>"><i class="bi bi-people"></i><span>Vendor Users</span></a>
      <?php endif; ?>
      <?php if ((hasRole('client_admin') || isClient()) && $sidebarClientId > 0 && $canPerm('clients.users.manage')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'client-users.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/clients/client-users?client_id=<?php echo (int)$sidebarClientId; ?>"><i class="bi bi-people"></i><span>Client Users</span></a>
      <?php endif; ?>
      <?php if ($canPerm('admin.settings')): ?>
        <a class="sidebar-sublink <?php echo $layoutPage === 'db-diagnostics.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($m); ?>/admin/db-diagnostics"><i class="bi bi-clipboard-pulse"></i><span>Diagnostics</span></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</nav>
