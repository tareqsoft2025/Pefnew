package com.pef.fleet.sync

import android.content.Context
import android.content.pm.ServiceInfo
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.work.CoroutineWorker
import androidx.work.ForegroundInfo
import androidx.work.WorkerParameters
import com.pef.fleet.FleetApp
import com.pef.fleet.Graph

class SyncWorker(c: Context, p: WorkerParameters) : CoroutineWorker(c, p) {
    override suspend fun doWork(): Result {
        val r = Graph.repo.sync()
        // فشل شبكة/خادم مؤقت ⇒ إعادة بتراجع أسي من WorkManager؛ خطأ نهائي (مثل 401) ⇒ لا تكرار فوري
        return if (r.ok) Result.success() else if (r.error?.contains("اتصال") == true) Result.retry() else Result.failure()
    }

    override suspend fun getForegroundInfo(): ForegroundInfo {
        val n = NotificationCompat.Builder(applicationContext, FleetApp.CH_TRACKING).setSmallIcon(android.R.drawable.stat_notify_sync)
            .setContentTitle("مزامنة البيانات").setOngoing(true).build()
        return if (Build.VERSION.SDK_INT >= 29) ForegroundInfo(2, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC) else ForegroundInfo(2, n)
    }
}
