<div class="topbar-actions">
  <?php $layoutModulesBase = isset($layoutModulesBase) ? (string)$layoutModulesBase : (appBasePath() . '/modules'); ?>
  <?php $layoutBase = isset($layoutBase) ? (string)$layoutBase : appBasePath(); ?>
  <?php if (isAdmin() || isQA()): ?>
    <form class="topbar-search d-none d-lg-flex" method="get" action="<?php echo htmlspecialchars($layoutModulesBase); ?>/leads/list">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" class="form-control border-start-0" name="search" placeholder="Search leads (ID, email, company)" autocomplete="off">
      </div>
    </form>
  <?php endif; ?>
  <?php
    $uid = (int)($layoutUser['id'] ?? 0);
    if (empty($_SESSION['csrf_token'] ?? '')) {
      if (function_exists('ensureCsrfToken')) ensureCsrfToken();
    }
    $csrfToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($uid > 0) {
      $last = (int)($_SESSION['campaign_end_check_ts'] ?? 0);
      if ($last <= 0 || (time() - $last) > 900) {
        $_SESSION['campaign_end_check_ts'] = time();
        notifyCampaignEndWarningsForUser($uid);
      }
    }
    $unread = $uid > 0 ? getUnreadNotificationCount($uid) : 0;
    $latest = $uid > 0 ? getUserNotifications($uid, 8) : [];
  ?>
  <?php if (!empty($layoutBackUrl)): ?>
    <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars((string)$layoutBackUrl); ?>" aria-label="Back">
      <i class="bi bi-arrow-left"></i>
    </a>
  <?php endif; ?>
  <button class="btn btn-light border btn-sm" type="button" data-theme-toggle aria-label="Toggle theme">
    <i class="bi bi-moon-stars"></i>
  </button>
  <!-- Dialer Trigger -->
  <button class="btn btn-light border btn-sm" type="button" id="dialerTrigger" title="Open Dialer">
    <i class="bi bi-telephone-outbound"></i>
  </button>
  <div class="dropdown">
    <button class="btn btn-light border btn-sm position-relative" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-bell"></i>
      <?php if ($unread > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
          <?php echo (int)min(99, $unread); ?>
        </span>
      <?php endif; ?>
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow-sm border-0 p-0" style="width: 360px;">
      <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
        <div class="fw-semibold">Notifications</div>
        <a class="small text-decoration-none" href="<?php echo htmlspecialchars($layoutModulesBase); ?>/notifications/notifications">View all</a>
      </div>
      <div class="list-group list-group-flush">
        <?php if (empty($latest)): ?>
          <div class="px-3 py-4 text-center text-muted">No notifications yet.</div>
        <?php else: ?>
          <?php foreach ($latest as $n): ?>
            <?php
              $id = (int)($n['id'] ?? 0);
              $title = (string)($n['title'] ?? '');
              $body = (string)($n['body'] ?? '');
              $link = (string)($n['link_url'] ?? '');
              $isRead = (int)($n['is_read'] ?? 0) === 1;
              $createdAt = (string)($n['created_at'] ?? '');
              $when = $createdAt ? date('d M, H:i', strtotime($createdAt)) : '';
              $hrefBase = $layoutModulesBase . '/notifications/mark-read?id=' . $id . '&csrf_token=' . urlencode($csrfToken);
              $href = $link !== '' ? ($hrefBase . '&to=' . urlencode($link)) : $hrefBase;
            ?>
            <a class="list-group-item list-group-item-action <?php echo $isRead ? '' : 'bg-light'; ?>" href="<?php echo htmlspecialchars($href); ?>">
              <div class="d-flex justify-content-between gap-3">
                <div style="min-width:0;">
                  <div class="fw-semibold small text-truncate"><?php echo htmlspecialchars($title); ?></div>
                  <?php if ($body !== ''): ?>
                    <div class="text-muted small" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo htmlspecialchars($body); ?></div>
                  <?php endif; ?>
                </div>
                <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($when); ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="px-3 py-2 border-top d-flex justify-content-end">
        <form method="post" action="<?php echo htmlspecialchars($layoutModulesBase); ?>/notifications/notifications" class="m-0">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
          <input type="hidden" name="action" value="mark_all_read">
          <button class="btn btn-link btn-sm text-decoration-none p-0" type="submit">Mark all read</button>
        </form>
      </div>
    </div>
  </div>
  <div class="dropdown">
    <button class="btn btn-light border btn-sm dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
      <?php if (!empty($layoutUser['profile_pic'])): ?>
        <img src="<?php echo htmlspecialchars($layoutBase . '/' . ltrim((string)$layoutUser['profile_pic'], '/')); ?>" style="width:28px;height:28px;border-radius:10px;object-fit:cover;">
      <?php else: ?>
        <span class="user-initial"><?php echo strtoupper(substr($layoutUser['full_name'] ?? 'U', 0, 1)); ?></span>
      <?php endif; ?>
      <span class="d-none d-md-inline"><?php echo htmlspecialchars(formatUserNameWithRole(($layoutUser['full_name'] ?? 'User'), ($layoutUser['role'] ?? ''))); ?></span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
      <li><a class="dropdown-item" href="<?php echo htmlspecialchars($layoutModulesBase); ?>/users/profile"><i class="bi bi-person me-2"></i>Profile</a></li>
      <li><a class="dropdown-item" href="<?php echo htmlspecialchars($layoutModulesBase); ?>/auth/change-password"><i class="bi bi-key me-2"></i>Change Password</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="<?php echo htmlspecialchars($layoutModulesBase); ?>/auth/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
    </ul>
  </div>
</div>
