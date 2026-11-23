package com.pef.fleet.sync

import android.content.Context
import androidx.work.*
import java.util.concurrent.TimeUnit

object SyncScheduler {
    private val net = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    /** مزامنة فورية (عند توفر الشبكة) — تُستدعى بعد كل حدث جديد وعند عودة الاتصال. */
    fun syncNow(c: Context) {
        val req = OneTimeWorkRequestBuilder<SyncWorker>().setConstraints(net)
            .setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.SECONDS).build()
        WorkManager.getInstance(c).enqueueUniqueWork("sync-now", ExistingWorkPolicy.KEEP, req)
    }

    /** شبكة أمان دورية (حد WorkManager الأدنى 15 دقيقة). */
    fun schedulePeriodic(c: Context) {
        val req = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES).setConstraints(net).build()
        WorkManager.getInstance(c).enqueueUniquePeriodicWork("sync-periodic", ExistingPeriodicWorkPolicy.KEEP, req)
    }
}
