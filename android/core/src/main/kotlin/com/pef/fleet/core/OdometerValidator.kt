package com.pef.fleet.core

object OdometerValidator {
    /** تحقق محلي مسبق؛ الحسم النهائي للخادم. previous = آخر قراءة معروفة (أو بداية المرحلة للنهاية). */
    fun validate(previous: Double?, value: Double?): String? = when {
        value == null || value.isNaN() -> "أدخل قراءة العداد"
        value < 0 || value > 99_999_999 -> "قراءة غير منطقية"
        previous != null && value < previous -> "القراءة أقل من السابقة ($previous). تواصل مع مدير الحركة لاستثناء موثق"
        else -> null
    }

    /** السياسة الأكثر تشددًا تغلب: عام أو مركبة أو مهمة. */
    fun photoRequired(global: Boolean, vehicle: Boolean, mission: Boolean) = global || vehicle || mission
}
