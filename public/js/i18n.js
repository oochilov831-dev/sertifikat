(function () {
  const LANG_KEY = 'sertifikat_lang';
  const currentLang = localStorage.getItem(LANG_KEY) || 'uz';

  const TRANSLATIONS = {
    uz: {
      // Sidebar and Navbar
      menu_certs: "Sertifikatlar",
      menu_create: "Yangi yaratish",
      menu_bulk: "Ommaviy yuklash",
      menu_plans: "Tarif va To'lov",
      menu_profile: "Profil",
      menu_logout: "Chiqish",
      menu_admin: "🛡️ Admin Panel",
      menu_dashboard: "📊 Dashboard",
      menu_users: "👥 Foydalanuvchilar",
      menu_templates: "🖼️ Shablonlar",
      menu_moderation: "🔍 Moderatsiya",
      menu_payments: "💳 To'lovlar",
      menu_plans_adm: "⚡ Tarif rejalar",
      menu_promo: "🏷️ Promokodlar",
      menu_broadcast: "📨 Email Broadcast",
      menu_audit: "📋 Audit log",
      menu_health: "⚕️ Server Health",
      menu_cabinet: "← Kabinetga",

      // Dashboard View
      dash_title: "Hujjatlar paneli",
      dash_sub: "Sertifikat, diplom va yorliqlarni boshqarish",
      dash_quick_new: "Yangi hujjat",
      dash_quick_import: "Ommaviy import",
      dash_quick_verify: "Tekshirish",
      dash_my_docs: "Mening Hujjatlarim",
      dash_all_types: "Barcha turlari",
      dash_search_placeholder: "Qidirish...",
      dash_btn_new: "+ Yangi",
      dash_stat_total: "Jami sertifikatlar",
      dash_stat_left: "Qolgan limit",
      dash_stat_used: "Ishlatilgan limit",
      dash_stat_plan: "Tarif rejasi",
      dash_limit_created: "Yaratilgan:",
      dash_limit_left: "Qolgan limit:",
      dash_limit_exp: "Tarif tugash muddati:",
      dash_empty_title: "Hozircha hech qanday hujjat yaratilmagan",
      dash_empty_sub: "Ilk professional sertifikatingizni yaratish uchun quyidagi tugmani bosing",
      dash_empty_btn: "+ Yangi sertifikat yaratish",

      // Plans View
      plans_title: "Tarif Rejalari",
      plans_sub: "Professional sertifikatlar uchun eng qulay narxlar",
      plans_history: "To'lovlar tarixi",
      plans_table_plan: "Tarif",
      plans_table_amount: "Summa",
      plans_table_provider: "To'lov tizimi",
      plans_table_status: "Holat",
      plans_table_date: "Sana",
      plans_status_pending: "Kutilmoqda",
      plans_status_success: "Muvaffaqiyatli",
      plans_status_failed: "Bekor qilingan",
      plans_btn_current: "✅ Joriy tarif",
      plans_btn_choose_free: "Bepulni tanlash",
      plans_btn_subscribe: "Obuna bo'lish",
      plans_badge_pop: "Tavsiya etiladi",
      plans_modal_title: "To'lov",
      plans_modal_provider: "To'lov tizimi",
      plans_modal_months: "Muddat",
      plans_modal_total: "To'lanadigan summa",
      plans_modal_btn: "Oflayn to'lov arizasini yaratish",

      // Constructor View
      const_title: "Sertifikat Studiyasi",
      const_tabs_templates: "🖼️ Shablonlar",
      const_tabs_fields: "⚙️ Maydonlar",
      const_tabs_data: "📝 Ma'lumot",
      const_tools_text: "Matn",
      const_tools_image: "Rasm",
      const_tools_qr: "QR Kod",
      const_tools_logo: "Logo",
      const_tools_seal: "Muhr",
      const_tools_sig: "Imzo",
      const_layers_title: "Mavjud Qatlamlar",
      const_btn_save: "💾 Saqlash",
      const_btn_load: "📂 Yuklash",
      const_btn_generate: "⚡ Yaratish",
      const_no_template: "🚫 Shablon yo'q",
      const_no_selection_title: "Tahrirlash uchun ekrandan biror maydonni tanlang",
      const_props_title: "Xususiyatlar",
      const_props_text: "Matn kontenti",
      const_props_size: "Shrift o'lchami",
      const_props_color: "Matn rangi",
      const_props_weight: "Matn qalinligi",
      const_props_font: "Shrift oilasi (Font Family)",
      const_props_align: "Matn hizalanishi",
      const_props_width: "Maydon kengligi (Eni - px)",
      const_props_var: "Dinamik o'zgaruvchi",
      const_props_del: "🗑️ Maydonni o'chirish",

      // Profile View
      prof_title: "Profil sozlamalari",
      prof_sub: "Tashkilot ma'lumotlari, brending va xavfsizlik sozlamalari",
      prof_tab_org: "🏢 Tashkilot & Brending",
      prof_tab_sec: "🔒 Xavfsizlik (2FA & API)",
      prof_btn_save: "Saqlash",
      prof_org_name: "Tashkilot nomi",
      prof_org_comp: "Kompaniya yoki shaxs",
      prof_org_phone: "Telefon raqami",
      prof_branding_logo: "Tashkilot logotipi (Logo)",
      prof_branding_sig: "Direktor imzosi (Signature)",
      prof_branding_seal: "Tashkilot muhri (Seal)",
      prof_api_title: "API integratsiyasi",
      prof_api_btn: "Yangi API kalit yaratish"
    },
    ru: {
      // Sidebar and Navbar
      menu_certs: "Сертификаты",
      menu_create: "Создать новый",
      menu_bulk: "Массовая загрузка",
      menu_plans: "Тарифы и Оплата",
      menu_profile: "Профиль",
      menu_logout: "Выйти",
      menu_admin: "🛡️ Админ Панель",
      menu_dashboard: "📊 Панель",
      menu_users: "👥 Пользователи",
      menu_templates: "🖼️ Шаблоны",
      menu_moderation: "🔍 Модерация",
      menu_payments: "💳 Платежи",
      menu_plans_adm: "⚡ Тарифные планы",
      menu_promo: "🏷️ Промокоды",
      menu_broadcast: "📨 Рассылка",
      menu_audit: "📋 Лог аудита",
      menu_health: "⚕️ Состояние",
      menu_cabinet: "← В Кабинет",

      // Dashboard View
      dash_title: "Панель управления",
      dash_sub: "Управление сертификатами, дипломами и грамотами",
      dash_quick_new: "Новый документ",
      dash_quick_import: "Массовый импорт",
      dash_quick_verify: "Проверка",
      dash_my_docs: "Мои Документы",
      dash_all_types: "Все типы",
      dash_search_placeholder: "Поиск...",
      dash_btn_new: "+ Новый",
      dash_stat_total: "Всего сертификатов",
      dash_stat_left: "Оставшийся лимит",
      dash_stat_used: "Использованный лимит",
      dash_stat_plan: "Тарифный план",
      dash_limit_created: "Создано:",
      dash_limit_left: "Осталось:",
      dash_limit_exp: "Срок действия тарифа:",
      dash_empty_title: "Документы пока не созданы",
      dash_empty_sub: "Нажмите кнопку ниже, чтобы создать свой первый профессиональный сертификат",
      dash_empty_btn: "+ Создать сертификат",

      // Plans View
      plans_title: "Тарифные Планы",
      plans_sub: "Самые выгодные цены для профессиональных сертификатов",
      plans_history: "История платежей",
      plans_table_plan: "Тариф",
      plans_table_amount: "Сумма",
      plans_table_provider: "Платежная система",
      plans_table_status: "Статус",
      plans_table_date: "Дата",
      plans_status_pending: "В ожидании",
      plans_status_success: "Успешно",
      plans_status_failed: "Отменено",
      plans_btn_current: "✅ Текущий тариф",
      plans_btn_choose_free: "Выбрать бесплатный",
      plans_btn_subscribe: "Подписаться",
      plans_badge_pop: "Рекомендуется",
      plans_modal_title: "Оплата",
      plans_modal_provider: "Способ оплаты",
      plans_modal_months: "Срок",
      plans_modal_total: "Сумма к оплате",
      plans_modal_btn: "Создать заявку на оффлайн оплату",

      // Constructor View
      const_title: "Студия Сертификатов",
      const_tabs_templates: "🖼️ Шаблоны",
      const_tabs_fields: "⚙️ Поля",
      const_tabs_data: "📝 Данные",
      const_tools_text: "Текст",
      const_tools_image: "Изображение",
      const_tools_qr: "QR Код",
      const_tools_logo: "Логотип",
      const_tools_seal: "Печать",
      const_tools_sig: "Подпись",
      const_layers_title: "Доступные слои",
      const_btn_save: "💾 Сохранить",
      const_btn_load: "📂 Загрузить",
      const_btn_generate: "⚡ Создать",
      const_no_template: "🚫 Без шаблона",
      const_no_selection_title: "Выберите любой элемент на экране для редактирования",
      const_props_title: "Свойства",
      const_props_text: "Содержимое текста",
      const_props_size: "Размер шрифта",
      const_props_color: "Цвет текста",
      const_props_weight: "Толщина текста",
      const_props_font: "Семейство шрифтов",
      const_props_align: "Выравнивание текста",
      const_props_width: "Ширина поля (px)",
      const_props_var: "Динамическая переменная",
      const_props_del: "🗑️ Удалить поле",

      // Profile View
      prof_title: "Настройки профиля",
      prof_sub: "Данные организации, брендинг и настройки безопасности",
      prof_tab_org: "🏢 Организация & Брендинг",
      prof_tab_sec: "🔒 Безопасность (2FA & API)",
      prof_btn_save: "Сохранить",
      prof_org_name: "Название организации",
      prof_org_comp: "Компания или лицо",
      prof_org_phone: "Номер телефона",
      prof_branding_logo: "Логотип организации",
      prof_branding_sig: "Подпись директора",
      prof_branding_seal: "Печать организации",
      prof_api_title: "Интеграция по API",
      prof_api_btn: "Создать новый API ключ"
    },
    en: {
      // Sidebar and Navbar
      menu_certs: "Certificates",
      menu_create: "Create New",
      menu_bulk: "Bulk Upload",
      menu_plans: "Plans & Payment",
      menu_profile: "Profile",
      menu_logout: "Log Out",
      menu_admin: "🛡️ Admin Panel",
      menu_dashboard: "📊 Dashboard",
      menu_users: "👥 Users",
      menu_templates: "🖼️ Templates",
      menu_moderation: "🔍 Moderation",
      menu_payments: "💳 Payments",
      menu_plans_adm: "⚡ Subscription Plans",
      menu_promo: "🏷️ Promo Codes",
      menu_broadcast: "📨 Broadcast",
      menu_audit: "📋 Audit Log",
      menu_health: "⚕️ Server Health",
      menu_cabinet: "← To Cabinet",

      // Dashboard View
      dash_title: "Documents Dashboard",
      dash_sub: "Manage certificates, diplomas and awards",
      dash_quick_new: "New document",
      dash_quick_import: "Bulk import",
      dash_quick_verify: "Verification",
      dash_my_docs: "My Documents",
      dash_all_types: "All types",
      dash_search_placeholder: "Search...",
      dash_btn_new: "+ New",
      dash_stat_total: "Total Certificates",
      dash_stat_left: "Remaining Limit",
      dash_stat_used: "Used Limit",
      dash_stat_plan: "Subscription Plan",
      dash_limit_created: "Created:",
      dash_limit_left: "Limit Left:",
      dash_limit_exp: "Plan Expiry Date:",
      dash_empty_title: "No documents created yet",
      dash_empty_sub: "Click the button below to create your first professional certificate",
      dash_empty_btn: "+ Create certificate",

      // Plans View
      plans_title: "Subscription Plans",
      plans_sub: "The best prices for professional certificates",
      plans_history: "Payment History",
      plans_table_plan: "Plan",
      plans_table_amount: "Amount",
      plans_table_provider: "Payment Provider",
      plans_table_status: "Status",
      plans_table_date: "Date",
      plans_status_pending: "Pending",
      plans_status_success: "Success",
      plans_status_failed: "Cancelled",
      plans_btn_current: "✅ Current Plan",
      plans_btn_choose_free: "Select Free",
      plans_btn_subscribe: "Subscribe",
      plans_badge_pop: "Recommended",
      plans_modal_title: "Payment",
      plans_modal_provider: "Payment Provider",
      plans_modal_months: "Duration",
      plans_modal_total: "Total to Pay",
      plans_modal_btn: "Create Offline Payment Request",

      // Constructor View
      const_title: "Certificate Studio",
      const_tabs_templates: "🖼️ Templates",
      const_tabs_fields: "⚙️ Fields",
      const_tabs_data: "📝 Data",
      const_tools_text: "Text",
      const_tools_image: "Image",
      const_tools_qr: "QR Code",
      const_tools_logo: "Logo",
      const_tools_seal: "Seal",
      const_tools_sig: "Signature",
      const_layers_title: "Available Layers",
      const_btn_save: "💾 Save",
      const_btn_load: "📂 Load",
      const_btn_generate: "⚡ Create",
      const_no_template: "🚫 No template",
      const_no_selection_title: "Select any element on the screen to edit properties",
      const_props_title: "Properties",
      const_props_text: "Text content",
      const_props_size: "Font size",
      const_props_color: "Text color",
      const_props_weight: "Font weight",
      const_props_font: "Font family",
      const_props_align: "Text alignment",
      const_props_width: "Field width (px)",
      const_props_var: "Dynamic variable",
      const_props_del: "🗑️ Delete field",

      // Profile View
      prof_title: "Profile Settings",
      prof_sub: "Organization data, branding and security configurations",
      prof_tab_org: "🏢 Organization & Branding",
      prof_tab_sec: "🔒 Security (2FA & API)",
      prof_btn_save: "Save changes",
      prof_org_name: "Organization name",
      prof_org_comp: "Company or name",
      prof_org_phone: "Phone number",
      prof_branding_logo: "Organization logo",
      prof_branding_sig: "Director's signature",
      prof_branding_seal: "Organization seal",
      prof_api_title: "API Integration",
      prof_api_btn: "Generate new API key"
    }
  };

  function t(key) {
    return TRANSLATIONS[currentLang][key] || TRANSLATIONS['uz'][key] || key;
  }

  function setLanguage(lang) {
    if (TRANSLATIONS[lang]) {
      localStorage.setItem(LANG_KEY, lang);
      location.reload();
    }
  }

  function translateDOM() {
    document.querySelectorAll('[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');
      const text = t(key);
      if (text) {
        if (el.children.length === 0) {
          el.textContent = text;
        } else {
          let textNode = Array.from(el.childNodes).find(n => n.nodeType === Node.TEXT_NODE);
          if (textNode) {
            textNode.textContent = text;
          }
        }
      }
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
      const key = el.getAttribute('data-i18n-placeholder');
      const text = t(key);
      if (text) el.placeholder = text;
    });
  }

  function injectSelector() {
    const navLinks = document.querySelector('.nav-links');
    if (navLinks) {
      if (!navLinks.querySelector('.lang-selector-wrap')) {
        const wrap = document.createElement('div');
        wrap.className = 'lang-selector-wrap';
        wrap.style.cssText = 'position: relative; display: inline-flex; align-items: center; margin-right: 12px;';
        wrap.innerHTML = `
          <select id="lang-select" style="
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            padding: 6px 10px;
            cursor: pointer;
            outline: none;
            transition: all 0.2s;
          ">
            <option value="uz">UZ</option>
            <option value="ru">RU</option>
            <option value="en">EN</option>
          </select>
        `;
        navLinks.insertBefore(wrap, navLinks.firstChild);
        const select = wrap.querySelector('#lang-select');
        select.value = currentLang;
        select.addEventListener('change', (e) => setLanguage(e.target.value));
      }
    }

    // Constructor injection
    const topbarRight = document.querySelector('.topbar-right .toolbar-row');
    if (topbarRight) {
      if (!topbarRight.querySelector('.lang-selector-wrap')) {
        const wrap = document.createElement('div');
        wrap.className = 'lang-selector-wrap';
        wrap.innerHTML = `
          <select id="lang-select-constructor" style="
            background: rgba(17, 23, 41, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            color: #cbd5e1;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 8px;
            cursor: pointer;
            outline: none;
          ">
            <option value="uz">UZ</option>
            <option value="ru">RU</option>
            <option value="en">EN</option>
          </select>
        `;
        topbarRight.insertBefore(wrap, topbarRight.firstChild);
        const select = wrap.querySelector('#lang-select-constructor');
        select.value = currentLang;
        select.addEventListener('change', (e) => setLanguage(e.target.value));
      }
    }
  }

  // Global exposure
  window.i18n = {
    t,
    setLanguage,
    currentLang,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      translateDOM();
      injectSelector();
    });
  } else {
    translateDOM();
    injectSelector();
  }
})();
