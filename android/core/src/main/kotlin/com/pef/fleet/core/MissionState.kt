package com.pef.fleet.core

/** حالات المهمة كما في الخادم + حالة «قرار محلي بانتظار تأكيد الخادم». */
object MissionStatus {
    const val SENT = "sent"; const val DELIVERED = "delivered"; const val ACCEPTED = "accepted"; const val REJECTED = "rejected"
    const val ACTIVE = "active"; const val ARRIVED = "arrived"; const val COMPLETION_PENDING = "completion_pending"
    const val COMPLETED = "completed"; const val CANCELLED = "cancelled"
    val TERMINAL = setOf(COMPLETED, CANCELLED, REJECTED)
    val TRACKED = setOf(ACCEPTED, ACTIVE, ARRIVED, COMPLETION_PENDING)   // مهام تستلزم استمرار تتبع GPS
}

object TrackingPolicy {
    /** يستمر التتبع ما دامت هناك مهمة واحدة على الأقل في حالة تتبع، بصرف النظر عن العداد أو إنهاء مهام أخرى. */
    fun shouldTrack(statuses: Collection<String>): Boolean = statuses.any { it in MissionStatus.TRACKED }
}

sealed interface LocalAction {
    data object Accept : LocalAction
    data class Reject(val reason: String) : LocalAction
    data object Arrive : LocalAction
    data object Complete : LocalAction
}

object LocalTransitions {
    /** يتحقق من القرار محليًا (قبل وضعه في outbox). null = مسموح، وإلا نص الخطأ. */
    fun validate(current: String, a: LocalAction): String? = when (a) {
        is LocalAction.Accept -> if (current in setOf(MissionStatus.SENT, MissionStatus.DELIVERED)) null else "لا يمكن قبول مهمة بحالة $current"
        is LocalAction.Reject -> when {
            a.reason.isBlank() -> "سبب الرفض إلزامي"
            current !in setOf(MissionStatus.SENT, MissionStatus.DELIVERED) -> "لا يمكن رفض مهمة بحالة $current"
            else -> null
        }
        is LocalAction.Arrive -> if (current in setOf(MissionStatus.ACCEPTED, MissionStatus.ACTIVE)) null else "لا يمكن تأكيد الوصول الآن"
        is LocalAction.Complete -> if (current == MissionStatus.ARRIVED) null else "أكّد الوصول أولًا"
    }

    /** الحالة المتوقعة محليًا (تفاؤلية) إلى حين تأكيد الخادم. */
    fun optimistic(a: LocalAction): String = when (a) {
        is LocalAction.Accept -> MissionStatus.ACTIVE
        is LocalAction.Reject -> MissionStatus.REJECTED
        is LocalAction.Arrive -> MissionStatus.ARRIVED
        is LocalAction.Complete -> MissionStatus.COMPLETED
    }
}
