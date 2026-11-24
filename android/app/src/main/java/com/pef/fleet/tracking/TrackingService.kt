package com.pef.fleet.tracking

import android.annotation.SuppressLint
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import com.google.android.gms.location.*
import com.pef.fleet.FleetApp
import com.pef.fleet.Graph
import com.pef.fleet.R
import com.pef.fleet.core.*
import com.pef.fleet.data.StopPromptEntity
import com.pef.fleet.ui.MainActivity
import kotlinx.coroutines.*
import java.util.UUID

/**
 * خدمة موقع أمامية بإشعار دائم. تسجل نقاط GPS محليًا دائمًا، وترفعها عند توفر الاتصال.
 * تتوقف تلقائيًا فقط عندما لا تبقى أي مهمة نشطة (TrackingPolicy) — وليس بإغلاق مهمة واحدة.
 */
class TrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private lateinit var client: FusedLocationProviderClient
    private val stopDetector = StopDetector()
    private var flushJob: Job? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        client = LocationServices.getFusedLocationProviderClient(this)
    }

    @SuppressLint("MissingPermission")
    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val n = NotificationCompat.Builder(this, FleetApp.CH_TRACKING).setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setContentTitle(getString(R.string.tracking_title)).setContentText(getString(R.string.tracking_text))
            .setOngoing(true).setContentIntent(open).setCategory(NotificationCompat.CATEGORY_SERVICE).build()
        ServiceCompat.startForeground(this, 1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        if (!TrackingController.hasLocationPermission(this)) { Graph.trackingBlocked.value = "إذن الموقع غير ممنوح"; stopSelf(); return START_NOT_STICKY }
        val interval = Graph.repo.gpsIntervalMs().coerceAtLeast(3000)
        val req = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, interval).setMinUpdateIntervalMillis(interval / 2).setWaitForAccurateLocation(false).build()
        client.removeLocationUpdates(callback)
        client.requestLocationUpdates(req, callback, Looper.getMainLooper())
        flushJob?.cancel()
        flushJob = scope.launch {
            while (isActive) { delay(maxOf(30_000, interval * 3)); if (Graph.online.value) runCatching { Graph.repo.sync() } }
        }
        return START_STICKY
    }

    private val callback = object : LocationCallback() {
        override fun onLocationResult(r: LocationResult) {
            scope.launch {
                val repo = Graph.repo
                val trip = repo.db.trips().open()
                for (l in r.locations) {
                    if (trip == null) break
                    val fix = Fix(l.time, l.latitude, l.longitude, if (l.hasAccuracy()) l.accuracy else null, if (l.hasSpeed()) l.speed else null)
                    repo.addPoint(trip.clientTripId, fix, if (l.hasBearing()) l.bearing else null, if (l.provider == "gps") "gps" else "fused")
                    Graph.lastFixMs.value = l.time
                    for (e in stopDetector.feed(fix)) if (e is StopEvent.PromptDue) repo.db.stopPrompts().upsert(StopPromptEntity(e.atMs - 300_000, trip.clientTripId, promptDue = true))
                }
                // التتبع مستمر طالما بقيت مهمة نشطة
                if (!TrackingPolicy.shouldTrack(repo.db.missions().allNow().map { it.status })) { stopSelf() }
            }
        }
    }

    override fun onDestroy() {
        client.removeLocationUpdates(callback); scope.cancel(); super.onDestroy()
    }
}
