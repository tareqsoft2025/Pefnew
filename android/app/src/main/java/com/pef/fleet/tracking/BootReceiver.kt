package com.pef.fleet.tracking

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.pef.fleet.Graph
import com.pef.fleet.core.TrackingPolicy
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/** يستأنف التتبع بعد إعادة التشغيل/التحديث إن بقيت مهمة نشطة. يتطلب إذن الموقع الخلفي وقد تمنعه قيود النظام. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(c: Context, i: Intent) {
        val pending = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                if (Graph.tokens.loggedIn && TrackingPolicy.shouldTrack(Graph.repo.db.missions().allNow().map { it.status })) TrackingController.ensure(c)
            } finally { pending.finish() }
        }
    }
}
