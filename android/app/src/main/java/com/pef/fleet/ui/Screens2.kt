package com.pef.fleet.ui

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.telephony.SmsManager
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.clickable
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import com.pef.fleet.Graph
import com.pef.fleet.data.GpsPointEntity
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject
import org.osmdroid.tileprovider.cachemanager.CacheManager
import org.osmdroid.tileprovider.tilesource.XYTileSource
import org.osmdroid.util.GeoPoint
import org.osmdroid.views.MapView
import org.osmdroid.views.overlay.Marker
import org.osmdroid.views.overlay.Polyline

private val repo get() = Graph.repo

// ---------- 9. الخريطة والمسار ----------
@Composable
fun MapScreen(back: () -> Unit) {
    val ctx = LocalContext.current
    val missions by repo.missions.collectAsState(emptyList()); val trip by repo.openTrip.collectAsState(null)
    var msg by remember { mutableStateOf<String?>(null) }
    var mapRef by remember { mutableStateOf<MapView?>(null) }
    var points by remember { mutableStateOf<List<GpsPointEntity>>(emptyList()) }
    LaunchedEffect(trip?.clientTripId) { while (true) { trip?.let { points = repo.db.gps().recent(it.clientTripId) }; delay(5000) } }
    Screen("الخريطة", back, scroll = false) {
        Banner("الخرائط دون إنترنت تتطلب تنزيل المنطقة مسبقًا. بدون تنزيل تُعرض البلاطات المخزنة مؤقتًا فقط.", Warn)
        AndroidView(modifier = Modifier.weight(1f).fillMaxWidth(), factory = { c ->
            MapView(c).apply { setMultiTouchControls(true); controller.setZoom(12.0); controller.setCenter(GeoPoint(24.7136, 46.6753)); mapRef = this
                tileSource = tileSourceFromConfig() }
        }, update = { mv ->
            mv.overlays.clear()
            val tracked = missions.filter { it.status in com.pef.fleet.core.MissionStatus.TRACKED || it.status in listOf("sent", "delivered") }
            tracked.forEach { m ->
                val p = JSONObject(m.payload)
                val geo = p.optJSONObject("route")?.optJSONArray("geometry")
                if (geo != null) mv.overlays.add(Polyline().apply { setPoints((0 until geo.length()).map { GeoPoint(geo.getJSONArray(it).getDouble(0), geo.getJSONArray(it).getDouble(1)) }); outlinePaint.color = 0xFF157347.toInt(); outlinePaint.strokeWidth = 9f })
                if (m.destLat != null && m.destLon != null) mv.overlays.add(Marker(mv).apply { position = GeoPoint(m.destLat, m.destLon); title = m.title })
            }
            if (points.isNotEmpty()) {
                mv.overlays.add(Polyline().apply { setPoints(points.map { GeoPoint(it.lat, it.lon) }); outlinePaint.color = 0xFF1D4A85.toInt(); outlinePaint.strokeWidth = 7f })
                points.first().let { mv.controller.setCenter(GeoPoint(it.lat, it.lon)) }
            }
            mv.invalidate()
        })
        msg?.let { Banner(it, Warn) }
        OutlinedButton(modifier = Modifier.fillMaxWidth(), onClick = {
            val mv = mapRef ?: return@OutlinedButton
            val tpl = repo.tileUrl()
            if (tpl.isBlank() || tpl.contains("tile.openstreetmap.org")) { msg = "التنزيل المسبق محظور على خوادم OpenStreetMap العامة. اضبط خادم بلاطات مرخّصًا (map.tile_url) من الإدارة."; return@OutlinedButton }
            val mgr = CacheManager(mv)
            val n = mgr.possibleTilesInArea(mv.boundingBox, 10, 15)
            if (n > 4000) { msg = "المنطقة كبيرة ($n بلاطة). قرّب الخريطة ثم أعد المحاولة."; return@OutlinedButton }
            msg = "جارٍ تنزيل $n بلاطة…"
            mgr.downloadAreaAsync(ctx, mv.boundingBox, 10, 15, object : CacheManager.CacheManagerCallback {
                override fun onTaskComplete() { msg = "اكتمل تنزيل المنطقة للاستخدام دون إنترنت" }
                override fun onTaskFailed(errors: Int) { msg = "فشل تنزيل $errors بلاطة" }
                override fun updateProgress(progress: Int, currentZoomLevel: Int, zoomMin: Int, zoomMax: Int) {}
                override fun downloadStarted() {}
                override fun setPossibleTilesInArea(total: Int) {}
            })
        }) { Text("تنزيل المنطقة الحالية للعمل دون إنترنت") }
    }
}

private fun tileSourceFromConfig(): org.osmdroid.tileprovider.tilesource.ITileSource {
    val tpl = repo.tileUrl()
    val base = tpl.substringBefore("{z}", "")
    return if (base.isNotBlank()) XYTileSource("fleet", 0, 19, 256, ".png", arrayOf(base)) else org.osmdroid.tileprovider.tilesource.TileSourceFactory.MAPNIK
}

// ---------- 11. SOS ----------
@Composable
fun SosScreen(go: (String) -> Unit, back: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val online by Graph.online.collectAsState()
    var type by remember { mutableStateOf<String?>(null) }
    var sentId by remember { mutableStateOf<String?>(null) }
    val haptic = LocalHapticFeedback.current
    Screen("طوارئ SOS", back) {
        Banner("اضغط مطوّلًا (ثانية ونصف) على الزر لإرسال البلاغ. لا يلزم اختيار نوع أو كتابة وصف.", Danger)
        if (!online) Banner("لا يوجد اتصال: سيُحفظ البلاغ على هاتفك ويظهر «بانتظار الاتصال» ولا يُعتبر مستلمًا حتى يصل للخادم.", Warn)
        Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
            HoldSosButton { haptic.performHapticFeedback(HapticFeedbackType.LongPress); scope.launch { sentId = repo.createSos(type, null, null) } }
        }
        Text("نوع الطارئ (اختياري ويمكن تحديده بعد الإرسال):")
        repo.emergencyTypes().chunked(3).forEach { row -> Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) { row.forEach { (c, n) -> FilterChip(type == c, { type = if (type == c) null else c }, { Text(n) }) } } }
        sentId?.let { id ->
            val s by repo.db.sos().observe(id).collectAsState(null)
            InfoCard("حالة البلاغ") {
                Text(if (s?.status == "received") "✅ وصل للخادم — رقم ${s?.incidentNo}" else "⏳ بانتظار الاتصال — لم يصل بعد")
                Button(onClick = { go("sosDetail/$id") }) { Text("تفاصيل البلاغ") }
                if (s?.status != "received") OutlinedButton(onClick = { sendSmsFallback(ctx, s?.lat, s?.lon, id, s?.createdMs ?: System.currentTimeMillis()) }) { Text("إرسال SMS احتياطي (تسليمه غير مضمون)") }
            }
        }
        Text("التتبع لا يتوقف عند إرسال SOS.", style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun HoldSosButton(holdMs: Long = 1500, onConfirm: () -> Unit) {
    var progress by remember { mutableFloatStateOf(0f) }
    val scope = rememberCoroutineScope()
    Box(Modifier.size(220.dp).clip(CircleShape).background(Danger.copy(alpha = 0.25f + 0.75f * progress)).pointerInput(Unit) {
        detectTapGestures(onPress = {
            val job: Job = scope.launch {
                val t0 = System.currentTimeMillis()
                while (true) { progress = ((System.currentTimeMillis() - t0) / holdMs.toFloat()).coerceAtMost(1f); if (progress >= 1f) { onConfirm(); break }; delay(16) }
            }
            tryAwaitRelease(); job.cancel(); progress = 0f
        })
    }, contentAlignment = Alignment.Center) {
        Text(if (progress >= 1f) "تم" else "SOS\nاضغط مطوّلًا", color = androidx.compose.ui.graphics.Color.White, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.headlineMedium)
    }
}

/** قناة SOS احتياطية عبر رسالة SMS من الهاتف نفسه. التسليم غير مضمون ويعتمد على شبكة الهاتف. */
private fun sendSmsFallback(ctx: Context, lat: Double?, lon: Double?, eventId: String, createdMs: Long) {
    val number = repo.sosFallbackNumber() ?: return
    val text = "SOS|$eventId|${lat ?: 0.0}|${lon ?: 0.0}|${createdMs / 1000}"
    if (ContextCompat.checkSelfPermission(ctx, Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED) {
        runCatching { ctx.getSystemService(SmsManager::class.java).sendTextMessage(number, null, text, null, null) }
    } else {
        ctx.startActivity(Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:$number")).putExtra("sms_body", text).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}

// ---------- 12. تفاصيل بلاغ الطوارئ ----------
@Composable
fun SosDetailScreen(id: String, back: () -> Unit) {
    val s by repo.db.sos().observe(id).collectAsState(null)
    var updates by remember { mutableStateOf<List<String>>(emptyList()) }
    LaunchedEffect(s?.incidentId) {
        while (true) {
            s?.incidentId?.let { iid -> runCatching { val v = repo.api.get("api/v1/emergencies/$iid"); updates = (0 until v.getJSONArray("updates").length()).map { v.getJSONArray("updates").getJSONObject(it).getString("message") } } }
            delay(15000)
        }
    }
    Screen("تفاصيل البلاغ", back) {
        val x = s ?: run { Text("غير موجود"); return@Screen }
        InfoCard("الحالة") {
            Text(if (x.status == "received") "✅ وصل للخادم" else "⏳ بانتظار الاتصال — لم يصل بعد", fontWeight = FontWeight.Bold)
            x.incidentNo?.let { Text("رقم البلاغ: $it") }
            Text("حالة المعالجة: " + when (x.serverStatus) { "new" -> "جديد — لم يُستلم بعد"; "acknowledged" -> "تم تأكيد الاستلام"; "in_progress" -> "قيد المعالجة"; "awaiting_update" -> "بانتظار تحديث"; "resolved" -> "تمت المعالجة"; "closed" -> "مغلق"; "false_alarm" -> "إنذار خاطئ"; else -> "—" })
            Text("وقت الضغط: ${java.util.Date(x.createdMs)}")
            if (x.lat != null) Text("الموقع: ${x.lat}, ${x.lon}" + if (x.lastKnown) " (آخر موقع معروف — قديم)" else "")
        }
        if (updates.isNotEmpty()) InfoCard("تحديثات من الإدارة") { updates.forEach { Text("• $it") } }
    }
}
