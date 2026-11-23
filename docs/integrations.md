# دليل التكاملات: Firebase وSMS والخرائط والمسارات

كل تكامل خلف واجهة قابلة للاستبدال، والافتراضي «غير مهيأ». **لا تضع أي مفتاح في Git** — استخدم `.env` على الخادم وأسرار GitHub.

## Firebase Cloud Messaging (اختياري)
1. أنشئ مشروعًا في Firebase Console وأضف تطبيق Android بـ `applicationId` = `com.pef.fleet.driver` (و`.debug` للتجريبي).
2. نزّل `google-services.json` → ضعه محليًا في `android/app/` (أو سر `GOOGLE_SERVICES_JSON` في workflow الإصدار).
3. أنشئ Service Account (Firebase ▸ Project settings ▸ Service accounts) ونزّل JSON → ضعه على الخادم خارج الويب، واضبط `FCM_SERVICE_ACCOUNT_JSON=/path/sa.json`.
4. بدون ذلك: `NullPushProvider` والتطبيق يعتمد على polling/مزامنة دورية. Push للتنبيه فقط وليس مصدرًا للحقيقة.

## SMS (تنبيه المهام غير المستلمة)
- المنفّذ: `HttpSmsProvider` (POST JSON `{to, from, message}` برمز Bearer) — **عدّل الفئة لتطابق واجهة مزودك** (Unifonic/Taqnyat/Twilio...). اضبط `SMS_API_URL` و`SMS_API_TOKEN` و`SMS_SENDER`.
- تقارير التسليم: وجّه webhook المزود إلى `POST /api/v1/webhooks/sms-dlr` مع ترويسة `X-Webhook-Secret` = `SMS_WEBHOOK_SECRET`، والجسم `{message_id, status: "delivered"|"failed"}`.
- SOS احتياطي عبر SMS: يرسل التطبيق `SOS|event_id|lat|lon|unix_ts` إلى `SOS_FALLBACK_SMS_NUMBER`، ويحوّله مزودك إلى `POST /api/v1/webhooks/sms-inbound` `{from, text}`. التسليم غير مضمون ويظهر ذلك للسائق.
- الحالات الأربع منفصلة في `sms_messages`؛ رسالة SMS لا تعني قبول المهمة.

## الخرائط
- لوحة الإدارة: Leaflet مستضاف محليًا + بلاطات من `map.tile_url` (الإعدادات). الافتراضي بلاطات OSM العامة **للتجارب فقط** (سياسة الاستخدام تحظر الاستخدام الكثيف).
- Android: osmdroid. الخرائط دون إنترنت تتطلب **تنزيل منطقة مسبقًا** (زر في شاشة الخريطة)، وهو محظور عمدًا على خوادم OSM العامة. اختر مزود بلاطات مرخّصًا (MapTiler/Stadia/خادم خاص) واضبط `map.tile_url`.
- كل ما تحتاجه مني لاحقًا: مزود البلاطات المعتمد ومفتاحه (يوضع كجزء من الرابط في الإعداد، وهو سرّ نسبي — يُفضّل خادم وسيط).

## المسارات
`ROUTING_OSRM_URL` (خادم OSRM خاص يُفضّل؛ الخادم العام للتجارب فقط). بدونه يُقترح خط مستقيم يعدّله المدير يدويًا ثم يعتمده.

## ما أحتاجه منك ومتى
| الخدمة | متى | ما المطلوب |
|---|---|---|
| استضافة staging + قاعدة بيانات | قبل المرحلة 8 | بيانات SSH آمنة كأسرار بيئة `staging` |
| مزود SMS | قبل اختبار المرحلة 6 الميداني | حساب + URL + token + رقم مرسل |
| Firebase | قبل اختبار Push (المرحلة 3–6) | `google-services.json` + Service Account |
| مزود بلاطات/Routing | قبل المرحلة 4 الميدانية | اختيار المزود |
| توقيع APK | قبل الإصدار | keystore (أسرار GitHub) |
