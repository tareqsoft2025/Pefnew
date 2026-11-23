package com.pef.fleet.core

import kotlin.random.Random
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

class CoreTest {
    private fun fix(t: Long, lat: Double, lon: Double, acc: Float? = 10f, sp: Float? = 0f) = Fix(t * 1000, lat, lon, acc, sp)

    @Test fun haversineKnownDistance() {
        val d = Geo.haversine(24.7, 46.7, 24.718, 46.7)
        assertTrue(d in 1990.0..2010.0, "was $d")
    }

    @Test fun arrivalNeedsStableReadingsAndGoodAccuracy() {
        val a = ArrivalDetector(24.8, 46.8, radiusM = 150.0, minReadings = 3)
        assertFalse(a.feed(fix(0, 24.8001, 46.8001)))
        assertFalse(a.feed(fix(10, 24.8001, 46.8001)))
        assertFalse(a.feed(fix(20, 24.8001, 46.8001, acc = 500f)))   // دقة سيئة لا تُحتسب
        assertTrue(a.feed(fix(30, 24.8001, 46.8001)))
        assertFalse(a.feed(fix(40, 24.9, 46.9)))   // غادر النطاق
    }

    @Test fun arrivalSingleSpikeDoesNotTrigger() {
        val a = ArrivalDetector(24.8, 46.8, minReadings = 3)
        assertFalse(a.feed(fix(0, 24.8, 46.8)))
        assertFalse(a.feed(fix(10, 25.0, 47.0)))
        assertFalse(a.feed(fix(20, 24.8, 46.8)))
    }

    @Test fun stopDetectedPromptedAndEnded() {
        val d = StopDetector()
        val events = ArrayList<StopEvent>()
        events += d.feed(fix(0, 24.7, 46.7, sp = 10f))
        for (i in 1..14) events += d.feed(fix(i * 60L, 24.70001, 46.70001))   // 14 دقيقة ثبات
        events += d.feed(fix(15 * 60L, 24.71, 46.7, sp = 12f))
        assertEquals(1, events.count { it is StopEvent.Started })
        assertEquals(1, events.count { it is StopEvent.PromptDue })
        assertEquals(1, events.count { it is StopEvent.AlertDue })
        assertEquals(1, events.count { it is StopEvent.Ended })
    }

    @Test fun stopIgnoresPoorAccuracy() {
        val d = StopDetector()
        d.feed(fix(0, 24.7, 46.7))
        assertTrue(d.feed(fix(300, 24.9, 46.9, acc = 900f)).isEmpty())
    }

    @Test fun backoffGrowsAndCaps() {
        val r = Random(1)
        val d = (0..12).map { Backoff.delaySeconds(it, rnd = r) }
        assertTrue(d[1] >= d[0] && d[3] > d[1])
        assertTrue(d.last() <= 900)
    }

    @Test fun outboxOrdersSosFirstThenDecisionsThenOthers() {
        val items = listOf(
            OutboxItem(1, "a", "odometer.record", 100), OutboxItem(2, "b", "mission.accept", 200),
            OutboxItem(3, "c", "sos.create", 300), OutboxItem(4, "d", "stop.explain", 50), OutboxItem(5, "e", "mission.reject", 150),
            OutboxItem(6, "f", "sos.create", 10, nextAttemptMs = 9_999_999),   // لم يحن موعده
        )
        val due = Outbox.due(items, nowMs = 1000).map { it.eventId }
        assertEquals(listOf("c", "e", "b", "d", "a"), due)
    }

    @Test fun serverVerdictNeverDeletesOnRetry() {
        assertEquals(ServerVerdict.DELETE_OK, SyncResults.verdict("applied"))
        assertEquals(ServerVerdict.DELETE_OK, SyncResults.verdict("duplicate"))
        assertEquals(ServerVerdict.DELETE_CONFLICT_APPLY_SERVER, SyncResults.verdict("conflict"))
        assertEquals(ServerVerdict.KEEP_RETRY, SyncResults.verdict("retry"))
        assertEquals(ServerVerdict.KEEP_RETRY, SyncResults.verdict("???"))
    }

    @Test fun trackingContinuesWhileAnyMissionActive() {
        assertTrue(TrackingPolicy.shouldTrack(listOf("completed", "active")))
        assertTrue(TrackingPolicy.shouldTrack(listOf("accepted")))   // التتبع يبدأ عند القبول قبل العداد
        assertFalse(TrackingPolicy.shouldTrack(listOf("completed", "cancelled", "sent")))
        assertFalse(TrackingPolicy.shouldTrack(emptyList()))
    }

    @Test fun localTransitionsRequireReasonAndOrder() {
        assertNotNull(LocalTransitions.validate("sent", LocalAction.Reject("  ")))
        assertNull(LocalTransitions.validate("sent", LocalAction.Reject("مريض")))
        assertNull(LocalTransitions.validate("delivered", LocalAction.Accept))
        assertNotNull(LocalTransitions.validate("cancelled", LocalAction.Accept))
        assertNotNull(LocalTransitions.validate("active", LocalAction.Complete))
        assertNull(LocalTransitions.validate("arrived", LocalAction.Complete))
    }

    @Test fun odometerValidationAndStrictestPhotoPolicy() {
        assertNull(OdometerValidator.validate(1000.0, 1000.0))
        assertNotNull(OdometerValidator.validate(1000.0, 999.0))
        assertNotNull(OdometerValidator.validate(null, null))
        assertTrue(OdometerValidator.photoRequired(false, true, false))
        assertFalse(OdometerValidator.photoRequired(false, false, false))
    }
}
