package com.pef.fleet.tracking

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import com.pef.fleet.Graph

object TrackingController {
    fun hasLocationPermission(c: Context) =
        ContextCompat.checkSelfPermission(c, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
    fun hasBackgroundPermission(c: Context) =
        ContextCompat.checkSelfPermission(c, Manifest.permission.ACCESS_BACKGROUND_LOCATION) == PackageManager.PERMISSION_GRANTED

    /** يبدأ التتبع إن لزم. لا يَعِد باستمرارية: النظام/البطارية/force-stop قد توقفه، والواجهة تعرض الحالة بصدق. */
    fun ensure(c: Context) {
        if (!hasLocationPermission(c)) { Graph.trackingBlocked.value = "إذن الموقع غير ممنوح — لا يتم تسجيل المسار"; return }
        try {
            ContextCompat.startForegroundService(c, Intent(c, TrackingService::class.java))
            Graph.trackingBlocked.value = null
        } catch (e: Exception) {
            Graph.trackingBlocked.value = "تعذر بدء التتبع في الخلفية (${e.javaClass.simpleName}). افتح التطبيق."
        }
    }
}
