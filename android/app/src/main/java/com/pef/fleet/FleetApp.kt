package com.pef.fleet

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import com.pef.fleet.sync.SyncScheduler
import org.osmdroid.config.Configuration

class FleetApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Graph.init(this)
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CH_TRACKING, getString(R.string.channel_tracking), NotificationManager.IMPORTANCE_LOW))
        nm.createNotificationChannel(NotificationChannel(CH_ALERTS, getString(R.string.channel_alerts), NotificationManager.IMPORTANCE_HIGH))
        Configuration.getInstance().apply { userAgentValue = packageName; osmdroidBasePath = cacheDir; osmdroidTileCache = cacheDir.resolve("tiles") }
        SyncScheduler.schedulePeriodic(this)
    }
    companion object { const val CH_TRACKING = "tracking"; const val CH_ALERTS = "alerts" }
}
