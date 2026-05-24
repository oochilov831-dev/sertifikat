(function () {
  const PAGE = location.pathname.replace(/\/+$/, '') || '/dashboard.html';

  const LINKS = [
    {
      href: '/dashboard.html',
      key: 'menu_certs',
      match: ['/dashboard.html', '/'],
      icon: `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M3 12h18M3 17h18"/></svg>`
    },
    {
      href: '/constructor.html',
      key: 'menu_create',
      match: ['/constructor.html'],
      icon: `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>`
    },
    {
      href: '/bulk.html',
      key: 'menu_bulk',
      match: ['/bulk.html'],
      icon: `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>`
    },
    {
      href: '/plans.html',
      key: 'menu_plans',
      match: ['/plans.html'],
      icon: `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>`
    },
    {
      href: '/profile.html',
      key: 'menu_profile',
      match: ['/profile.html'],
      icon: `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>`
    }
  ];

  function render() {
    // 1. Auto translate top navbar links
    try {
      const navLinks = document.querySelector('.nav-links');
      if (navLinks && window.i18n) {
        navLinks.querySelectorAll('a').forEach(a => {
          const path = a.getAttribute('href');
          if (path === '/dashboard.html') a.textContent = window.i18n.t('menu_certs');
          else if (path === '/constructor.html') a.textContent = window.i18n.t('menu_create');
          else if (path === '/plans.html') a.textContent = window.i18n.t('menu_plans');
          else if (path === '/profile.html') a.textContent = window.i18n.t('menu_profile');
          else if (path === '/templates.html') a.textContent = window.i18n.t('menu_templates');
        });
        
        const logoutBtn = navLinks.querySelector('button[onclick="logout()"]');
        if (logoutBtn) logoutBtn.textContent = window.i18n.t('menu_logout');
      }
    } catch (_) {}

    // 2. Render sidebar
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
      let html = '';
      LINKS.forEach(link => {
        const isActive = link.match.some(m => PAGE === m || (m !== '/' && PAGE.startsWith(m)));
        const label = window.i18n ? window.i18n.t(link.key) : link.key;
        html += `
          <a href="${link.href}" class="sidebar-link ${isActive ? 'active' : ''}">
            ${link.icon}
            ${label}
          </a>
        `;
      });

      try {
        const user = JSON.parse(localStorage.getItem('user') || '{}');
        if (['admin', 'super_admin'].includes(user.role)) {
          const adminText = window.i18n ? window.i18n.t('menu_admin') : 'Admin Panel';
          html += `
            <div style="margin-top: auto; border-top: 1px solid var(--border); padding-top: 14px;">
              <a href="/admin/index.html" class="sidebar-link" style="color: var(--primary); font-weight: 700;">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--primary);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-5M20 12a8 8 0 11-16 0 8 8 0 0116 0z"/></svg>
                ${adminText}
              </a>
            </div>
          `;
        }
      } catch (_) {}

      sidebar.innerHTML = html;
    }

    // 3. Dynamic Top Navbar return button injection
    try {
      const user = JSON.parse(localStorage.getItem('user') || '{}');
      if (['admin', 'super_admin'].includes(user.role)) {
        const navLinks = document.querySelector('.nav-links');
        if (navLinks) {
          if (!navLinks.querySelector('.admin-nav-link')) {
            const adminLink = document.createElement('a');
            adminLink.href = '/admin/index.html';
            adminLink.className = 'nav-link admin-nav-link';
            adminLink.style.color = 'var(--primary)';
            adminLink.style.fontWeight = '700';
            adminLink.style.display = 'inline-flex';
            adminLink.style.alignItems = 'center';
            adminLink.style.gap = '4px';
            adminLink.innerHTML = '🛡️ Admin';
            
            const logoutBtn = navLinks.querySelector('button[onclick="logout()"]');
            if (logoutBtn) {
              navLinks.insertBefore(adminLink, logoutBtn);
            } else {
              navLinks.appendChild(adminLink);
            }
          }
        }
      }
    } catch (_) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
