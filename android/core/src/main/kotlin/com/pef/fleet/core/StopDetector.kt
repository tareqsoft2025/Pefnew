package com.pef.fleet.core

sealed interface StopEvent {
    data class Started(val atMs: Long) : StopEvent
    data class PromptDue(val atMs: Long) : StopEvent   // طلب السبب (افتراضيًا 5 دقائق) — لا يُعرض أثناء القيادة
    data class AlertDue(val atMs: Long) : StopEvent
    data class Ended(val startedMs: Long, val endedMs: Long) : StopEvent
}

/** كاشف توقف على الجهاز (للمطالبة بالسبب)؛ الخادم يعيد التحليل ويعتمد نتيجته. */
class StopDetector(
    private val radiusM: Double = 40.0, private val recordAfterS: Long = 180, private val promptAfterS: Long = 300,
    private val alertAfterS: Long = 600, private val maxAccuracyM: Float = 60f,
) {
    private var anchor: Fix? = null
    private var lastStillMs = 0L
    private var stopStart: Long? = null
    private var promptSent = false
    private var alertSent = false

    fun feed(f: Fix): List<StopEvent> {
        if (f.accuracyM != null && f.accuracyM > maxAccuracyM) return emptyList()
        val a = anchor
        if (a == null) { anchor = f; lastStillMs = f.tsMs; return emptyList() }
        val out = ArrayList<StopEvent>()
        val moved = Geo.haversine(a.lat, a.lon, f.lat, f.lon) > radiusM || (f.speedMps ?: 0f) > 2.5f
        if (!moved) {
            lastStillMs = f.tsMs
            val dur = (f.tsMs - a.tsMs) / 1000
            if (stopStart == null && dur >= recordAfterS) { stopStart = a.tsMs; out += StopEvent.Started(a.tsMs) }
            if (stopStart != null) {
                val d = (f.tsMs - stopStart!!) / 1000
                if (!promptSent && d >= promptAfterS) { promptSent = true; out += StopEvent.PromptDue(stopStart!! + promptAfterS * 1000) }
                if (!alertSent && d >= alertAfterS) { alertSent = true; out += StopEvent.AlertDue(stopStart!! + alertAfterS * 1000) }
            }
        } else {
            stopStart?.let { out += StopEvent.Ended(it, lastStillMs) }
            stopStart = null; promptSent = false; alertSent = false
            anchor = f; lastStillMs = f.tsMs
        }
        return out
    }
}
