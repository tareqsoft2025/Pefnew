package com.pef.fleet.core

/**
 * كشف الوصول للوجهة: يتطلب عدة قراءات متتالية داخل نصف القطر وبدقة مقبولة.
 * الناتج «اقتراح وصول» فقط؛ المهمة لا تُغلق آليًا بل بتأكيد السائق.
 */
class ArrivalDetector(
    private val destLat: Double, private val destLon: Double,
    private val radiusM: Double = 150.0, private val minReadings: Int = 3, private val maxAccuracyM: Float = 60f,
) {
    private var streak = 0
    var suggested = false; private set

    fun feed(fix: Fix): Boolean {
        if (fix.accuracyM != null && fix.accuracyM > maxAccuracyM) return suggested   // قراءة ضعيفة لا تغيّر الحالة
        val inside = Geo.haversine(fix.lat, fix.lon, destLat, destLon) <= radiusM
        streak = if (inside) streak + 1 else 0
        if (streak >= minReadings) suggested = true
        if (!inside) suggested = false
        return suggested
    }
}
