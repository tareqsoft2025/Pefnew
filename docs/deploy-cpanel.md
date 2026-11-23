# النشر على cPanel / استضافة مشتركة

> النشر لـ staging/production **لا يتم تلقائيًا**. هذه الخطوات للتنفيذ اليدوي أو عبر workflow `deploy-staging.yml` بعد موافقتك وتهيئة الأسرار.

## المتطلبات
PHP ‏8.1+ (يُفضّل 8.3) بامتدادات: `pdo_mysql`، `mbstring`، `openssl`، `fileinfo`، `curl`، (`zip` لتصدير XLSX، وإلا يتوفر CSV). MySQL 5.7+/MariaDB 10.4+ (utf8mb4). HTTPS مفعّل (AutoSSL).

## الخطوات
1. **قاعدة البيانات:** من cPanel ▸ MySQL Databases أنشئ قاعدة ومستخدمًا بصلاحيات كاملة عليها.
2. **رفع الملفات:** ارفع محتوى `backend/` إلى مجلد **خارج** `public_html` (مثلًا `~/fleet/`)، ثم اجعل `public_html` (أو النطاق الفرعي) يشير إلى `~/fleet/public` (cPanel ▸ Domains ▸ Document Root). لا تضع `.env` أو `storage/` أو `src/` داخل مجلد الويب.
3. **الاعتماديات:** `composer install --no-dev --optimize-autoloader` على جهازك أو عبر Terminal ثم ارفع `vendor/` (لا حزم وقت تشغيل خارجية حاليًا؛ autoloader فقط).
4. **البيئة:** انسخ `.env.example` إلى `~/fleet/.env` واملأ القيم (ملف خارج الويب، صلاحية 600). اضبط `STORAGE_DIR=/home/USER/fleet-storage` (خارج الويب، صلاحية 750).
5. **Migrations والزرع:** `php bin/migrate.php`
6. **مدير النظام:** `ADMIN_PASSWORD='كلمة-قوية' php bin/create-admin.php admin "الاسم" email@example.com` ثم غيّر كلمة المرور من «حسابي» وفعّل التحقق الثنائي.
7. **Cron (كل دقيقة):** cPanel ▸ Cron Jobs: `* * * * * /usr/local/bin/php /home/USER/fleet/bin/cron.php >/dev/null 2>&1` — مسؤول عن تصعيد SOS وSMS وتنبيهات التوقف والاحتفاظ.
8. **التحقق:** افتح `/login`، وجرّب `GET /api/v1/config` بدون رمز (يجب 401).

## الأمان
- `.htaccess` في `public/` يوجّه الطلبات لـ `index.php` ويمنع عرض المجلدات. تأكد أن `src/` و`vendor/` و`storage/` و`.env` خارج Document Root.
- فعّل HSTS بعد التأكد من HTTPS (الترويسة موجودة في `.htaccess`).
- حدّد `CORS_ORIGINS` فارغًا ما لم تكن هناك واجهة ويب خارجية.
- اجعل مستخدم قاعدة البيانات بلا صلاحية DROP/ALTER بعد الـ migrations إن أمكن.

## التحديثات
ارفع الملفات الجديدة (بدون استبدال `.env`/`storage`) ثم `php bin/migrate.php`. خذ نسخة احتياطية قبل كل تحديث (انظر `backup-restore.md`).
