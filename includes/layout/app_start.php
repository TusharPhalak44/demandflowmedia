<?php
$layoutTitle = $pageTitle ?? 'Dashboard';
$layoutUser = getCurrentUser();
$layoutPage = basename($_SERVER['PHP_SELF'] ?? '');
if ($layoutPage !== '' && stripos($layoutPage, '.php') === false) {
    $layoutPage = basename($_SERVER['SCRIPT_NAME'] ?? $layoutPage);
}
$layoutBackUrl = $layoutBackUrl ?? appBackUrl('');
$layoutBase = function_exists('appBasePath') ? appBasePath() : '';
$layoutModulesBase = $layoutBase . '/modules';
$layoutAssetsBase = $layoutBase . '/assets';
$layoutDefaultTheme = function_exists('getAppSetting') ? (string)(getAppSetting('ui.theme.default', 'light') ?? 'light') : 'light';
$layoutDefaultTheme = $layoutDefaultTheme === 'dark' ? 'dark' : 'light';
$layoutAllowUserOverride = function_exists('getAppSetting') ? ((string)(getAppSetting('ui.theme.allow_user_override', '1') ?? '1') === '1') : true;
$layoutDashUrl = '#';
if (isAdmin() || isDirector()) $layoutDashUrl = $layoutModulesBase . '/dashboard/admin-dashboard';
elseif (isSales()) $layoutDashUrl = $layoutModulesBase . '/dashboard/sales-dashboard';
elseif (isAgent()) $layoutDashUrl = $layoutModulesBase . '/dashboard/operations-dashboard';
elseif (isFormFiller()) $layoutDashUrl = $layoutModulesBase . '/dashboard/email-marketing-dashboard';
elseif (isQA()) $layoutDashUrl = $layoutModulesBase . '/qa/dashboard';
elseif (function_exists('isVendor') && isVendor()) $layoutDashUrl = $layoutModulesBase . '/dashboard/vendor-dashboard';
elseif (function_exists('isClient') && isClient()) $layoutDashUrl = $layoutModulesBase . '/dashboard/client-dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($layoutTitle . ' - TGS - DemandFlow Bridge'); ?></title>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/images/logos/Only-Logo.png">
  <script>
    (() => {
      try {
        const defTheme = <?php echo json_encode($layoutDefaultTheme); ?>;
        const allowOverride = <?php echo $layoutAllowUserOverride ? 'true' : 'false'; ?>;
        const stored = allowOverride ? localStorage.getItem('lms_theme') : null;
        const theme = (stored === 'dark' || stored === 'light') ? stored : defTheme;
        if (allowOverride && !stored) localStorage.setItem('lms_theme', theme);
        const apply = (el) => {
          if (!el) return;
          el.setAttribute('data-theme', theme);
          el.setAttribute('data-bs-theme', theme);
        };
        apply(document.documentElement);
        if (document.body) apply(document.body);
        else document.addEventListener('DOMContentLoaded', () => apply(document.body), { once: true });
      } catch (e) {}
    })();
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/metis.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/hr.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/tables.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/app.css">
  <style>
    #app-preloader{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.92);backdrop-filter:saturate(120%) blur(6px);opacity:1;visibility:visible;transition:opacity .28s ease,visibility .28s ease}
    [data-bs-theme="dark"] #app-preloader{background:rgba(10,14,22,.88)}
    #app-preloader.ap-hidden{opacity:0;visibility:hidden}
    #app-preloader .ap-wrap{position:relative;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px}
    #app-preloader .ap-spinner{width:min(180px,44vw);height:auto;filter:drop-shadow(0 10px 22px rgba(0,0,0,.10))}
    [data-bs-theme="dark"] #app-preloader .ap-spinner{filter:drop-shadow(0 10px 24px rgba(0,0,0,.35))}
    #app-preloader .ap-text{font-size:.85rem;letter-spacing:.04em;text-transform:uppercase;color:rgba(60,60,60,.65)}
    [data-bs-theme="dark"] #app-preloader .ap-text{color:rgba(230,235,255,.65)}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (() => {
      const start = performance && performance.now ? performance.now() : Date.now();
      const minMs = 260;
      const show = () => {
        const el = document.getElementById('app-preloader');
        if (!el) return;
        el.classList.remove('ap-hidden');
      };
      const hide = () => {
        const el = document.getElementById('app-preloader');
        if (!el) return;
        const now = performance && performance.now ? performance.now() : Date.now();
        const wait = Math.max(0, minMs - (now - start));
        window.setTimeout(() => {
          el.classList.add('ap-hidden');
          window.setTimeout(() => { try { el.remove(); } catch (e) {} }, 340);
        }, wait);
      };
      window.__appLoader = { show, hide };

      document.addEventListener('DOMContentLoaded', () => {
        const aHandler = (e) => {
          if (e.defaultPrevented) return;
          if (e.button !== 0) return;
          if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
          const a = e.target && e.target.closest ? e.target.closest('a') : null;
          if (!a) return;
          if (a.hasAttribute('download')) return;
          const target = (a.getAttribute('target') || '').toLowerCase();
          if (target && target !== '_self') return;
          const href = a.getAttribute('href') || '';
          if (!href || href === '#' || href.startsWith('javascript:')) return;
          if (href.startsWith('mailto:') || href.startsWith('tel:')) return;
          if (a.getAttribute('data-no-loader') === '1') return;
          let url;
          try { url = new URL(href, window.location.href); } catch (err) { return; }
          if (url.origin !== window.location.origin) return;
          if (url.pathname.endsWith('.php')) {
            e.preventDefault();
            url.pathname = url.pathname.slice(0, -4);
            show();
            window.location.href = url.toString();
            return;
          }
          if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
          show();
        };
        document.addEventListener('click', aHandler, true);
        document.addEventListener('submit', (e) => {
          if (e.defaultPrevented) return;
          try {
            const f = e.target;
            if (f && f.tagName === 'FORM') {
              const action = f.getAttribute('action') || '';
              if (action && !action.startsWith('#') && !action.startsWith('javascript:')) {
                const u = new URL(action, window.location.href);
                if (u.origin === window.location.origin && u.pathname.endsWith('.php')) {
                  u.pathname = u.pathname.slice(0, -4);
                  f.setAttribute('action', u.toString());
                }
              }
            }
          } catch (err) {}
          show();
        }, true);
        window.addEventListener('beforeunload', () => { show(); });
      });
      window.addEventListener('load', hide);
    })();
  </script>
</head>
<body>
<div id="app-preloader" role="status" aria-live="polite">
  <div class="ap-wrap">
    <img class="ap-spinner" src="<?php echo htmlspecialchars($layoutAssetsBase); ?>/images/logos/infinite-spinner.svg" alt="Loading">
    <div class="ap-text">Loading</div>
  </div>
</div>
<div class="app-shell">
  <aside class="app-sidebar">
    <div class="brand">
      <a href="<?php echo htmlspecialchars($layoutDashUrl); ?>" class="brand-link d-flex align-items-center gap-2">
       
        <span class="brand-full">DemandFlow Bridge</span>
        <span class="brand-mini">TGS</span>
      </a>
    </div>
    <?php include __DIR__ . '/sidebar.php'; ?>
  </aside>

  <div class="app-main">
    <header class="app-topbar">
      <button type="button" class="btn btn-light border btn-sm" data-sidebar-toggle>
        <i class="bi bi-list"></i>
      </button>
      <h1 class="topbar-title ms-2"><?php echo htmlspecialchars($layoutTitle); ?></h1>
      <?php include __DIR__ . '/topbar.php'; ?>
    </header>
    <main class="app-content">
      <?php
        $isDash = str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/modules/dashboard/') || str_contains($layoutPage, 'dashboard');
        if ($isDash) {
          $incentivesEnabled = function_exists('getAppSetting') && (string)(getAppSetting('dashboard.banner.incentives.enabled', '1') ?? '1') === '1';
          $incentivesRaw = function_exists('getAppSetting') ? (string)(getAppSetting('dashboard.banner.incentives.items', '') ?? '') : '';
          $showBirthdays = function_exists('getAppSetting') && (string)(getAppSetting('dashboard.banner.birthdays.enabled', '1') ?? '1') === '1';
          $showAnni = function_exists('getAppSetting') && (string)(getAppSetting('dashboard.banner.anniversaries.enabled', '1') ?? '1') === '1';
          $todayMd = date('m-d');
          $birthdays = [];
          $anniversaries = [];
          $conn = function_exists('getDbConnection') ? getDbConnection() : null;
          if ($conn && ($showBirthdays || $showAnni)) {
            if ($showBirthdays) {
              $rs = @$conn->query("SELECT full_name FROM users WHERE is_active = 1 AND date_of_birth IS NOT NULL AND DATE_FORMAT(date_of_birth, '%m-%d') = '" . $conn->real_escape_string($todayMd) . "' ORDER BY full_name");
              if ($rs) $birthdays = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
            }
            if ($showAnni) {
              $rs = @$conn->query("SELECT full_name, date_of_joining FROM users WHERE is_active = 1 AND date_of_joining IS NOT NULL AND DATE_FORMAT(date_of_joining, '%m-%d') = '" . $conn->real_escape_string($todayMd) . "' ORDER BY full_name");
              if ($rs) $anniversaries = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
            }
          }

          $items = [];
          if ($incentivesEnabled && trim($incentivesRaw) !== '') {
            $raw = str_replace(["\r\n", "\r"], "\n", $incentivesRaw);
            foreach (explode("\n", $raw) as $line) {
              $line = trim($line);
              if ($line === '') continue;
              $parts = array_map('trim', explode('|', $line));
              $name = (string)($parts[0] ?? '');
              $amt = (string)($parts[1] ?? '');
              if ($name === '') continue;
              $items[] = $amt !== '' ? ($name . ' (Rs. ' . $amt . ')') : $name;
            }
          }
          $bNames = array_map(fn($r) => (string)($r['full_name'] ?? ''), $birthdays);
          $aNames = [];
          foreach ($anniversaries as $r) {
            $n = (string)($r['full_name'] ?? '');
            $dj = (string)($r['date_of_joining'] ?? '');
            $yrs = 0;
            if ($dj !== '') {
              $yrs = (int)date('Y') - (int)substr($dj, 0, 4);
              if ($yrs < 0) $yrs = 0;
            }
            $aNames[] = $yrs > 0 ? ($n . ' (' . $yrs . 'y)') : $n;
          }
      ?>
      <?php if (!empty($items) || !empty($bNames) || !empty($aNames)): ?>
        <div class="dash-banner-wrap mb-3">
          <?php if (!empty($items)): ?>
            <div class="dash-banner dash-banner-incentive">
              <div class="dash-banner-title"><i class="bi bi-trophy me-1"></i>Today Incentive Earners</div>
              <div class="dash-banner-marquee">
                <div class="dash-banner-track">
                  <?php echo htmlspecialchars(implode('  •  ', $items)); ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
          <?php if (!empty($bNames) || !empty($aNames)): ?>
            <div class="dash-banner dash-banner-celebrate">
              <div class="dash-banner-title"><i class="bi bi-stars me-1"></i>Celebrations</div>
              <div class="dash-banner-marquee">
                <div class="dash-banner-track">
                  <?php
                    $msgs = [];
                    if (!empty($bNames)) $msgs[] = 'Happy Birthday: ' . implode(', ', array_filter($bNames));
                    if (!empty($aNames)) $msgs[] = 'Work Anniversary: ' . implode(', ', array_filter($aNames));
                    echo htmlspecialchars(implode('  •  ', $msgs));
                  ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php } ?>
