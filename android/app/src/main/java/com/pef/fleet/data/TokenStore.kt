package com.pef.fleet.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.util.UUID

/** يخزن الرموز وعنوان الخادم مشفرة بمفتاح Android Keystore (EncryptedSharedPreferences). */
class TokenStore(context: Context) {
    private val prefs: SharedPreferences = run {
        val key = MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
        EncryptedSharedPreferences.create(context, "secure_prefs", key,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV, EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM)
    }
    var serverUrl: String
        get() = prefs.getString("server", "") ?: ""
        set(v) { prefs.edit().putString("server", v.trim().let { if (it.isEmpty() || it.endsWith("/")) it else "$it/" }).apply() }
    var access: String? get() = prefs.getString("access", null); set(v) { prefs.edit().putString("access", v).apply() }
    var refresh: String? get() = prefs.getString("refresh", null); set(v) { prefs.edit().putString("refresh", v).apply() }
    var driverId: Long get() = prefs.getLong("driver", 0); set(v) { prefs.edit().putLong("driver", v).apply() }
    var userName: String get() = prefs.getString("name", "") ?: ""; set(v) { prefs.edit().putString("name", v).apply() }
    var pushToken: String? get() = prefs.getString("push", null); set(v) { prefs.edit().putString("push", v).apply() }
    var configJson: String? get() = prefs.getString("config", null); set(v) { prefs.edit().putString("config", v).apply() }
    var lastSyncOkMs: Long get() = prefs.getLong("last_ok", 0); set(v) { prefs.edit().putLong("last_ok", v).apply() }
    var lastSyncError: String? get() = prefs.getString("last_err", null); set(v) { prefs.edit().putString("last_err", v).apply() }
    val deviceId: String get() = prefs.getString("device", null) ?: UUID.randomUUID().toString().also { prefs.edit().putString("device", it).apply() }
    val loggedIn: Boolean get() = !refresh.isNullOrEmpty()
    fun clearSession() { prefs.edit().remove("access").remove("refresh").remove("driver").apply() }
}
