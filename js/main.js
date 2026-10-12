/**
 * Unified Competitive Programming Tracker - Core JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
  initThemeToggle();
  initMetricCountUp();
  initTableSearch();
  initButtonLoadingStates();
});

/**
 * Theme Toggle (Dark / Light) with LocalStorage sync
 */
function initThemeToggle() {
  const toggleBtn = document.getElementById('theme-toggle');
  if (!toggleBtn) return;

  const updateToggleUI = (theme) => {
    const isDark = theme === 'dark';
    const label = toggleBtn.querySelector('.toggle-label');
    const sunIcon = toggleBtn.querySelector('.icon-sun');
    const moonIcon = toggleBtn.querySelector('.icon-moon');

    if (label) label.textContent = isDark ? 'Dark' : 'Light';
    if (sunIcon) sunIcon.style.display = isDark ? 'none' : 'inline-block';
    if (moonIcon) moonIcon.style.display = isDark ? 'inline-block' : 'none';
  };

  const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
  updateToggleUI(currentTheme);

  toggleBtn.addEventListener('click', () => {
    const active = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const next = active === 'dark' ? 'light' : 'dark';
    
    document.documentElement.setAttribute('data-theme', next);
    try {
      localStorage.setItem('ucp-theme', next);
    } catch (e) {
      // Storage unavailable
    }
    updateToggleUI(next);
  });
}

/**
 * Animated Count-Up for Dashboard Metric Numbers
 */
function initMetricCountUp() {
  const metrics = document.querySelectorAll('.metric[data-count]');
  if (!metrics.length) return;

  metrics.forEach((metric) => {
    const rawTarget = metric.getAttribute('data-count');
    const target = parseFloat(rawTarget);
    if (isNaN(target)) return;

    const isDecimal = rawTarget.includes('.');
    const duration = 1200; // ms
    const startTime = performance.now();

    function updateCounter(currentTime) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      // Ease out cubic
      const ease = 1 - Math.pow(1 - progress, 3);
      const current = target * ease;

      metric.textContent = isDecimal 
        ? current.toFixed(1) 
        : Math.round(current).toLocaleString();

      if (progress < 1) {
        requestAnimationFrame(updateCounter);
      } else {
        metric.textContent = isDecimal 
          ? target.toFixed(1) 
          : Math.round(target).toLocaleString();
      }
    }

    requestAnimationFrame(updateCounter);
  });
}

/**
 * Quick Live Search Filter on Tables
 */
function initTableSearch() {
  const searchInputs = document.querySelectorAll('[data-table-search]');
  searchInputs.forEach((input) => {
    const targetTableId = input.getAttribute('data-table-search');
    const table = document.getElementById(targetTableId);
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');

    input.addEventListener('input', (e) => {
      const query = e.target.value.toLowerCase().trim();
      rows.forEach((row) => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
      });
    });
  });
}

/**
 * Visual Loading Spinners on Refresh/Sync Form Submissions
 */
function initButtonLoadingStates() {
  // Links that trigger refreshes
  const refreshLinks = document.querySelectorAll('.btn-refresh, a[href^="refresh_"]');
  refreshLinks.forEach((link) => {
    link.addEventListener('click', function () {
      if (this.classList.contains('is-loading')) return;
      this.classList.add('is-loading');
      const originalText = this.innerHTML;
      this.innerHTML = 'Syncing...';
    });
  });

  // Forms that trigger syncs
  const syncForms = document.querySelectorAll('form');
  syncForms.forEach((form) => {
    form.addEventListener('submit', function () {
      const btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.classList.contains('is-loading')) {
        btn.classList.add('is-loading');
      }
    });
  });
}
