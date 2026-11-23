package com.pef.fleet.push

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.pef.fleet.Graph
import com.pef.fleet.sync.SyncScheduler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/** Push للتنبيه فقط: أي رسالة تُطلق مزامنة؛ التفاصيل الموثوقة تأتي دائمًا من الخادم عبر API. */
class FleetMessagingService : FirebaseMessagingService() {
    override fun onMessageReceived(m: RemoteMessage) { SyncScheduler.syncNow(applicationContext) }
    override fun onNewToken(token: String) { CoroutineScope(Dispatchers.IO).launch { Graph.repo.registerPushToken(token) } }
}
