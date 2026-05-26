<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(getKnownRoles());
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo 'Invalid token';
        exit;
    }
    markAllNotificationsRead($userId);
    header('Location: notifications');
    exit;
}

$rows = getUserNotifications($userId, 50);
$pageTitle = 'Notifications';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Notifications</h3>
      <div class="text-muted small">Latest updates in one place.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="preferences.php"><i class="bi bi-sliders me-1"></i>Preferences</a>
      <form method="post" class="d-flex gap-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="mark_all_read">
        <button class="btn btn-light border btn-sm" type="submit"><i class="bi bi-check2-all me-1"></i>Mark all read</button>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="list-group list-group-flush">
      <?php if (empty($rows)): ?>
        <div class="p-4 text-center text-muted">No notifications yet.</div>
      <?php else: ?>
        <?php foreach ($rows as $n): ?>
          <?php
            $id = (int)($n['id'] ?? 0);
            $type = (string)($n['type'] ?? '');
            $title = (string)($n['title'] ?? '');
            $body = (string)($n['body'] ?? '');
            $link = (string)($n['link_url'] ?? '');
            $isRead = (int)($n['is_read'] ?? 0) === 1;
            $createdAt = (string)($n['created_at'] ?? '');
            $when = $createdAt ? date('d M Y, H:i', strtotime($createdAt)) : '';
            $href = $link !== '' ? ('mark-read.php?id=' . $id . '&csrf_token=' . urlencode($_SESSION['csrf_token']) . '&to=' . urlencode($link)) : ('mark-read.php?id=' . $id . '&csrf_token=' . urlencode($_SESSION['csrf_token']));
            $tLower = strtolower($type);
            $lLower = strtolower($link);
            $icon = 'bi-bell';
            $iconCls = 'text-secondary';
            if (str_contains($tLower, 'chat') || str_contains($lLower, 'chat')) { $icon = 'bi-chat-dots-fill'; $iconCls = 'text-primary'; }
            elseif (str_contains($tLower, 'lead') || str_contains($lLower, 'lead')) { $icon = 'bi-person-lines-fill'; $iconCls = 'text-info'; }
            elseif (str_contains($tLower, 'campaign') || str_contains($lLower, 'campaign')) { $icon = 'bi-megaphone'; $iconCls = 'text-primary'; }
            elseif (str_contains($tLower, 'invoice') || str_contains($lLower, 'invoice') || str_contains($lLower, 'revenue')) { $icon = 'bi-receipt'; $iconCls = 'text-success'; }
          ?>
          <a class="list-group-item list-group-item-action <?php echo $isRead ? '' : 'bg-light'; ?>" href="<?php echo htmlspecialchars($href); ?>">
            <div class="d-flex justify-content-between align-items-start gap-3">
              <div style="min-width:0;">
                <div class="fw-semibold text-truncate d-flex align-items-center gap-2">
                  <?php if (!$isRead): ?><span class="badge rounded-pill bg-primary" style="width:8px;height:8px;padding:0;"></span><?php endif; ?>
                  <i class="bi <?php echo htmlspecialchars($icon); ?> <?php echo htmlspecialchars($iconCls); ?>"></i>
                  <span class="text-truncate"><?php echo htmlspecialchars($title); ?></span>
                </div>
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
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
