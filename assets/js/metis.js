(() => {
  const body = document.body;
  const key = 'lms_sidebar_collapsed';
  const themeKey = 'lms_theme';

  function setCollapsed(val) {
    if (val) body.classList.add('sidebar-collapsed');
    else body.classList.remove('sidebar-collapsed');
    localStorage.setItem(key, val ? '1' : '0');
  }

  function toggleMobile() {
    body.classList.toggle('sidebar-open');
  }

  function applyTheme(theme, persist) {
    body.dataset.theme = theme;
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-bs-theme', theme);
    if (persist) localStorage.setItem(themeKey, theme);
  }

  document.addEventListener('click', (e) => {
    const t = e.target;
    const btnCollapse = t.closest('[data-sidebar-toggle]');
    if (btnCollapse) {
      if (window.matchMedia('(max-width: 992px)').matches) toggleMobile();
      else setCollapsed(!body.classList.contains('sidebar-collapsed'));
      return;
    }

    const btnTheme = t.closest('[data-theme-toggle]');
    if (btnTheme) {
      const cur = body.dataset.theme === 'dark' ? 'dark' : 'light';
      applyTheme(cur === 'dark' ? 'light' : 'dark', true);
      return;
    }

    if (window.matchMedia('(max-width: 992px)').matches) {
      const sidebar = document.querySelector('.app-sidebar');
      const clickedInside = sidebar && sidebar.contains(t);
      const topbarToggle = t.closest('[data-sidebar-toggle]');
      if (!clickedInside && !topbarToggle) body.classList.remove('sidebar-open');
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    const stored = localStorage.getItem(key);
    if (stored === '1') body.classList.add('sidebar-collapsed');

    const themeStored = localStorage.getItem(themeKey);
    const theme = themeStored === 'dark' ? 'dark' : 'light';
    applyTheme(theme, true);
  });
})();
