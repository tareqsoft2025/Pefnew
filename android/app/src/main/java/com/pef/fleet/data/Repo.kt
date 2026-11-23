package com.pef.fleet.data

import android.content.Context
import com.pef.fleet.core.*
import com.pef.fleet.sync.SyncScheduler
import com.pef.fleet.tracking.TrackingController
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest
import java.time.Instant
import java.util.UUID

data class SyncReport(val ok: Boolean, val sentEvents: Int = 0, val uploadedPoints: Int = 0, val uploadedPhotos: Int = 0, val error: String? = null)

/**
 * مصدر الحقيقة المحلي (Room) + outbox. كل قرار يُحفظ محليًا أولًا، ولا يصبح «مؤكدًا مركزيًا» إلا بعد قبول الخادم.
 */
class Repo(private val ctx: Context, val db: AppDb, val tokens: TokenStore, val api: ApiClient) {

    // ---------- أدوات ----------
    private fun iso(ms: Long) = Instant.ofEpochMilli(ms).toString()
    private fun uuid() = UUID.randomUUID().toString()
    fun parseServerTime(s: String?): Long? = try { if (s == null) null else Instant.parse(s.replace(' ', 'T') + (if (s.endsWith("Z")) "" else "Z")).toEpochMilli() } catch (e: Exception) { null }

    fun config(): JSONObject = try { JSONObject(tokens.configJson ?: "{}") } catch (e: Exception) { JSONObject() }
    fun stopReasons(): List<Pair<String, String>> = config().optJSONArray("stop_reasons")?.let { a -> (0 until a.length()).map { a.getJSONObject(it).let { o -> o.getString("code") to o.getString("name_ar") } } } ?: emptyList()
    fun emergencyTypes(): List<Pair<String, String>> = config().optJSONArray("emergency_types")?.let { a -> (0 until a.length()).map { a.getJSONObject(it).let { o -> o.getString("code") to o.getString("name_ar") } } } ?: emptyList()
    fun globalOdoPhotoRequired() = config().optJSONObject("odometer")?.optBoolean("photo_required") ?: false
    fun gpsIntervalMs(): Long = (config().optJSONObject("gps")?.optLong("poll_interval_s", 10) ?: 10) * 1000
    fun tileUrl(): String = config().optJSONObject("map")?.optString("tile_url").orEmpty()
    fun inspectionEnabled() = config().optJSONObject("features")?.optBoolean("inspection") ?: false
    fun sosFallbackNumber(): String? = config().optString("sos_fallback_number").takeIf { it.isNotBlank() && it != "null" }

    // ---------- تدفقات للواجهة ----------
    val missions: Flow<List<MissionEntity>> get() = db.missions().all()
    val outboxAll get() = db.outbox().all()
    val outboxPending get() = db.outbox().pendingCount()
    val gpsPending get() = db.gps().pendingCount()
    val photosPending get() = db.photos().pendingCount()
    val photosAll get() = db.photos().all()
    val sosAll get() = db.sos().all()
    val notifications get() = db.notifications().all()
    val openTrip get() = db.trips().observeOpen()
    val stopPromptsDue get() = db.stopPrompts().due()

    // ---------- الدخول ----------
    suspend fun login(server: String, user: String, pass: String, mfa: String?) {
        tokens.serverUrl = server
        val body = JSONObject().put("username", user).put("password", pass).put("device_id", tokens.deviceId).put("device_label", android.os.Build.MODEL)
        tokens.pushToken?.let { body.put("push_token", it) }
        if (!mfa.isNullOrBlank()) body.put("mfa_code", mfa)
        val j = api.post("api/v1/auth/login", body, auth = false)
        tokens.access = j.getString("access_token"); tokens.refresh = j.getString("refresh_token")
        tokens.driverId = j.getLong("driver_id"); tokens.userName = j.getJSONObject("user").getString("full_name")
        runCatching { refreshConfig() }
    }

    suspend fun logout() {
        runCatching { api.post("api/v1/auth/logout", JSONObject()) }
        tokens.clearSession()
    }

    suspend fun refreshConfig() { tokens.configJson = api.get("api/v1/config").toString() }

    // ---------- outbox ----------
    suspend fun enqueue(type: String, payload: JSONObject, eventId: String = uuid(), kick: Boolean = true): String {
        db.outbox().insert(OutboxEntity(eventId = eventId, type = type, payload = payload.toString(), createdMs = System.currentTimeMillis()))
        if (kick) SyncScheduler.syncNow(ctx)
        return eventId
    }

    // ---------- قرارات المهام (محلية أولًا) ----------
    suspend fun accept(missionId: Long): String? {
        val m = db.missions().get(missionId) ?: return "المهمة غير موجودة"
        LocalTransitions.validate(m.status, LocalAction.Accept)?.let { return it }
        val trip = db.trips().open() ?: TripEntity(clientTripId = uuid(), startedMs = System.currentTimeMillis(), vehicleId = m.vehicleId).also { db.trips().upsert(it) }
        db.missions().upsert(m.copy(status = LocalTransitions.optimistic(LocalAction.Accept), pendingLocal = true, conflictNote = null))
        enqueue("mission.accept", JSONObject().put("mission_id", missionId).put("client_trip_id", trip.clientTripId))
        TrackingController.ensure(ctx)   // بدء GPS فورًا عند القبول، دون انتظار العداد
        return null
    }

    suspend fun reject(missionId: Long, reason: String): String? {
        val m = db.missions().get(missionId) ?: return "المهمة غير موجودة"
        LocalTransitions.validate(m.status, LocalAction.Reject(reason))?.let { return it }
        db.missions().upsert(m.copy(status = LocalTransitions.optimistic(LocalAction.Reject(reason)), pendingLocal = true))
        enqueue("mission.reject", JSONObject().put("mission_id", missionId).put("reason", reason))
        return null
    }

    suspend fun arrive(missionId: Long): String? {
        val m = db.missions().get(missionId) ?: return "المهمة غير موجودة"
        LocalTransitions.validate(m.status, LocalAction.Arrive)?.let { return it }
        db.missions().upsert(m.copy(status = MissionStatus.ARRIVED, pendingLocal = true))
        enqueue("mission.arrive", JSONObject().put("mission_id", missionId))
        return null
    }

    suspend fun complete(missionId: Long, notes: String?, proofPhoto: File?): String? {
        val m = db.missions().get(missionId) ?: return "المهمة غير موجودة"
        LocalTransitions.validate(m.status, LocalAction.Complete)?.let { return it }
        if (m.proofPolicy == "photo_required" && proofPhoto == null) return "صورة الإثبات إلزامية لهذه المهمة"
        proofPhoto?.let { queuePhoto("mission_proof", missionId.toString(), it) }
        val completed = if (m.proofPolicy == "photo_required") MissionStatus.COMPLETION_PENDING else MissionStatus.COMPLETED
        db.missions().upsert(m.copy(status = completed, pendingLocal = true))
        enqueue("mission.complete", JSONObject().put("mission_id", missionId).put("notes", notes ?: ""))
        return null
    }

    // ---------- العداد والرحلة ----------
    suspend fun recordOdometer(kind: String, value: Double?, photo: File?, photoRequired: Boolean): String? {
        val trip = db.trips().open() ?: return "لا توجد رحلة نشطة"
        OdometerValidator.validate(if (kind == "end") trip.startOdometer else null, value)?.let { return it }
        if (photoRequired && photo == null) return "صورة العداد إلزامية"
        val now = System.currentTimeMillis()
        val eid = uuid()
        photo?.let { queuePhoto("odometer", eid, it) }
        if (kind == "start") db.trips().upsert(trip.copy(startOdometer = value, startOdoPending = false))
        enqueue("odometer.record", JSONObject().put("kind", kind).put("value", value).put("measured_at", iso(now)), eid)
        return null
    }

    suspend fun closeTrip(endValue: Double?, photo: File?, photoRequired: Boolean): String? {
        val trip = db.trips().open() ?: return "لا توجد رحلة مفتوحة"
        val statuses = db.missions().allNow().map { it.status }
        if (TrackingPolicy.shouldTrack(statuses)) return "لا تزال هناك مهام نشطة؛ التتبع مستمر"
        OdometerValidator.validate(trip.startOdometer, endValue)?.let { return it }
        if (photoRequired && photo == null) return "صورة العداد إلزامية"
        val eid = uuid()
        photo?.let { queuePhoto("odometer", eid, it) }
        // تُرسل قراءة النهاية ضمن trip.close، وصورتها مرتبطة بحدث الإغلاق نفسه عبر مرجع القراءة
        enqueue("trip.close", JSONObject().put("client_trip_id", trip.clientTripId).put("end_odometer", endValue).put("measured_at", iso(System.currentTimeMillis())), eid)
        return null
    }

    suspend fun explainStop(startedMs: Long, reasonCode: String, notes: String?) {
        enqueue("stop.explain", JSONObject().put("started_at", iso(startedMs)).put("reason", reasonCode).put("notes", notes ?: ""))
        db.stopPrompts().markExplained(startedMs)
    }

    suspend fun queuePhoto(ownerType: String, ownerRef: String, file: File): String {
        val id = uuid()
        db.photos().upsert(PhotoEntity(id = id, ownerType = ownerType, ownerRef = ownerRef, path = file.absolutePath, capturedMs = System.currentTimeMillis()))
        return id
    }

    // ---------- SOS ----------
    /** SOS عاجل: لا يتطلب نوعًا ولا وصفًا. يُحفظ محليًا بأولوية قصوى ويظهر «بانتظار الاتصال» حتى يؤكد الخادم. */
    suspend fun createSos(type: String?, notes: String?, lastFix: GpsPointEntity?): String {
        val fix = lastFix ?: db.gps().last()
        val now = System.currentTimeMillis()
        val eid = uuid()
        val p = JSONObject().put("client_event_id", eid).put("created_at", iso(now))
        type?.let { p.put("type", it) }; if (!notes.isNullOrBlank()) p.put("notes", notes)
        if (fix != null) {
            p.put("lat", fix.lat).put("lon", fix.lon).put("accuracy", fix.accuracy?.toDouble()).put("location_at", iso(fix.tsMs))
            p.put("location_is_last_known", now - fix.tsMs > 60_000)
        }
        db.sos().upsert(SosEntity(eid, now, fix?.lat, fix?.lon, fix?.accuracy, fix?.tsMs, fix != null && now - fix.tsMs > 60_000, type, notes))
        enqueue("sos.create", p, eid)
        return eid
    }

    // ---------- GPS ----------
    suspend fun addPoint(clientTripId: String, f: Fix, bearing: Float?, source: String): GpsPointEntity {
        val p = GpsPointEntity(uuid(), clientTripId, f.tsMs, f.lat, f.lon, f.accuracyM, f.speedMps, bearing, source)
        db.gps().insert(p); return p
    }

    // ---------- المزامنة ----------
    @Volatile private var syncing = false

    suspend fun sync(): SyncReport {
        if (!tokens.loggedIn) return SyncReport(false, error = "غير مسجل الدخول")
        if (syncing) return SyncReport(true)
        syncing = true
        var sent = 0; var pts = 0; var photos = 0
        try {
            sent = flushOutbox()
            pts = flushGps()
            photos = flushPhotos()
            pull()
            db.gps().purgeUploaded(System.currentTimeMillis() - 24 * 3600_000)
            tokens.lastSyncOkMs = System.currentTimeMillis(); tokens.lastSyncError = null
            return SyncReport(true, sent, pts, photos)
        } catch (e: NetworkError) {
            tokens.lastSyncError = "لا يوجد اتصال بالخادم"; return SyncReport(false, sent, pts, photos, tokens.lastSyncError)
        } catch (e: ApiError) {
            tokens.lastSyncError = e.message; return SyncReport(false, sent, pts, photos, e.message)
        } finally { syncing = false }
    }

    private suspend fun flushOutbox(): Int {
        val now = System.currentTimeMillis()
        val rows = db.outbox().pending()
        val due = Outbox.due(rows.map { OutboxItem(it.id, it.eventId, it.type, it.createdMs, it.attempts, it.nextAttemptMs) }, now, 50)
        if (due.isEmpty()) return 0
        val byId = rows.associateBy { it.eventId }
        val arr = JSONArray()
        due.forEach { d -> val r = byId.getValue(d.eventId); arr.put(JSONObject().put("event_id", r.eventId).put("type", r.type).put("client_ts", iso(r.createdMs)).put("payload", JSONObject(r.payload))) }
        val res = api.post("api/v1/sync/events", JSONObject().put("events", arr))
        val results = res.optJSONArray("results") ?: JSONArray()
        for (i in 0 until results.length()) applyResult(results.getJSONObject(i), byId)
        return results.length()
    }

    private suspend fun applyResult(r: JSONObject, byId: Map<String, OutboxEntity>) {
        val eid = r.getString("event_id"); val row = byId[eid] ?: return
        val status = r.getString("status")
        when (SyncResults.verdict(status)) {
            ServerVerdict.DELETE_OK -> { applyData(row, r.optJSONObject("data")); db.outbox().delete(eid) }
            ServerVerdict.DELETE_CONFLICT_APPLY_SERVER -> {
                val msg = r.optJSONObject("error")?.optString("message")
                val sm = r.optJSONObject("server_mission")
                if (sm != null) { mergeMission(sm, conflictNote = msg); db.outbox().delete(eid) } else db.outbox().markRejected(eid, msg)
            }
            ServerVerdict.DELETE_REJECTED -> db.outbox().markRejected(eid, r.optJSONObject("error")?.optString("message"))
            ServerVerdict.KEEP_RETRY -> db.outbox().retryLater(eid, System.currentTimeMillis() + Backoff.delaySeconds(row.attempts) * 1000, r.optJSONObject("error")?.optString("message"))
        }
    }

    private suspend fun applyData(row: OutboxEntity, data: JSONObject?) {
        when (row.type) {
            "mission.accept", "mission.reject", "mission.arrive", "mission.complete" -> {
                data?.optJSONObject("mission")?.let { mergeMission(it, force = true) }
                data?.optJSONObject("trip")?.let { t ->
                    val open = db.trips().get(t.optString("client_trip_id")) ?: db.trips().open()
                    open?.let { db.trips().upsert(it.copy(serverTripId = t.optLong("id"), segmentId = t.optLong("segment_id").takeIf { s -> s > 0 })) }
                }
            }
            "trip.close" -> db.trips().open()?.let { db.trips().upsert(it.copy(closed = true)) }
            "sos.create" -> {
                val s = db.sos().get(row.eventId)
                if (s != null && data != null) db.sos().upsert(s.copy(status = "received", incidentId = data.optLong("incident_id"), incidentNo = data.optString("incident_no"), serverStatus = data.optString("status"), receivedAt = data.optString("received_at")))
            }
        }
    }

    private suspend fun flushGps(): Int {
        var total = 0
        for (tripId in db.gps().tripsWithPending()) {
            while (true) {
                val batch = db.gps().pending(tripId, 200)
                if (batch.isEmpty()) break
                val arr = JSONArray()
                batch.forEach { b -> arr.put(JSONObject().put("id", b.id).put("ts", iso(b.tsMs)).put("lat", b.lat).put("lon", b.lon).put("accuracy", b.accuracy?.toDouble()).put("speed", b.speed?.toDouble()).put("bearing", b.bearing?.toDouble()).put("source", b.source)) }
                try { api.post("api/v1/gps/batch", JSONObject().put("client_trip_id", tripId).put("points", arr)) }
                catch (e: ApiError) { if (e.status == 404) return total else throw e }   // قبول المهمة لم يُزامَن بعد
                db.gps().markUploaded(batch.map { it.id }); total += batch.size
                if (batch.size < 200) break
            }
        }
        return total
    }

    private suspend fun flushPhotos(): Int {
        var n = 0
        for (p in db.photos().pending()) {
            val f = File(p.path)
            if (!f.exists()) { db.photos().upsert(p.copy(status = "failed", lastError = "الملف المحلي غير موجود")); continue }
            // لا نرفع قبل أن يصل الحدث المرتبط بها (وإلا يرد الخادم owner_not_found)
            if (p.ownerType != "mission_proof" && db.outbox().pending().any { it.eventId == p.ownerRef }) continue
            val sha = MessageDigest.getInstance("SHA-256").digest(f.readBytes()).joinToString("") { "%02x".format(it) }
            try {
                api.upload("api/v1/attachments", mapOf("owner_type" to p.ownerType, "owner_ref" to p.ownerRef, "client_file_id" to p.id, "sha256" to sha, "captured_at" to iso(p.capturedMs)), f)
                db.photos().upsert(p.copy(status = "uploaded", lastError = null)); n++
            } catch (e: ApiError) {
                db.photos().upsert(p.copy(status = if (e.status == 404) "pending" else "failed", attempts = p.attempts + 1, lastError = e.message))
            }
        }
        return n
    }

    private suspend fun pull() {
        val res = api.get("api/v1/missions")
        val arr = res.optJSONArray("data") ?: JSONArray()
        val pendingIds = db.outbox().pending().mapNotNull { runCatching { JSONObject(it.payload).optLong("mission_id") }.getOrNull() }.filter { it > 0 }.toSet()
        val keep = ArrayList<Long>()
        for (i in 0 until arr.length()) {
            val j = arr.getJSONObject(i); keep.add(j.getLong("id"))
            mergeMission(j, localPending = j.getLong("id") in pendingIds)
            if (j.getString("status") == "sent" && j.getLong("id") !in pendingIds) enqueue("mission.delivered", JSONObject().put("mission_id", j.getLong("id")), kick = false)
        }
        db.missions().deleteNotIn(keep.ifEmpty { listOf(-1L) })
        runCatching { refreshConfig() }
        runCatching {
            val n = api.get("api/v1/notifications").optJSONArray("data") ?: JSONArray()
            db.notifications().upsertAll((0 until n.length()).map { n.getJSONObject(it).let { o -> NotificationEntity(o.getLong("id"), o.getString("type"), o.getString("title"), o.optString("body"), o.getString("created_at"), !o.isNull("read_at")) } })
        }
        runCatching {
            val pend = api.get("api/v1/trips/current/pending").optJSONArray("stops") ?: JSONArray()
            val ct = db.trips().open()?.clientTripId
            if (ct != null) for (i in 0 until pend.length()) parseServerTime(pend.getJSONObject(i).getString("started_at"))?.let { db.stopPrompts().upsert(StopPromptEntity(it, ct, promptDue = true)) }
        }
        runCatching {
            for (s in db.sos().all().first()) if (s.incidentId != null && s.serverStatus !in listOf("closed", "false_alarm")) {
                val v = api.get("api/v1/emergencies/${s.incidentId}")
                db.sos().upsert(s.copy(serverStatus = v.optString("status")))
            }
        }
    }

    private suspend fun mergeMission(j: JSONObject, force: Boolean = false, localPending: Boolean = false, conflictNote: String? = null) {
        val prev = db.missions().get(j.getLong("id"))
        val dest = j.optJSONObject("destination"); val veh = j.optJSONObject("vehicle")
        val serverStatus = j.getString("status")
        val keepLocal = prev != null && prev.pendingLocal && !force && localPending
        db.missions().upsert(MissionEntity(
            id = j.getLong("id"), missionNo = j.getString("mission_no"), title = j.getString("title"),
            status = if (keepLocal) prev!!.status else serverStatus, serverStatus = serverStatus, version = j.optInt("version"),
            scheduledStart = j.optString("scheduled_start"), destLat = dest?.optDouble("lat")?.takeIf { !it.isNaN() }, destLon = dest?.optDouble("lon")?.takeIf { !it.isNaN() },
            arrivalRadiusM = j.optInt("arrival_radius_m", 150), proofPolicy = j.optString("proof_policy", "optional"), odometerPhotoRequired = j.optBoolean("odometer_photo_required"),
            vehicleId = veh?.optLong("id"), vehiclePlate = veh?.optString("plate_no"), vehiclePhotoRequired = veh?.optInt("require_odometer_photo") == 1,
            payload = j.toString(), pendingLocal = keepLocal, conflictNote = conflictNote ?: if (keepLocal) prev?.conflictNote else null))
    }

    suspend fun markNotificationRead(id: Long) { db.notifications().markRead(id); runCatching { api.post("api/v1/notifications/$id/read", JSONObject()) } }
    suspend fun retryAllNow() { db.outbox().retryAllNow(); SyncScheduler.syncNow(ctx) }
    suspend fun clearRejected() = db.outbox().clearRejected()
    suspend fun history(): JSONObject = api.get("api/v1/history")
    suspend fun registerPushToken(t: String) { tokens.pushToken = t; if (tokens.loggedIn) runCatching { api.post("api/v1/devices/push-token", JSONObject().put("push_token", t)) } }
}
