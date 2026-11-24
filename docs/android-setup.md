# إعداد Android Studio وبناء التطبيق

## المتطلبات
Android Studio (Ladybug+)، JDK 17، Android SDK 35، جهاز/محاكي API 26+.

## التشغيل
1. افتح المجلد `android/` في Android Studio وانتظر مزامنة Gradle.
2. شغّل `app` على جهاز. في شاشة الدخول أدخل عنوان الخادم `https://...` (HTTPS فقط؛ يمكن ضبط افتراضي بـ `FLEET_DEFAULT_SERVER_URL` عند البناء).
3. اختبارات المنطق على JVM (لا تتطلب SDK): `cd android && ./gradlew -PcoreOnly=true :core:test`
4. APK تجريبي: `./gradlew :app:assembleDebug` ← `app/build/outputs/apk/debug/`. نفس الأمر ينفذه GitHub Actions ويرفع الـ APK كـ artifact.

## الأذونات وسياسات Android (بصدق)
- **خدمة موقع أمامية** بإشعار دائم (`foregroundServiceType=location`). تبدأ عند قبول المهمة والتطبيق في الواجهة.
- **الموقع الخلفي (`ACCESS_BACKGROUND_LOCATION`)**: مطلوب فقط لاستئناف التتبع بعد إعادة التشغيل؛ يُطلب بشاشة توضيحية منفصلة. دونه قد يمنع Android بدء الخدمة من `BootReceiver`.
- **لا ضمانات:** `Force stop` من المستخدم، توفير البطارية الصارم لدى بعض المصنّعين، أو سحب الإذن يوقف التتبع. التطبيق يعرض تحذيرًا صريحًا عند منع بدء الخدمة، ويشرح في «المساعدة» ما ينبغي فعله. فقدان الإشارة يترك فجوة في المسار ولا يُنشأ موقع وهمي.
- التخزين الحساس: الرموز في `EncryptedSharedPreferences` (Android Keystore). قاعدة Room غير مشفرة افتراضيًا — نموذج التهديد: الهاتف مقفل بالجهاز؛ يمكن إضافة SQLCipher إن اشترطت سياستكم.
- **Firebase (اختياري):** ضع `google-services.json` في `android/app/` (لا يُرفع إلى Git). بدونه يعمل التطبيق بـ polling فقط.
- **توقيع الإنتاج:** عبر workflow `release-apk.yml` وأسرار GitHub (`RELEASE_KEYSTORE_BASE64`، ...). لا keystore في المستودع.

> **ملاحظة صدق:** بيئة التطوير التي كُتب فيها هذا الكود لا تتيح تنزيل Android SDK، لذا بُنيت واختُبرت وحدة `core` فقط محليًا، أما وحدة `app` فتُبنى لأول مرة في CI. توقّع احتمال إصلاحات تجميع بسيطة عند أول تشغيل.
