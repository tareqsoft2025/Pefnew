package com.pef.fleet.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit

/** خطأ من الخادم (استجابة وصلت): لا يُعاد تلقائيًا إلا 5xx. */
class ApiError(val status: Int, val code: String, message: String, val body: JSONObject?) : Exception(message)
/** فشل شبكة (لم تصل استجابة): يُعاد لاحقًا بتراجع أسي. */
class NetworkError(cause: Throwable) : Exception(cause.message ?: "network", cause)

class ApiClient(private val tokens: TokenStore, private val http: OkHttpClient = defaultClient()) {
    private val refreshLock = Mutex()
    private val json = "application/json; charset=utf-8".toMediaType()

    companion object {
        fun defaultClient(): OkHttpClient = OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS).readTimeout(30, TimeUnit.SECONDS).writeTimeout(60, TimeUnit.SECONDS).build()
    }

    private fun url(path: String): String {
        val base = tokens.serverUrl
        if (base.isEmpty()) throw ApiError(0, "no_server", "عنوان الخادم غير مضبوط", null)
        return base + path.trimStart('/')
    }

    suspend fun get(path: String, auth: Boolean = true): JSONObject = call("GET", path, null, auth)
    suspend fun post(path: String, body: JSONObject, auth: Boolean = true): JSONObject = call("POST", path, body, auth)

    private suspend fun call(method: String, path: String, body: JSONObject?, auth: Boolean): JSONObject {
        var res = exec(method, path, body, auth)
        if (res.first == 401 && auth && tokens.refresh != null) {
            if (refreshTokens()) res = exec(method, path, body, auth)
        }
        return parse(res.first, res.second)
    }

    private suspend fun exec(method: String, path: String, body: JSONObject?, auth: Boolean): Pair<Int, String> = withContext(Dispatchers.IO) {
        val rb = Request.Builder().url(url(path))
        if (auth) tokens.access?.let { rb.header("Authorization", "Bearer $it") }
        rb.header("Accept", "application/json")
        when (method) { "GET" -> rb.get(); else -> rb.method(method, (body?.toString() ?: "{}").toRequestBody(json)) }
        try { http.newCall(rb.build()).execute().use { it.code to (it.body?.string() ?: "") } } catch (e: IOException) { throw NetworkError(e) }
    }

    private fun parse(status: Int, text: String): JSONObject {
        val obj = try { if (text.isBlank()) JSONObject() else JSONObject(text) } catch (e: Exception) { JSONObject() }
        if (status in 200..299) return obj
        val err = obj.optJSONObject("error")
        throw ApiError(status, err?.optString("code") ?: "http_$status", err?.optString("message") ?: "خطأ $status", obj)
    }

    private suspend fun refreshTokens(): Boolean = refreshLock.withLock {
        val rt = tokens.refresh ?: return false
        try {
            val r = exec("POST", "api/v1/auth/refresh", JSONObject().put("refresh_token", rt), false)
            if (r.first == 200) {
                val j = JSONObject(r.second); tokens.access = j.getString("access_token"); tokens.refresh = j.getString("refresh_token"); true
            } else { if (r.first == 401) tokens.clearSession(); false }
        } catch (e: NetworkError) { false }
    }

    /** رفع صورة multipart مع sha256 للتحقق من السلامة على الخادم. */
    suspend fun upload(path: String, fields: Map<String, String>, file: File, mime: String = "image/jpeg"): JSONObject = withContext(Dispatchers.IO) {
        suspend fun once(): Pair<Int, String> {
            val mb = MultipartBody.Builder().setType(MultipartBody.FORM)
            fields.forEach { (k, v) -> mb.addFormDataPart(k, v) }
            mb.addFormDataPart("file", file.name, file.asRequestBody(mime.toMediaType()))
            val rb = Request.Builder().url(url(path)).post(mb.build())
            tokens.access?.let { rb.header("Authorization", "Bearer $it") }
            return try { http.newCall(rb.build()).execute().use { it.code to (it.body?.string() ?: "") } } catch (e: IOException) { throw NetworkError(e) }
        }
        var r = once()
        if (r.first == 401 && refreshTokens()) r = once()
        parse(r.first, r.second)
    }
}
