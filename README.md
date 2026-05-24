# 🎓 Professional Sertifikat Berish Tizimi

PHP 8.1 + PostgreSQL asosida qurilgan professional sertifikat yaratish va ommaviy generatsiya platformasi.

---

## Ishga Tushirish va O'rnatish

### 1. Tizim Talablari
- **PHP** >= 8.1 (GD, PDO_PGSQL, MBSTRING va XML kutubxonalari bilan)
- **PostgreSQL** >= 13
- **Composer** (Paketlarni boshqarish uchun)

### 2. Loyihani O'rnatish

```bash
# Loyihani klonlash yoki yuklab olish
# Bog'liqliklarni o'rnatish
composer install

# Muhit sozlamalarini yaratish
cp .env.example .env
# .env faylini ochib, DB, JWT va SMTP ma'lumotlarini sozlang
```

### 3. Ma'lumotlar Bazasini Sozlash va Migratsiya

```bash
# PostgreSQL da bo'sh ma'lumotlar bazasini yarating
createdb sertifikat_db

# Baza schemasini import qiling
psql -d sertifikat_db -f database/schema.sql

# Migratsiyalarni ishga tushirish (xavfsiz runtime'dan tashqari CLI)
php database/migrate.php
```

### 4. Mahalliy Serverni Ishga Tushirish

```bash
# Mahalliy built-in serverni ishga tushiring
composer start
```
Tizimga kirish: `http://localhost:8000`

---

## Loyiha Sahifalari Tuzilmasi

| Sahifa | URL | Tavsif |
| :--- | :--- | :--- |
| **Kirish va Registratsiya** | `/login.html` | Xavfsiz login/register va 2FA |
| **Shaxsiy Kabinet** | `/dashboard.html` | Yaratilgan sertifikatlar va statistika |
| **Sertifikat Konstruktori** | `/constructor.html` | Vizual drag-and-drop muharrir va shablonlar |
| **Ommaviy Generatsiya** | `/bulk.html` | CSV orqali minglab sertifikatlarni ommaviy yaratish |
| **Tarif Rejalari** | `/plans.html` | Obuna xizmatlari va Click/Payme to'lovlari |
| **Sertifikat Tekshiruvi** | `/verify.html` | QR-kod yoki ID orqali ommaviy tekshirish |
| **Admin Panel** | `/admin/index.html` | Foydalanuvchilar, to'lovlar va audit jurnallari boshqaruvi |

---

## 🔒 Production Deployment Checklist

Loyiha mahsulot (production) muhitiga joylashtirilganda, xavfsizlik va barqarorlikni ta'minlash uchun quyidagi ko'rsatmalarga qat'iy amal qiling:

### 1. Muhit Sozlamalari (`.env`)
- `APP_ENV` qiymatini `production` qilib belgilang.
- `APP_DEBUG` qiymatini `false` ga o'zgartiring.
- `OFFLINE_MODE` qiymatini `false` qilib SMTP orqali real xat yuborishni yoqing.

### 2. Xavfsiz Kalitlar (JWT va To'lovlar)
- `JWT_SECRET` kalitini default qiymatda qoldirmang! Kamida 32 ta tasodifiy belgidan iborat kuchli kalit generatsiya qilib o'rnating.
- Click, Payme va Uzum to'lov tizimlari uchun maxfiy tokenlarni faqat `.env` da saqlang.

### 3. Xizmat va Sozlash Skriptlari (Xavfsizlik Qulfi)
- `public/install.php` va `public/fix_admin.php` fayllari avtomatik ravishda tashqi foydalanuvchilar va production muhitida kirishni cheklaydi (403 Forbidden). Sozlash tugagandan so'ng ularni serverdan butunlay o'chirib tashlash tavsiya etiladi.

### 4. Background Workers (Ommaviy Generatsiya)
- Ommaviy CSV yuklashlarni fonda qayta ishlash uchun serverda cron worker ishga tushiring:
  ```bash
  * * * * * php /var/www/sertifikat/cron/worker.php >> /var/log/sertifikat-worker.log 2>&1
  ```

### 5. HTTPS va Xavfsizlik Siyosatlari
- Saytni faqat **HTTPS** protokoli orqali ishga tushiring.
- Nginx/Apache sozlamalarida CORS va xavfsiz headerlarni faollashtiring (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`).

### 6. Doimiy Zaxiralash (Database Backups)
- PostgreSQL ma'lumotlar bazasini doimiy (masalan, har kuni kechasi) avtomatik zaxiralash (pg_dump) xizmatini yoqing.
