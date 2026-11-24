package com.pef.fleet

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import com.pef.fleet.data.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

/** حاوية تبعيات بسيطة (Service Locator) تُهيّأ في FleetApp. */
object Graph {
    lateinit var repo: Repo; private set
    lateinit var tokens: TokenStore; private set
    private val _online = MutableStateFlow(true)
    val online: StateFlow<Boolean> get() = _online
    /** true عند فشل بدء خدمة التتبع (إذن ناقص/قيود النظام) كي تعرض الواجهة تحذيرًا صريحًا */
    val trackingBlocked = MutableStateFlow<String?>(null)
    val lastFixMs = MutableStateFlow(0L)

    fun init(c: Context) {
        val app = c.applicationContext
        tokens = TokenStore(app)
        repo = Repo(app, AppDb.build(app), tokens, ApiClient(tokens))
        val cm = app.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        cm.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) { _online.value = true; com.pef.fleet.sync.SyncScheduler.syncNow(app) }
            override fun onLost(network: Network) { _online.value = false }
        })
    }
}
