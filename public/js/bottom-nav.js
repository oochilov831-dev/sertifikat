(function () {
  const PAGE = location.pathname.replace(/\/+$/, '') || '/';

  // SVG ikonkalar: outline (default) + filled (active)
  // Markazdagi "+" tugma — alohida, action turi
  const ITEMS = [
    {
      href: '/dashboard.html',
      label: 'Kabinet',
      match: ['/dashboard.html', '/'],
      iconOutline: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9"/><path d="M5 10v10a1 1 0 001 1h3v-6h6v6h3a1 1 0 001-1V10"/></svg>`,
      iconFilled:  `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.3 2.5a1 1 0 011.4 0l9 9a1 1 0 01-.7 1.7H20v8a1 1 0 01-1 1h-4v-6H9v6H5a1 1 0 01-1-1v-8H3a1 1 0 01-.7-1.7l9-9z"/></svg>`,
    },
    {
      href: '/templates.html',
      label: 'Shablonlar',
      match: ['/templates.html'],
      iconOutline: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>`,
      iconFilled:  `<svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>`,
    },
    {
      type: 'action',
      href: '/constructor.html',
      label: 'Yaratish',
      match: ['/constructor.html', '/bulk.html'],
      icon: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`,
    },
    {
      href: '/plans.html',
      label: 'Tariflar',
      match: ['/plans.html'],
      iconOutline: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><line x1="2" y1="11" x2="22" y2="11"/><line x1="6" y1="15" x2="9" y2="15"/></svg>`,
      iconFilled:  `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4a2 2 0 00-2 2v3h20V6a2 2 0 00-2-2zM2 11v7a2 2 0 002 2h16a2 2 0 002-2v-7H2zm5 5H5v-2h2v2z"/></svg>`,
    },
    {
      href: '/profile.html',
      label: 'Profil',
      match: ['/profile.html'],
      iconOutline: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`,
      iconFilled:  `<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="8" r="4.5"/><path d="M4.5 21a7.5 7.5 0 0115 0H4.5z"/></svg>`,
    },
  ];

  function buildItem(item, isActive) {
    if (item.type === 'action') {
      const a = document.createElement('a');
      a.href = item.href;
      a.className = 'bn-item bn-action' + (isActive ? ' active' : '');
      a.setAttribute('aria-label', item.label);
      a.innerHTML = `<span class="bn-action-btn">${item.icon}</span>`;
      return a;
    }

    const a = document.createElement('a');
    a.href = item.href;
    a.className = 'bn-item' + (isActive ? ' active' : '');
    a.setAttribute('aria-label', item.label);
    a.innerHTML = `
      <span class="bn-icon bn-icon-outline">${item.iconOutline}</span>
      <span class="bn-icon bn-icon-filled">${item.iconFilled}</span>
      <span class="bn-label">${item.label}</span>
    `;
    return a;
  }

  function isMatch(item) {
    return item.match.some(m => PAGE === m || (m !== '/' && PAGE.startsWith(m)));
  }

  function render() {
    const nav = document.createElement('nav');
    nav.className = 'bottom-nav';
    nav.setAttribute('aria-label', 'Asosiy navigatsiya');

    const inner = document.createElement('div');
    inner.className = 'bottom-nav-inner';

    ITEMS.forEach(item => {
      inner.appendChild(buildItem(item, isMatch(item)));
    });

    nav.appendChild(inner);
    document.body.appendChild(nav);

    attachInteractions(nav);
    attachScrollHide(nav);
  }

  // Ripple + haptic feedback
  function attachInteractions(nav) {
    nav.addEventListener('pointerdown', e => {
      const item = e.target.closest('.bn-item');
      if (!item) return;

      // Haptic
      if ('vibrate' in navigator) navigator.vibrate(8);

      // Ripple
      const rect = item.getBoundingClientRect();
      const ripple = document.createElement('span');
      ripple.className = 'bn-ripple';
      const size = Math.max(rect.width, rect.height) * 1.6;
      ripple.style.width = ripple.style.height = `${size}px`;
      ripple.style.left = `${e.clientX - rect.left - size/2}px`;
      ripple.style.top  = `${e.clientY - rect.top  - size/2}px`;
      item.appendChild(ripple);
      setTimeout(() => ripple.remove(), 600);
    });
  }

  // Scroll-da yashirish
  function attachScrollHide(nav) {
    let lastY = window.scrollY;
    let ticking = false;
    const THRESHOLD = 40;

    window.addEventListener('scroll', () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        const y = window.scrollY;
        const diff = y - lastY;

        if (y < 60) {
          nav.classList.remove('bn-hidden');
        } else if (diff > THRESHOLD) {
          nav.classList.add('bn-hidden');
          lastY = y;
        } else if (diff < -THRESHOLD) {
          nav.classList.remove('bn-hidden');
          lastY = y;
        }
        ticking = false;
      });
    }, { passive: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
