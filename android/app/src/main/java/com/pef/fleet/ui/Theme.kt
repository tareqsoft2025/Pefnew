package com.pef.fleet.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.platform.LocalLayoutDirection

val Navy = Color(0xFF0B2545); val Navy2 = Color(0xFF13315C); val Navy3 = Color(0xFF1D4A85)
val Danger = Color(0xFFB42318); val Ok = Color(0xFF157347); val Warn = Color(0xFFB26A00)

private val Light = lightColorScheme(primary = Navy3, onPrimary = Color.White, primaryContainer = Color(0xFFDCE6F7), onPrimaryContainer = Navy,
    secondary = Navy2, background = Color(0xFFF3F6FB), surface = Color.White, error = Danger)
private val Dark = darkColorScheme(primary = Color(0xFF8DB4FF), onPrimary = Navy, background = Color(0xFF0A1424), surface = Color(0xFF111F36), error = Color(0xFFFF8A80))

/** واجهة عربية RTL دائمًا (أزرق كحلي/أبيض)؛ الإنجليزية لاحقًا بإضافة values-en وإزالة فرض الاتجاه. */
@Composable fun FleetTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (isSystemInDarkTheme()) Dark else Light) {
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) { content() }
    }
}
