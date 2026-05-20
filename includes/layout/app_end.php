    </main>
    <footer class="app-footer">
      <div class="d-flex justify-content-between align-items-center">
        <div>© <?php echo date('Y'); ?> Taraj Global Solutions. All rights reserved.</div>
        <div class="small">
          <a class="text-decoration-none" href="https://tarajglobal.com/" target="_blank" rel="noopener">Taraj Global Solutions.</a>
        </div>
      </div>
    </footer>
  </div>
</div>

<?php
  $toastUserId = (int)($layoutUser['id'] ?? 0);
  $toastRows = $toastUserId > 0 ? getUnreadToastNotifications($toastUserId, 3) : [];
?>
<?php if (!empty($toastRows)): ?>
  <div class="toast-container position-fixed top-50 start-50 translate-middle p-3" style="z-index:1080; width: 420px; max-width: calc(100vw - 24px);">
    <?php foreach ($toastRows as $t): ?>
      <?php
        $tid = (int)($t['id'] ?? 0);
        $title = (string)($t['title'] ?? '');
        $body = (string)($t['body'] ?? '');
        $link = (string)($t['link_url'] ?? '');
        $createdAt = (string)($t['created_at'] ?? '');
        $when = $createdAt ? date('d M, H:i', strtotime($createdAt)) : '';
        $l = strtolower($link);
        $tt = strtolower($title);
        $icon = 'bi-bell-fill';
        $iconCls = 'text-secondary';
        if (str_contains($l, 'chat') || str_contains($tt, 'chat')) { $icon = 'bi-chat-dots-fill'; $iconCls = 'text-primary'; }
        elseif (str_contains($l, 'qa') || str_contains($tt, 'qa')) { $icon = 'bi-check2-circle'; $iconCls = 'text-info'; }
        elseif (str_contains($l, 'invoice') || str_contains($l, 'revenue') || str_contains($tt, 'invoice')) { $icon = 'bi-receipt'; $iconCls = 'text-success'; }
        elseif (str_contains($l, 'attendance') || str_contains($l, 'payroll') || str_contains($tt, 'attendance')) { $icon = 'bi-calendar-check'; $iconCls = 'text-warning'; }
        elseif (str_contains($l, 'campaign') || str_contains($tt, 'campaign')) { $icon = 'bi-megaphone'; $iconCls = 'text-primary'; }
      ?>
      <div class="toast border-0 shadow-sm mb-2" role="alert" aria-live="assertive" aria-atomic="true" data-notif-toast data-notif-id="<?php echo $tid; ?>">
        <div class="toast-header">
          <i class="bi <?php echo htmlspecialchars($icon); ?> <?php echo htmlspecialchars($iconCls); ?> me-2"></i>
          <strong class="me-auto"><?php echo htmlspecialchars($title); ?></strong>
          <small class="text-muted"><?php echo htmlspecialchars($when); ?></small>
          <button type="button" class="btn-close ms-2" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
          <?php if ($body !== ''): ?>
            <div class="mb-2"><?php echo htmlspecialchars($body); ?></div>
          <?php endif; ?>
          <?php if ($link !== ''): ?>
            <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars($link); ?>">Open</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php $layoutAssetsBase = isset($layoutAssetsBase) ? (string)$layoutAssetsBase : (appBasePath() . '/assets'); ?>
<script src="<?php echo htmlspecialchars($layoutAssetsBase); ?>/js/metis.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  const csrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? '')); ?>;
  const notifMarkReadUrl = <?php echo json_encode(appBasePath() . '/modules/notifications/mark-read-ajax.php'); ?>;
  const notifPollUrl = <?php echo json_encode(appBasePath() . '/modules/notifications/poll.php'); ?>;
  const shown = new Set();
  let sinceId = 0;
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container position-fixed top-50 start-50 translate-middle p-3';
    container.style.zIndex = '1080';
    container.style.width = '420px';
    container.style.maxWidth = 'calc(100vw - 24px)';
    document.body.appendChild(container);
  }

  function inferIcon(link, title) {
    const l = String(link || '').toLowerCase();
    const t = String(title || '').toLowerCase();
    if (l.includes('chat') || t.includes('chat')) return { icon: 'bi-chat-dots-fill', cls: 'text-primary' };
    if (l.includes('qa') || t.includes('qa')) return { icon: 'bi-check2-circle', cls: 'text-info' };
    if (l.includes('invoice') || l.includes('revenue') || t.includes('invoice')) return { icon: 'bi-receipt', cls: 'text-success' };
    if (l.includes('attendance') || l.includes('payroll') || t.includes('attendance')) return { icon: 'bi-calendar-check', cls: 'text-warning' };
    if (l.includes('campaign') || t.includes('campaign')) return { icon: 'bi-megaphone', cls: 'text-primary' };
    return { icon: 'bi-bell-fill', cls: 'text-secondary' };
  }

  function markRead(id) {
    if (!id || !csrf) return;
    fetch(notifMarkReadUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(csrf) + '&id=' + encodeURIComponent(id)
    }).catch(function(){});
  }

  function renderToast(row) {
    const id = parseInt(row.id || 0, 10);
    if (!id || shown.has(id)) return;
    shown.add(id);
    if (id > sinceId) sinceId = id;
    const title = String(row.title || '');
    const body = String(row.body || '');
    const link = String(row.link_url || '');
    const when = row.created_at ? String(row.created_at).slice(0, 16).replace('T', ' ') : '';
    const ic = inferIcon(link, title);

    const el = document.createElement('div');
    el.className = 'toast border-0 shadow-sm mb-2';
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');
    el.innerHTML =
      '<div class="toast-header">' +
        '<i class="bi me-2"></i>' +
        '<strong class="me-auto"></strong>' +
        '<small class="text-muted"></small>' +
        '<button type="button" class="btn-close ms-2" data-bs-dismiss="toast" aria-label="Close"></button>' +
      '</div>' +
      '<div class="toast-body"></div>';
    const iconEl = el.querySelector('i');
    iconEl.className = 'bi ' + ic.icon + ' ' + ic.cls + ' me-2';
    el.querySelector('strong').textContent = title;
    el.querySelector('small').textContent = when;
    const bodyEl = el.querySelector('.toast-body');
    if (body) {
      const p = document.createElement('div');
      p.className = 'mb-2';
      p.textContent = body;
      bodyEl.appendChild(p);
    }
    if (link) {
      const a = document.createElement('a');
      a.className = 'btn btn-sm btn-primary';
      a.href = link;
      a.textContent = 'Open';
      bodyEl.appendChild(a);
    }
    container.appendChild(el);
    const toast = new bootstrap.Toast(el, { autohide: false });
    toast.show();
    markRead(id);
  }

  document.querySelectorAll('[data-notif-toast]').forEach(function(el) {
    const id = parseInt(el.getAttribute('data-notif-id') || '0', 10);
    if (id) {
      shown.add(id);
      if (id > sinceId) sinceId = id;
      markRead(id);
    }
    const toast = new bootstrap.Toast(el, { autohide: false });
    toast.show();
  });

  function poll() {
    if (!csrf) return;
    fetch(notifPollUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(csrf) + '&since_id=' + encodeURIComponent(String(sinceId))
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.ok || !Array.isArray(data.rows)) return;
        data.rows.forEach(renderToast);
      })
      .catch(function(){});
  }

  setInterval(poll, 45000);
});
</script>

</body>
</html>
