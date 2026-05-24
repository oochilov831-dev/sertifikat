(function () {
  // Meta taglarni avtomatik qo'shish (har bir sahifaga qo'shish o'rniga)
  function injectMeta() {
    if (!document.querySelector('link[rel="manifest"]')) {
      const m = document.createElement('link');
      m.rel = 'manifest'; m.href = '/manifest.json';
      document.head.appendChild(m);
    }
    if (!document.querySelector('meta[name="theme-color"]')) {
      const t = document.createElement('meta');
      t.name = 'theme-color'; t.content = '#6366f1';
      document.head.appendChild(t);
    }
    if (!document.querySelector('link[rel="apple-touch-icon"]')) {
      const a = document.createElement('link');
      a.rel = 'apple-touch-icon'; a.href = '/icons/apple-touch-icon.png';
      document.head.appendChild(a);
    }
    if (!document.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
      const c = document.createElement('meta');
      c.name = 'apple-mobile-web-app-capable'; c.content = 'yes';
      document.head.appendChild(c);
    }
    if (!document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]')) {
      const s = document.createElement('meta');
      s.name = 'apple-mobile-web-app-status-bar-style'; s.content = 'default';
      document.head.appendChild(s);
    }
  }

  // Service Worker registratsiya
  function registerSW() {
    if (!('serviceWorker' in navigator)) return;
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') return;

    navigator.serviceWorker.register('/service-worker.js')
      .then(reg => {
        reg.addEventListener('updatefound', () => {
          const nw = reg.installing;
          nw?.addEventListener('statechange', () => {
            if (nw.state === 'installed' && navigator.serviceWorker.controller) {
              if (window.Toast) {
                Toast.info('Yangi versiya mavjud. Sahifani yangilang.', { duration: 6000 });
              }
            }
          });
        });
      })
      .catch(err => console.warn('[PWA] SW reg fail:', err));
  }

  // Install prompt
  let deferredPrompt = null;
  let installBtn = null;

  function createInstallBtn() {
    if (installBtn || document.getElementById('pwa-install-btn')) return;
    if (sessionStorage.getItem('pwa-install-dismissed')) return;

    installBtn = document.createElement('button');
    installBtn.id = 'pwa-install-btn';
    installBtn.className = 'pwa-install-btn';
    installBtn.setAttribute('aria-label', "Ilovani o'rnatish");
    installBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/>
        <line x1="12" y1="15" x2="12" y2="3"/>
      </svg>
      <span>Ilovani o'rnatish</span>
      <button class="pwa-install-close" aria-label="Yopish">&times;</button>
    `;

    installBtn.addEventListener('click', async e => {
      if (e.target.classList.contains('pwa-install-close')) {
        installBtn.remove();
        sessionStorage.setItem('pwa-install-dismissed', '1');
        return;
      }
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      if (outcome === 'accepted' && window.Toast) {
        Toast.success("Ilova o'rnatildi!");
      }
      deferredPrompt = null;
      installBtn.remove();
    });

    document.body.appendChild(installBtn);
  }

  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredPrompt = e;
    setTimeout(createInstallBtn, 2000);
  });

  window.addEventListener('appinstalled', () => {
    if (window.Toast) Toast.success("Ilova bosh ekranga qo'shildi!");
    installBtn?.remove();
    deferredPrompt = null;
  });

  // ── Online/offline detector ──
  window.addEventListener('offline', () => {
    if (window.Toast) Toast.warning("Internet aloqasi uzildi. Offline rejim.", { duration: 5000 });
  });
  window.addEventListener('online', () => {
    if (window.Toast) Toast.success('Internet tiklandi!');
  });

  // Init
  injectMeta();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerSW);
  } else {
    registerSW();
  }
})();
