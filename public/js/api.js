// Auto-sync token/user from cross-subdomain cookies if available
(function syncCookiesToLocalStorage() {
  const getCookie = (name) => {
    const matches = document.cookie.match(new RegExp(
      "(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
    ));
    return matches ? decodeURIComponent(matches[1]) : undefined;
  };

  const googleToken = getCookie('google_token');
  const googleUser = getCookie('google_user');

  if (googleToken && googleUser) {
    localStorage.setItem('token', googleToken);
    localStorage.setItem('user', googleUser);
    
    // Clear cookies to avoid re-syncing or polluting cookies
    const domain = window.location.hostname.endsWith('sertifikat-qr.uz') ? '.sertifikat-qr.uz' : '';
    document.cookie = "google_token=; path=/; max-age=0;" + (domain ? " domain=" + domain + ";" : "");
    document.cookie = "google_user=; path=/; max-age=0;" + (domain ? " domain=" + domain + ";" : "");
    document.cookie = "google_token=; path=/; max-age=0;";
    document.cookie = "google_user=; path=/; max-age=0;";
  }
})();

const API_BASE = '/api';

const Api = {
  token: () => localStorage.getItem('token'),

  async request(method, path, data = null, isForm = false) {
    const headers = {};
    const token = this.token();
    if (token) headers.Authorization = `Bearer ${token}`;
    if (!isForm) headers['Content-Type'] = 'application/json';

    const opts = { method, headers, credentials: 'same-origin' };
    if (data) opts.body = isForm ? data : JSON.stringify(data);

    let res;
    try {
      res = await fetch(API_BASE + path, opts);
    } catch (networkError) {
      throw new Error("Server bilan aloqa uzildi. Lokal server ishlayotganini tekshiring.");
    }

    const text = await res.text();
    let json = null;
    if (text) {
      try {
        json = JSON.parse(text);
      } catch (_) {
        json = null;
      }
    }

    if (!res.ok) {
      const err = new Error(json?.message || text?.slice(0, 180) || 'Xato yuz berdi');
      err.status = res.status;
      err.errors = json?.errors;
      throw err;
    }
    return json && Object.prototype.hasOwnProperty.call(json, 'data') ? json.data : json;
  },

  get:    (path)         => Api.request('GET',    path),
  post:   (path, data)   => Api.request('POST',   path, data),
  put:    (path, data)   => Api.request('PUT',    path, data),
  delete: (path)         => Api.request('DELETE', path),
  upload: (path, form)   => Api.request('POST',   path, form, true),

  // Auth
  auth: {
    login:    (d)  => Api.post('/auth/login', d),
    register: (d)  => Api.post('/auth/register', d),
    me:       ()   => Api.get('/auth/me'),
    profile:  (d)  => Api.put('/auth/profile', d),
    password: (d)  => Api.put('/auth/change-password', d),
    forgot:   (d)  => Api.post('/auth/forgot-password', d),
    reset:    (d)  => Api.post('/auth/reset-password', d),
  },

  // Sertifikatlar
  certs: {
    list:    (page, search, docType) => Api.get(`/certificates?page=${page}&search=${encodeURIComponent(search || '')}${docType ? '&doc_type=' + encodeURIComponent(docType) : ''}`),
    get:     (id)   => Api.get(`/certificates/${id}`),
    create:  (d)    => Api.post('/certificates', d),
    delete:  (id)   => Api.delete(`/certificates/${id}`),
    bulk:    (form) => Api.upload('/certificates/bulk', form),
    bulkJob: (id)   => Api.get(`/certificates/bulk-jobs/${id}`),
    verify:  (id)   => fetch(`/verify/${id}`).then(r => r.json()),
    templates: ()   => Api.get('/templates'),
  },

  layout: {
    saveLayout: (d) => Api.post('/constructor/layout', d),
    loadLayout: ()  => Api.get('/constructor/layout'),
  },

  // To'lovlar
  payments: {
    plans:    ()  => fetch('/api/plans').then(r => r.json()).then(d => d.data),
    initiate: (d) => Api.post('/payments/initiate', d),
    history:  (p) => Api.get(`/payments/history?page=${p}`),
    free:     ()  => Api.post('/payments/free-plan'),
  },

  // Admin
  admin: {
    stats:    ()      => Api.get('/admin/stats'),
    users:    (p, q)  => Api.get(`/admin/users?page=${p}&search=${encodeURIComponent(q || '')}`),
    block:    (id)    => Api.put(`/admin/users/${id}/block`),
    sub:      (id, d) => Api.put(`/admin/users/${id}/subscription`, d),
    payments: (p)     => Api.get(`/admin/payments?page=${p}`),
    plans:    ()      => Api.get('/admin/plans'),
    updatePlan: (plan, d) => Api.put(`/admin/plans/${plan}`, d),
    templates: ()     => Api.get('/admin/templates'),
    delTemplate: (id) => Api.delete(`/admin/templates/${id}`),
    addTemplate: (f)  => Api.upload('/admin/templates', f),
  },
};

// Auth guard
function requireAuth() {
  if (!localStorage.getItem('token')) {
    window.location.href = '/login.html';
    return false;
  }
  return true;
}

function requireGuest() {
  if (localStorage.getItem('token')) {
    window.location.href = '/dashboard.html';
    return false;
  }
  return true;
}

function logout() {
  localStorage.removeItem('token');
  localStorage.removeItem('user');
  window.location.href = '/login.html';
}

// Toast notifications
function toast(msg, type = 'success') {
  const t = document.createElement('div');
  t.className = `alert alert-${type === 'error' ? 'danger' : type}`;
  t.style.cssText = `
    position:fixed; top:20px; right:20px; z-index:9999;
    min-width:280px; animation:slideIn .3s ease;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,.1);
  `;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

// Format helpers
const fmt = {
  money: (n) => new Intl.NumberFormat('uz-UZ').format(n) + ' so\'m',
  date:  (d) => new Date(d).toLocaleDateString('uz-UZ'),
  plan:  (p) => ({ free: 'Bepul', standard: 'Standart', pro: 'Pro' }[p] || p),
};
