# المعمارية

```
 [Android Driver App]                       [Admin Web (PHP views)]
  Room ⇄ outbox ⇄ SyncWorker                  Leaflet + fetch (10s)
  TrackingService (FGS) ─ GPS ─▶ Room              │ session+CSRF
        │  HTTPS (Bearer)                          ▼
        └──────────────▶  /api/v1  ◀───────────────┘
                     Kernel (auth, CSRF, CORS, errors)
                     ApiController / WebController
                          │ Services (Authz في كل منها)
   Mission • Trip • TripAnalyzer • Odometer • Emergency • Sync • Sms • Attachment • Report • Query
                          │
                    MySQL (InnoDB) + storage/ (صور خارج الويب)
   cron (كل دقيقة): تصعيد SOS • SMS • تنبيهات التوقف • القراءات المعلقة • الاحتفاظ
   مزودون: Push (Null|FCM) • SMS (Null|Http) • Routing (Straight|OSRM)
```

## تدفق قبول مهمة (offline-first)
1. المدير ينشئ المهمة ويسند سائقًا ومركبة (تحقق تعارض) ويرسلها ⇒ `sent` + إشعار.
2. التطبيق يسحب المهام ويرسل `mission.delivered` ⇒ `delivered` (وهنا فقط يُعد «وصلت للتطبيق»؛ SMS لا تُغيّر الحالة).
3. السائق يقبل ⇒ **محليًا فورًا**: رحلة جديدة + بدء GPS + حدث `mission.accept` في outbox (الواجهة تعرض «بانتظار تأكيد الخادم»).
4. المزامنة: الخادم يطبق القبول، يربط المهمة بالرحلة المفتوحة، يفتح مرحلة المركبة (عداد معلق)، يحوّل المهمة `accepted→active`.
5. نقاط GPS تُرفع دفعات؛ بعد كل دفعة `TripAnalyzer` يعيد حساب المسافة والتوقفات والانحرافات (idempotent، يدعم الترتيب الخاطئ).
6. إنهاء كل المهام ثم `trip.close` بقراءة النهاية ⇒ إغلاق المرحلة والرحلة ومقارنة العداد/GPS (علامة مراجعة فقط).

## المسؤوليات
- `Core/Authz`: صلاحيات ونطاقات. `Core/Audit`: سلسلة تجزئة. `Core/Settings`: عتبات قابلة للتعديل.
- `Services/*`: كل منطق الأعمال؛ HTTP طبقة رقيقة فوقها.
- الأوقات: UTC في القاعدة، وتُعرض بتوقيت الرياض (UTC+3) في اللوحة افتراضيًا (`Labels::dt`).
