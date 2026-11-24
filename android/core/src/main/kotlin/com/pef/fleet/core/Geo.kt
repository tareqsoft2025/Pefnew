package com.pef.fleet.core

import kotlin.math.*

/** حسابات جغرافية نقية (بلا Android) قابلة للاختبار على JVM. */
object Geo {
    fun haversine(lat1: Double, lon1: Double, lat2: Double, lon2: Double): Double {
        val r = 6371000.0
        val p1 = Math.toRadians(lat1); val p2 = Math.toRadians(lat2)
        val dp = p2 - p1; val dl = Math.toRadians(lon2 - lon1)
        val a = sin(dp / 2).pow(2) + cos(p1) * cos(p2) * sin(dl / 2).pow(2)
        return 2 * r * asin(min(1.0, sqrt(a)))
    }
}

data class Fix(val tsMs: Long, val lat: Double, val lon: Double, val accuracyM: Float?, val speedMps: Float?)
