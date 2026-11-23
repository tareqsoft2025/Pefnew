package com.pef.fleet.core

import kotlin.math.min
import kotlin.random.Random

/** تراجع أسي مع jitter لإعادة المحاولة (ثوانٍ). */
object Backoff {
    fun delaySeconds(attempt: Int, baseS: Long = 5, capS: Long = 900, rnd: Random = Random.Default): Long {
        val exp = min(capS, baseS shl min(attempt.coerceAtLeast(0), 20))
        val jitter = (exp * 0.2 * rnd.nextDouble()).toLong()
        return min(capS, exp + jitter)
    }
}
