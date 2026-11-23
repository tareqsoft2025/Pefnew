# ADR 0005 — مزودات خارجية قابلة للاستبدال

واجهات: `PushProvider` (Null | FCM v1)، `SmsProvider` (Null | Http | Fake)، `RoutingProvider` (StraightLine | OSRM). تُختار من متغيرات البيئة؛ الافتراضي «غير مهيأ» ويُسجَّل الفشل بصدق (مثلًا `sms_provider_not_configured`) بدل ادعاء الإرسال. لا بيانات اعتماد في المستودع.
الخرائط: Leaflet (لوحة) وosmdroid (Android) مع رابط بلاطات قابل للضبط. **تنبيه:** خوادم OSM العامة لا تسمح بالتنزيل الكثيف المسبق؛ الإنتاج يحتاج مزود بلاطات مرخّصًا أو خادمًا خاصًا (انظر `docs/integrations.md`).
