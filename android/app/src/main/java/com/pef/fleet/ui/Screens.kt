package com.pef.fleet.ui

import android.Manifest
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.pef.fleet.BuildConfig
import com.pef.fleet.Graph
import com.pef.fleet.core.MissionStatus
import com.pef.fleet.core.TrackingPolicy
import com.pef.fleet.data.*
import com.pef.fleet.tracking.TrackingController
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject

private val repo get() = Graph.repo
private val tokens get() = Graph.tokens

// ---------- 1. البداية ----------
@Composable
fun SplashScreen(next: (String) -> Unit) {
    LaunchedEffect(Unit) { delay(700); next(if (tokens.loggedIn) "home" else "login") }
    Surface(color = Navy, modifier = Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
            Text("🚐", style = MaterialTheme.typography.displayLarge)
            Text("سائق الحركة", color = androidx.compose.ui.graphics.Color.White, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
        }
    }
}

// ---------- 2. تسجيل الدخول ----------
@Composable
fun LoginScreen(done: () -> Unit) {
    var server by remember { mutableStateOf(tokens.serverUrl.ifEmpty { BuildConfig.DEFAULT_SERVER_URL }) }
    var user by remember { mutableStateOf("") }; var pass by remember { mutableStateOf("") }; var mfa by remember { mutableStateOf("") }
    var needMfa by remember { mutableStateOf(false) }; var busy by remember { mutableStateOf(false) }; var err by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    Screen("تسجيل الدخول") {
        Text("استخدم الحساب الذي أنشأه مدير الحركة. الاتصال عبر HTTPS فقط.")
        OutlinedTextField(server, { server = it }, label = { Text("عنوان الخادم (https://…)") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
        OutlinedTextField(user, { user = it }, label = { Text("اسم المستخدم") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
        OutlinedTextField(pass, { pass = it }, label = { Text("كلمة المرور") }, modifier = Modifier.fillMaxWidth(), singleLine = true, visualTransformation = PasswordVisualTransformation())
        if (needMfa) OutlinedTextField(mfa, { mfa = it }, label = { Text("رمز التحقق الثنائي") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
        err?.let { Banner(it, Danger) }
        Button(enabled = !busy && server.startsWith("https://") && user.isNotBlank() && pass.isNotBlank(), modifier = Modifier.fillMaxWidth(), onClick = {
            busy = true; err = null
            scope.launch {
                try { repo.login(server, user.trim(), pass, mfa.ifBlank { null }); com.pef.fleet.sync.SyncScheduler.syncNow(ctx); done() }
                catch (e: ApiError) { if (e.code == "mfa_required") needMfa = true; err = e.message }
                catch (e: NetworkError) { err = "تعذر الاتصال بالخادم. تحقق من العنوان والإنترنت" }
                finally { busy = false }
            }
        }) { Text(if (busy) "جارٍ الدخول…" else "دخول") }
    }
}

// ---------- 3. الرئيسية ----------
@Composable
fun HomeScreen(go: (String) -> Unit) {
    val ctx = LocalContext.current
    val missions by repo.missions.collectAsState(emptyList())
    val trip by repo.openTrip.collectAsState(null)
    val outbox by repo.outboxPending.collectAsState(0); val gps by repo.gpsPending.collectAsState(0); val photos by repo.photosPending.collectAsState(0)
    val online by Graph.online.collectAsState(); val blocked by Graph.trackingBlocked.collectAsState()
    val due by repo.stopPromptsDue.collectAsState(emptyList())
    var hasLoc by remember { mutableStateOf(TrackingController.hasLocationPermission(ctx)) }
    val permLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { hasLoc = TrackingController.hasLocationPermission(ctx); if (hasLoc) TrackingController.ensure(ctx) }
    val bgLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { }
    val active = missions.filter { it.status in MissionStatus.TRACKED }
    val fresh = missions.filter { it.status in listOf("sent", "delivered") }
    val tracking = TrackingPolicy.shouldTrack(missions.map { it.status })
    Screen("الرئيسية", actions = {
        IconButton(onClick = { go("notifications") }) { Text("🔔") }
        IconButton(onClick = { go("profile") }) { Text("👤") }
    }) {
        Text("مرحبًا ${tokens.userName}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        if (!online) Banner("لا يوجد اتصال — يستمر التسجيل محليًا وتُرسل البيانات عند عودة الإنترنت")
        blocked?.let { Banner(it, Danger) }
        if (!hasLoc) InfoCard("أذونات مطلوبة") {
            Text("يحتاج التطبيق إذن الموقع لتسجيل مسار المهام، وإذن الإشعارات لإظهار إشعار التتبع الدائم.")
            Button(onClick = { permLauncher.launch(buildList { add(Manifest.permission.ACCESS_FINE_LOCATION); add(Manifest.permission.ACCESS_COARSE_LOCATION); if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS) }.toTypedArray()) }) { Text("منح الأذونات") }
        } else if (Build.VERSION.SDK_INT >= 29 && !TrackingController.hasBackgroundPermission(ctx)) InfoCard("استمرار التتبع في الخلفية") {
            Text("للسماح باستئناف التتبع بعد إعادة تشغيل الهاتف اختر «السماح طوال الوقت». لا يُسجَّل موقعك خارج المهام.")
            OutlinedButton(onClick = { bgLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION) }) { Text("السماح طوال الوقت") }
        }
        due.firstOrNull()?.let { Banner("توقف طويل — اذكر السبب عندما يكون ذلك آمنًا", Warn); OutlinedButton(onClick = { go("stop") }) { Text("تسجيل سبب التوقف") } }
        InfoCard("رحلتي الحالية") {
            if (trip == null) Text("لا توجد رحلة نشطة. تبدأ الرحلة تلقائيًا عند قبول مهمة.")
            else {
                Text(if (tracking) "🟢 التتبع نشط · ${active.size} مهمة نشطة" else "لا مهام نشطة — يمكنك إغلاق الرحلة")
                if (trip!!.startOdoPending) Banner("قراءة عداد البداية معلقة (التتبع لم يتوقف)")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { go("trip") }) { Text("تفاصيل الرحلة") }
                    if (trip!!.startOdoPending) OutlinedButton(onClick = { go("odo/start") }) { Text("إدخال العداد") }
                }
            }
        }
        InfoCard("مهامي") {
            Text("${fresh.size} جديدة · ${active.size} نشطة")
            fresh.take(3).forEach { m -> TextButton(onClick = { go("mission/${m.id}") }) { Text("${m.missionNo} — ${m.title}") } }
            Button(onClick = { go("missions") }) { Text("كل المهام") }
        }
        InfoCard("المزامنة") {
            Text("آخر نجاح: ${fmtAgo(tokens.lastSyncOkMs)}")
            SyncBadge(outbox, "حدث"); SyncBadge(gps, "نقطة GPS"); SyncBadge(photos, "صورة")
            OutlinedButton(onClick = { go("sync") }) { Text("مركز المزامنة") }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
            OutlinedButton(onClick = { go("map") }, modifier = Modifier.weight(1f)) { Text("الخريطة") }
            OutlinedButton(onClick = { go("history") }, modifier = Modifier.weight(1f)) { Text("السجل") }
            OutlinedButton(onClick = { go("help") }, modifier = Modifier.weight(1f)) { Text("مساعدة") }
        }
        Button(onClick = { go("sos") }, colors = ButtonDefaults.buttonColors(containerColor = Danger), modifier = Modifier.fillMaxWidth().height(64.dp)) { Text("🆘  SOS — طوارئ", style = MaterialTheme.typography.titleLarge) }
    }
}

// ---------- 4. قائمة المهام ----------
@Composable
fun MissionsScreen(go: (String) -> Unit, back: () -> Unit) {
    val missions by repo.missions.collectAsState(emptyList())
    Screen("مهامي", back, scroll = false) {
        if (missions.isEmpty()) Text("لا توجد مهام. اسحب للمزامنة من مركز المزامنة.")
        LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            items(missions, key = { it.id }) { m ->
                InfoCard { Column(Modifier.clickable { go("mission/${m.id}") }) {
                    Text("${m.missionNo} — ${m.title}", fontWeight = FontWeight.Bold)
                    Text("الموعد: ${m.scheduledStart}", style = MaterialTheme.typography.bodySmall)
                    StatusChip(m.status, m.pendingLocal)
                    m.conflictNote?.let { Banner(it, Danger) }
                } }
            }
        }
    }
}

// ---------- 5. تفاصيل المهمة ----------
@Composable
fun MissionDetailScreen(id: Long, go: (String) -> Unit, back: () -> Unit) {
    val m by repo.db.missions().observe(id).collectAsState(null)
    val scope = rememberCoroutineScope()
    var rejecting by remember { mutableStateOf(false) }; var reason by remember { mutableStateOf("") }; var msg by remember { mutableStateOf<String?>(null) }
    val mm = m
    Screen("تفاصيل المهمة", back) {
        if (mm == null) { Text("المهمة غير موجودة"); return@Screen }
        val p = JSONObject(mm.payload)
        Text("${mm.missionNo} — ${mm.title}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        StatusChip(mm.status, mm.pendingLocal)
        if (mm.pendingLocal) Banner("القرار محفوظ على هاتفك وبانتظار تأكيد الخادم", Warn)
        mm.conflictNote?.let { Banner("تعارض مع الإدارة: $it", Danger) }
        InfoCard("التفاصيل") {
            Text("الموعد: ${mm.scheduledStart}"); Text("من: ${p.optJSONObject("origin")?.optString("name").orEmpty()}"); Text("إلى: ${p.optJSONObject("destination")?.optString("name").orEmpty()}")
            p.optString("description").takeIf { it.isNotBlank() && it != "null" }?.let { Text(it) }
            p.optString("instructions").takeIf { it.isNotBlank() && it != "null" }?.let { Text("تعليمات: $it") }
            p.optString("party_info").takeIf { it.isNotBlank() && it != "null" }?.let { Text("الوفد/الحمولة: $it") }
            mm.vehiclePlate?.let { Text("المركبة: $it") }
            Text("الإثبات: " + if (mm.proofPolicy == "photo_required") "صورة إلزامية" else "اختياري")
        }
        msg?.let { Banner(it, Danger) }
        if (mm.status in listOf("sent", "delivered")) {
            if (!rejecting) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(modifier = Modifier.weight(1f), onClick = { scope.launch { msg = repo.accept(mm.id) } }) { Text("قبول") }
                OutlinedButton(modifier = Modifier.weight(1f), onClick = { rejecting = true }) { Text("رفض") }
            } else {
                OutlinedTextField(reason, { reason = it }, label = { Text("سبب الرفض (إلزامي)") }, modifier = Modifier.fillMaxWidth())
                Button(enabled = reason.isNotBlank(), onClick = { scope.launch { msg = repo.reject(mm.id, reason.trim()); if (msg == null) back() } }) { Text("تأكيد الرفض") }
            }
        }
        if (mm.status in listOf("accepted", "active")) {
            Button(modifier = Modifier.fillMaxWidth(), onClick = { go("arrival/${mm.id}") }) { Text("وصلت للوجهة / إنهاء") }
            OutlinedButton(modifier = Modifier.fillMaxWidth(), onClick = { go("map") }) { Text("عرض المسار على الخريطة") }
        }
        if (mm.status in listOf("arrived", "completion_pending")) Button(modifier = Modifier.fillMaxWidth(), onClick = { go("arrival/${mm.id}") }) { Text("إثبات التنفيذ وإنهاء") }
    }
}

// ---------- 13. الوصول وإثبات التنفيذ ----------
@Composable
fun ArrivalScreen(id: Long, back: () -> Unit) {
    val m by repo.db.missions().observe(id).collectAsState(null)
    val scope = rememberCoroutineScope()
    var notes by remember { mutableStateOf("") }; var photo by remember { mutableStateOf<java.io.File?>(null) }; var msg by remember { mutableStateOf<String?>(null) }
    val capture = rememberPhotoCapture { photo = it }
    val mm = m
    Screen("الوصول وإثبات التنفيذ", back) {
        if (mm == null) { Text("المهمة غير موجودة"); return@Screen }
        Text("${mm.missionNo} — ${mm.title}", fontWeight = FontWeight.Bold)
        Text("الوصول يُكتشف بالموقع كاقتراح فقط؛ إنهاء المهمة بتأكيدك أنت.")
        if (mm.status in listOf("accepted", "active")) Button(modifier = Modifier.fillMaxWidth(), onClick = { scope.launch { msg = repo.arrive(mm.id) } }) { Text("تأكيد الوصول للوجهة") }
        if (mm.status == "arrived") {
            OutlinedTextField(notes, { notes = it }, label = { Text("ملاحظات (اختيارية)") }, modifier = Modifier.fillMaxWidth())
            OutlinedButton(onClick = capture) { Text(if (photo == null) "التقاط صورة إثبات" + if (mm.proofPolicy == "photo_required") " (إلزامي)" else " (اختياري)" else "✓ صورة ملتقطة — إعادة الالتقاط") }
            Banner("الصورة تُحفظ على الهاتف أولًا وتُرفع عند توفر الاتصال؛ تابع حالتها في مركز المزامنة.", Warn)
            Button(modifier = Modifier.fillMaxWidth(), onClick = { scope.launch { msg = repo.complete(mm.id, notes.ifBlank { null }, photo); if (msg == null) back() } }) { Text("إنهاء المهمة") }
        }
        if (mm.status == "completion_pending") Banner("بانتظار وصول صورة الإثبات للخادم قبل اكتمال المهمة", Warn)
        msg?.let { Banner(it, Danger) }
    }
}

// ---------- 6 + 14. قراءة العداد ----------
@Composable
fun OdometerScreen(kind: String, back: () -> Unit) {
    val trip by repo.openTrip.collectAsState(null)
    val missions by repo.missions.collectAsState(emptyList())
    val scope = rememberCoroutineScope()
    var value by remember { mutableStateOf("") }; var photo by remember { mutableStateOf<java.io.File?>(null) }; var msg by remember { mutableStateOf<String?>(null) }
    val capture = rememberPhotoCapture { photo = it }
    val required = repo.globalOdoPhotoRequired() || missions.any { it.status in MissionStatus.TRACKED && (it.odometerPhotoRequired || it.vehiclePhotoRequired) }
    Screen(if (kind == "start") "عداد البداية" else "عداد النهاية", back) {
        Text(if (kind == "start") "سجّل قراءة العداد قبل التحرك. التتبع يعمل حتى لو أجّلتها." else "سجّل قراءة العداد النهائية لإغلاق الرحلة.")
        trip?.startOdometer?.let { Text("قراءة البداية: $it") }
        OutlinedTextField(value, { value = it }, label = { Text("قراءة العداد (كم)") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
        OutlinedButton(onClick = capture) { Text(if (photo == null) "التقاط صورة العداد" + if (required) " (إلزامي)" else " (اختياري)" else "✓ صورة ملتقطة") }
        msg?.let { Banner(it, Danger) }
        Button(modifier = Modifier.fillMaxWidth(), onClick = { scope.launch {
            msg = if (kind == "start") repo.recordOdometer("start", value.toDoubleOrNull(), photo, required) else repo.recordOdometer("end", value.toDoubleOrNull(), photo, required)
            if (msg == null) back()
        } }) { Text("حفظ القراءة") }
        Text("القراءة تُحفظ محليًا وتُعتمد بعد قبول الخادم لها.", style = MaterialTheme.typography.bodySmall)
    }
}

// ---------- 7. الفحص (اختياري حسب الإعداد) ----------
@Composable
fun InspectionScreen(back: () -> Unit) {
    val scope = rememberCoroutineScope()
    var tires by remember { mutableStateOf(true) }; var lights by remember { mutableStateOf(true) }; var damage by remember { mutableStateOf(false) }; var notes by remember { mutableStateOf("") }; var phase by remember { mutableStateOf("pre") }
    val trip by repo.openTrip.collectAsState(null)
    Screen("فحص المركبة", back) {
        if (!repo.inspectionEnabled()) { Banner("وحدة الفحص غير مفعّلة من الإدارة حاليًا. مستوى إلزامها لم يُحسم بعد.", Warn); return@Screen }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) { FilterChip(phase == "pre", { phase = "pre" }, { Text("قبل الحركة") }); FilterChip(phase == "post", { phase = "post" }, { Text("بعد العودة") }) }
        Row(verticalAlignment = Alignment.CenterVertically) { Checkbox(tires, { tires = it }); Text("الإطارات سليمة") }
        Row(verticalAlignment = Alignment.CenterVertically) { Checkbox(lights, { lights = it }); Text("الأنوار سليمة") }
        Row(verticalAlignment = Alignment.CenterVertically) { Checkbox(damage, { damage = it }); Text("توجد أضرار") }
        OutlinedTextField(notes, { notes = it }, label = { Text("ملاحظات") }, modifier = Modifier.fillMaxWidth())
        Button(modifier = Modifier.fillMaxWidth(), onClick = { scope.launch {
            repo.enqueue("inspection.submit", JSONObject().put("vehicle_id", trip?.vehicleId ?: 0).put("phase", phase).put("tires_ok", tires).put("lights_ok", lights).put("damage_noted", damage).put("notes", notes)); back() } }) { Text("حفظ الفحص") }
    }
}

// ---------- 8. الرحلة النشطة ----------
@Composable
fun TripScreen(go: (String) -> Unit, back: () -> Unit) {
    val trip by repo.openTrip.collectAsState(null); val missions by repo.missions.collectAsState(emptyList())
    val online by Graph.online.collectAsState(); val lastFix by Graph.lastFixMs.collectAsState(); val gps by repo.gpsPending.collectAsState(0)
    val active = missions.filter { it.status in MissionStatus.TRACKED }
    Screen("الرحلة النشطة", back) {
        if (trip == null) { Text("لا توجد رحلة نشطة"); return@Screen }
        Banner(if (online) "متصل بالإنترنت" else "دون اتصال — التسجيل محلي", if (online) Ok else Warn)
        InfoCard("التتبع") { Text("آخر نقطة GPS: ${fmtAgo(lastFix)}"); Text("نقاط بانتظار الرفع: $gps"); Text("بدأت: ${java.util.Date(trip!!.startedMs)}") }
        InfoCard("المهام المتزامنة (${active.size})") {
            if (active.isEmpty()) Text("لا مهام نشطة") else active.forEach { m -> TextButton(onClick = { go("mission/${m.id}") }) { Text("${m.missionNo} — ${m.title}") } }
        }
        if (trip!!.startOdoPending) Button(onClick = { go("odo/start") }, modifier = Modifier.fillMaxWidth()) { Text("إدخال عداد البداية") }
        Button(onClick = { go("closetrip") }, enabled = active.isEmpty(), modifier = Modifier.fillMaxWidth()) { Text("إغلاق الرحلة") }
        if (active.isNotEmpty()) Text("لا يمكن إغلاق الرحلة قبل انتهاء كل المهام.", style = MaterialTheme.typography.bodySmall)
        OutlinedButton(onClick = { go("stop") }, modifier = Modifier.fillMaxWidth()) { Text("تسجيل سبب توقف") }
    }
}

// ---------- 10. سبب التوقف ----------
@Composable
fun StopReasonScreen(back: () -> Unit) {
    val due by repo.stopPromptsDue.collectAsState(emptyList())
    val scope = rememberCoroutineScope()
    var notes by remember { mutableStateOf("") }
    Screen("سبب التوقف", back) {
        val s = due.firstOrNull()
        if (s == null) { Text("لا توجد توقفات تنتظر التفسير."); return@Screen }
        Text("توقف بدأ ${java.util.Date(s.startedMs)}")
        Text("اختر السبب بلمسة واحدة، أو أجّل لوقت آمن:")
        repo.stopReasons().chunked(2).forEach { row -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
            row.forEach { (code, name) -> Button(modifier = Modifier.weight(1f), onClick = { scope.launch { repo.explainStop(s.startedMs, code, notes.ifBlank { null }); back() } }) { Text(name) } }
        } }
        OutlinedTextField(notes, { notes = it }, label = { Text("ملاحظات (اختيارية)") }, modifier = Modifier.fillMaxWidth())
        TextButton(onClick = back) { Text("تأجيل") }
    }
}

// ---------- 15. إغلاق الرحلة ----------
@Composable
fun CloseTripScreen(done: () -> Unit) {
    val scope = rememberCoroutineScope()
    var value by remember { mutableStateOf("") }; var photo by remember { mutableStateOf<java.io.File?>(null) }; var msg by remember { mutableStateOf<String?>(null) }
    val capture = rememberPhotoCapture { photo = it }
    val required = repo.globalOdoPhotoRequired()
    Screen("إغلاق الرحلة", done) {
        Text("أدخل قراءة العداد النهائية. بعد إغلاق الرحلة يتوقف التتبع.")
        OutlinedTextField(value, { value = it }, label = { Text("عداد النهاية (كم)") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
        OutlinedButton(onClick = capture) { Text(if (photo == null) "صورة العداد" + if (required) " (إلزامي)" else " (اختياري)" else "✓ صورة ملتقطة") }
        msg?.let { Banner(it, Danger) }
        Button(modifier = Modifier.fillMaxWidth(), onClick = { scope.launch { msg = repo.closeTrip(value.toDoubleOrNull(), photo, required); if (msg == null) done() } }) { Text("إغلاق الرحلة") }
    }
}

// ---------- 16. مركز المزامنة ----------
@Composable
fun SyncCenterScreen(back: () -> Unit) {
    val outbox by repo.outboxAll.collectAsState(emptyList()); val photos by repo.photosAll.collectAsState(emptyList())
    val gps by repo.gpsPending.collectAsState(0); val online by Graph.online.collectAsState()
    val scope = rememberCoroutineScope()
    val pending = outbox.filter { it.state == "pending" }; val rejected = outbox.filter { it.state == "rejected" }
    Screen("مركز المزامنة", back) {
        Banner(if (online) "متصل" else "دون اتصال", if (online) Ok else Warn)
        InfoCard("الملخص") {
            Text("أحداث/مهام معلقة: ${pending.size}"); Text("نقاط GPS معلقة: $gps")
            Text("صور: ${photos.count { it.status == "pending" }} بانتظار الرفع · ${photos.count { it.status == "uploaded" }} وصلت · ${photos.count { it.status == "failed" }} فشلت")
            Text("آخر نجاح: ${fmtAgo(tokens.lastSyncOkMs)}"); tokens.lastSyncError?.let { Text("آخر خطأ: $it", color = Danger) }
        }
        Button(modifier = Modifier.fillMaxWidth(), onClick = { scope.launch { repo.retryAllNow() } }) { Text("إعادة المحاولة الآن") }
        if (pending.isNotEmpty()) InfoCard("بانتظار الإرسال") { pending.forEach { Text("• ${it.type}  (محاولات: ${it.attempts})${it.lastError?.let { e -> " — $e" } ?: ""}") } }
        if (rejected.isNotEmpty()) InfoCard("رفضها الخادم") {
            rejected.forEach { Text("• ${it.type}: ${it.lastError}", color = Danger) }
            OutlinedButton(onClick = { scope.launch { repo.clearRejected() } }) { Text("إخفاء") }
        }
        if (photos.isNotEmpty()) InfoCard("الصور") { photos.take(20).forEach { Text("• ${it.ownerType} — " + when (it.status) { "uploaded" -> "وصلت للخادم"; "failed" -> "فشل: ${it.lastError}"; else -> "محفوظة محليًا فقط (لم تصل بعد)" }) } }
    }
}

// ---------- 17. الإشعارات ----------
@Composable
fun NotificationsScreen(back: () -> Unit) {
    val list by repo.notifications.collectAsState(emptyList()); val scope = rememberCoroutineScope()
    Screen("الإشعارات", back, scroll = false) {
        if (list.isEmpty()) Text("لا توجد إشعارات")
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(list, key = { it.id }) { n -> InfoCard { Column(Modifier.clickable { scope.launch { repo.markNotificationRead(n.id) } }) {
                Text(n.title, fontWeight = if (n.read) FontWeight.Normal else FontWeight.Bold); n.body?.takeIf { it.isNotBlank() && it != "null" }?.let { Text(it) }; Text(n.createdAt, style = MaterialTheme.typography.bodySmall) } } }
        }
    }
}

// ---------- 18. السجل ----------
@Composable
fun HistoryScreen(back: () -> Unit) {
    var data by remember { mutableStateOf<JSONObject?>(null) }; var err by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { try { data = repo.history() } catch (e: Exception) { err = "يتطلب اتصالًا بالإنترنت لعرض السجل" } }
    Screen("السجل والتقارير", back) {
        err?.let { Banner(it) }
        data?.let { d ->
            val t = d.optJSONObject("totals")
            InfoCard("ملخصي") { Text("رحلات مغلقة: ${t?.optInt("trips")}"); Text("المسافة (GPS): ${(t?.optLong("distance_gps_m") ?: 0) / 1000.0} كم") }
            val ms = d.optJSONArray("missions")
            InfoCard("آخر المهام") { if (ms != null) for (i in 0 until ms.length()) ms.getJSONObject(i).let { Text("• ${it.optString("mission_no")} — ${it.optString("title")} (${it.optString("status")})") } }
        } ?: if (err == null) CircularProgressIndicator() else Unit
    }
}

// ---------- 19. الملف الشخصي ----------
@Composable
fun ProfileScreen(back: () -> Unit, loggedOut: () -> Unit) {
    val scope = rememberCoroutineScope()
    Screen("الملف الشخصي والإعدادات", back) {
        InfoCard("الحساب") { Text("الاسم: ${tokens.userName}"); Text("الخادم: ${tokens.serverUrl}"); Text("الجهاز: ${tokens.deviceId.take(8)}…"); Text("الإصدار: ${BuildConfig.VERSION_NAME}") }
        InfoCard("الخصوصية") { Text("يُسجَّل موقعك فقط أثناء المهام النشطة، وحالات SOS. تظهر للإدارة المخوّلة فقط.") }
        Button(colors = ButtonDefaults.buttonColors(containerColor = Danger), modifier = Modifier.fillMaxWidth(), onClick = { scope.launch {
            if (repo.db.outbox().pending().isNotEmpty()) { /* نبقي البيانات المعلقة محليًا ولا نحذفها */ }
            repo.logout(); loggedOut() } }) { Text("تسجيل الخروج") }
        Text("عند الخروج تبقى البيانات غير المرسلة على الهاتف حتى تُرسل عند الدخول مجددًا.", style = MaterialTheme.typography.bodySmall)
    }
}

// ---------- 20. المساعدة ----------
@Composable
fun HelpScreen(back: () -> Unit) {
    val ctx = LocalContext.current
    Screen("المساعدة والدعم", back) {
        InfoCard("أسئلة شائعة") {
            Text("• التتبع يبدأ تلقائيًا عند قبول المهمة، ولا يعتمد على بدء الرحلة أو العداد.")
            Text("• عند انقطاع الإنترنت تُحفظ بياناتك على الهاتف وتُرسل لاحقًا.")
            Text("• لتحسين الاستمرارية: لا تُفعّل توفير البطارية الصارم للتطبيق، ولا توقفه بالقوة (Force stop) أثناء المهمة.")
            Text("• SOS: اضغط مطوّلًا على الزر الأحمر. لا يتطلب نوعًا ولا وصفًا.")
        }
        InfoCard("دعم") { OutlinedButton(onClick = { ctx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("tel:" + (repo.sosFallbackNumber() ?: "")))) }) { Text("اتصال بمركز الحركة") } }
        OutlinedButton(onClick = { ctx.startActivity(Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + ctx.packageName))) }) { Text("إعدادات التطبيق/البطارية") }
    }
}
