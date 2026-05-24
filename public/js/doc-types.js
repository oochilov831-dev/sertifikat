// Hujjat turlari — global helper
window.DOC_TYPES = {
  certificate:  { name: 'Sertifikat',        icon: '🎓', bg: '#eef2ff', fg: '#4338ca' },
  diploma:      { name: 'Diplom',            icon: '📜', bg: '#fef3c7', fg: '#92400e' },
  gratitude:    { name: 'Tashakkurnoma',     icon: '🤝', bg: '#dcfce7', fg: '#15803d' },
  honor:        { name: 'Faxriy yorliq',     icon: '🏅', bg: '#fee2e2', fg: '#991b1b' },
  commendation: { name: "Maqtov yorlig'i",   icon: '🌟', bg: '#fef9c3', fg: '#854d0e' },
};

window.docTypeBadge = function (type) {
  const t = window.DOC_TYPES[type] || window.DOC_TYPES.certificate;
  return `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;background:${t.bg};color:${t.fg};">${t.icon} ${t.name}</span>`;
};

window.docTypeName = function (type) {
  return (window.DOC_TYPES[type] || window.DOC_TYPES.certificate).name;
};
