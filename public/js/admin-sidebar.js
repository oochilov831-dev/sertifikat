(function () {
  const PAGE = location.pathname.replace(/\/+$/, '') || '/admin/index.html';

  const GROUPS = [
    {
      groupKey: "group_main",
      defaultGroup: "Asosiy boshqaruv",
      links: [
        { href: '/admin/index.html', label: '📊 Dashboard', key: 'menu_dashboard' },
        { href: '/admin/users.html', label: '👥 Foydalanuvchilar', key: 'menu_users' },
        { href: '/admin/templates.html', label: '🖼️ Shablonlar', key: 'menu_templates' },
        { href: '/admin/moderation.html', label: '🔍 Moderatsiya', key: 'menu_moderation' }
      ]
    },
    {
      groupKey: "group_fin",
      defaultGroup: "Moliyaviy amallar",
      links: [
        { href: '/admin/payments.html', label: '💳 To\'lovlar', key: 'menu_payments' },
        { href: '/admin/plans.html', label: '⚡ Tarif rejalar', key: 'menu_plans_adm' },
        { href: '/admin/promo.html', label: '🏷️ Promokodlar', key: 'menu_promo' }
      ]
    },
    {
      groupKey: "group_sys",
      defaultGroup: "Tizim va loglar",
      links: [
        { href: '/admin/broadcast.html', label: '📨 Email Broadcast', key: 'menu_broadcast' },
        { href: '/admin/audit.html', label: '📋 Audit log', key: 'menu_audit' },
        { href: '/admin/health.html', label: '⚕️ Server Health', key: 'menu_health' }
      ]
    }
  ];

  const groupTranslations = {
    uz: {
      group_main: "Asosiy boshqaruv",
      group_fin: "Moliyaviy amallar",
      group_sys: "Tizim va loglar"
    },
    ru: {
      group_main: "Основное управление",
      group_fin: "Финансовые операции",
      group_sys: "Система и лог-файлы"
    },
    en: {
      group_main: "Main Management",
      group_fin: "Financial Operations",
      group_sys: "System & Logs"
    }
  };

  function render() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;

    const lang = window.i18n ? window.i18n.currentLang : 'uz';

    let html = '';
    GROUPS.forEach((g, index) => {
      const topPadding = index > 0 ? '20px' : '10px';
      const groupLabel = groupTranslations[lang]?.[g.groupKey] || g.defaultGroup;
      
      html += `<div style="font-size: 11px; font-weight: 700; color: var(--text-soft); text-transform: uppercase; letter-spacing: .08em; padding: ${topPadding} 14px 6px;">${groupLabel}</div>`;
      
      g.links.forEach(link => {
        const isActive = PAGE === link.href;
        const label = window.i18n ? window.i18n.t(link.key) : link.label;
        html += `<a href="${link.href}" class="sidebar-link ${isActive ? 'active' : ''}">${label}</a>`;
      });
    });

    const cabinetText = window.i18n ? window.i18n.t('menu_cabinet') : '← Kabinetga';
    html += `
      <div style="margin-top: auto; border-top: 1px solid var(--border); padding-top: 14px;">
        <a href="/dashboard.html" class="sidebar-link">${cabinetText}</a>
      </div>
    `;

    sidebar.innerHTML = html;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
