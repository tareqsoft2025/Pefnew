package com.pef.fleet.core

/** أولويات المزامنة: SOS ثم قرارات المهام والأحداث المهمة ثم الباقي. GPS والصور تُرفع بعد الأحداث. */
enum class EventPriority(val rank: Int) { SOS(0), DECISION(1), OTHER(2) }

data class OutboxItem(val id: Long, val eventId: String, val type: String, val createdMs: Long, val attempts: Int = 0, val nextAttemptMs: Long = 0)

object Outbox {
    fun priorityOf(type: String): EventPriority = when (type) {
        "sos.create" -> EventPriority.SOS
        "mission.delivered", "mission.accept", "mission.reject", "mission.arrive", "mission.complete" -> EventPriority.DECISION
        else -> EventPriority.OTHER
    }

    /** الأحداث الجاهزة للإرسال الآن، مرتبة بالأولوية ثم الزمن. */
    fun due(items: List<OutboxItem>, nowMs: Long, limit: Int = 50): List<OutboxItem> =
        items.filter { it.nextAttemptMs <= nowMs }
            .sortedWith(compareBy({ priorityOf(it.type).rank }, { it.createdMs }, { it.id }))
            .take(limit)
}

/** نتيجة حدث من الخادم → ما يفعله التطبيق محليًا. لا حذف قبل تأكيد الخادم. */
enum class ServerVerdict { DELETE_OK, DELETE_CONFLICT_APPLY_SERVER, DELETE_REJECTED, KEEP_RETRY }

object SyncResults {
    fun verdict(status: String): ServerVerdict = when (status) {
        "applied", "duplicate" -> ServerVerdict.DELETE_OK
        "conflict" -> ServerVerdict.DELETE_CONFLICT_APPLY_SERVER
        "rejected" -> ServerVerdict.DELETE_REJECTED
        else -> ServerVerdict.KEEP_RETRY   // retry أو غير معروف: لا نحذف
    }
}
