/**
 * Sertifikat Tizimi — Service Worker
 * Cache strategiyalari:
 *  - Static assets: cache-first
 *  - HTML sahifalar: network-first, offline da cache
 *  - API: network-only (always fresh)
 */

const CACHE_VERSION = 'v4';
const STATIC_CACHE  = `static-${CACHE_VERSION}`;
const PAGES_CACHE   = `pages-${CACHE_VERSION}`;
const RUNTIME_CACHE = `runtime-${CACHE_VERSION}`;

const PRECACHE_URLS = [
  '/css/main.css',
  '/js/api.js',
  '/js/i18n.js',
  '/js/bottom-nav.js',
  '/js/toast.js',
  '/js/pwa.js',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/manifest.json',
];

// Install: pre-cache assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
      .catch(err => console.warn('[SW] precache fail:', err))
  );
});

// Activate: clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys
        .filter(k => ![STATIC_CACHE, PAGES_CACHE, RUNTIME_CACHE].includes(k))
        .map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

// Fetch: routing strategy
self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // API — never cache
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/verify/')) {
    return;
  }

  // HTML — network-first
  if (req.mode === 'navigate' || req.destination === 'document') {
    event.respondWith(networkFirst(req));
    return;
  }

  // Static assets — cache-first
  if (req.destination === 'style' || req.destination === 'script' ||
      req.destination === 'image' || req.destination === 'font') {
    event.respondWith(cacheFirst(req));
    return;
  }

  // Default: network with cache fallback
  event.respondWith(
    fetch(req).catch(() => caches.match(req))
  );
});

async function networkFirst(req) {
  try {
    const res = await fetch(req);
    const cache = await caches.open(PAGES_CACHE);
    cache.put(req, res.clone());
    return res;
  } catch {
    const cached = await caches.match(req);
    if (cached) return cached;
    // Fallback offline page
    return new Response(
      '<!DOCTYPE html><html lang="uz"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Internet yo\'q</title>' +
      '<style>body{font-family:system-ui,sans-serif;padding:40px 20px;text-align:center;color:#334155;background:#f5f7fb;}h1{font-size:24px;margin:16px 0 8px;}p{color:#64748b;}a{color:#6366f1;text-decoration:none;font-weight:600;margin-top:20px;display:inline-block;}</style>' +
      '</head><body><div style="font-size:64px;">📡</div><h1>Internet aloqasi yo\'q</h1><p>Sertifikat Tizimi internetga ulanib ishlaydi.</p><a href="/" onclick="location.reload();return false;">Qayta urinish</a></body></html>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }
}

async function cacheFirst(req) {
  const cached = await caches.match(req);
  if (cached) return cached;
  try {
    const res = await fetch(req);
    const cache = await caches.open(RUNTIME_CACHE);
    cache.put(req, res.clone());
    return res;
  } catch (err) {
    return new Response('', { status: 503 });
  }
}

// Push notification (kelajak uchun)
self.addEventListener('push', event => {
  if (!event.data) return;
  const data = event.data.json();
  event.waitUntil(
    self.registration.showNotification(data.title || 'Sertifikat Tizimi', {
      body: data.body || '',
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      data: { url: data.url || '/dashboard.html' },
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(
    clients.openWindow(event.notification.data?.url || '/dashboard.html')
  );
});
