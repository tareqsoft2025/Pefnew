package com.pef.fleet.ui

import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import com.pef.fleet.Graph
import java.io.File
import java.util.UUID

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun Screen(title: String, onBack: (() -> Unit)? = null, actions: @Composable RowScope.() -> Unit = {}, scroll: Boolean = true, content: @Composable ColumnScope.() -> Unit) {
    Scaffold(topBar = {
        TopAppBar(title = { Text(title, fontWeight = FontWeight.Bold) },
            navigationIcon = { if (onBack != null) IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "رجوع") } },
            actions = actions, colors = TopAppBarDefaults.topAppBarColors(containerColor = Navy, titleContentColor = Color.White, navigationIconContentColor = Color.White, actionIconContentColor = Color.White))
    }) { pad ->
        val m = Modifier.padding(pad).fillMaxSize().padding(16.dp)
        Column(if (scroll) m.verticalScroll(rememberScrollState()) else m, verticalArrangement = Arrangement.spacedBy(12.dp), content = content)
    }
}

@Composable
fun InfoCard(title: String? = null, container: Color = MaterialTheme.colorScheme.surface, content: @Composable ColumnScope.() -> Unit) {
    Card(colors = CardDefaults.cardColors(containerColor = container), shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (title != null) Text(title, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            content()
        }
    }
}

@Composable
fun Banner(text: String, color: Color = Warn) {
    Surface(color = color.copy(alpha = 0.14f), shape = RoundedCornerShape(10.dp), modifier = Modifier.fillMaxWidth()) {
        Text(text, color = color, modifier = Modifier.padding(12.dp), fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun StatusChip(status: String, pending: Boolean = false) {
    val (label, color) = when (status) {
        "sent", "delivered" -> "جديدة" to Warn; "accepted", "active" -> "نشطة" to Ok; "arrived" -> "وصلت" to Ok
        "completion_pending" -> "إنهاء معلق" to Warn; "completed" -> "مكتملة" to Color.Gray; "cancelled" -> "ملغاة" to Color.Gray; "rejected" -> "مرفوضة" to Danger
        else -> status to Color.Gray
    }
    AssistChip(onClick = {}, label = { Text(label + if (pending) " · بانتظار تأكيد الخادم" else "") }, colors = AssistChipDefaults.assistChipColors(labelColor = color))
}

@Composable
fun SyncBadge(count: Int, label: String) { if (count > 0) Banner("$count $label بانتظار الإرسال", Warn) }

/** التقاط صورة وحفظها في مخزن التطبيق الخاص؛ الحفظ المحلي لا يعني الوصول للخادم (تُعرض الحالة في مركز المزامنة). */
@Composable
fun rememberPhotoCapture(onResult: (File) -> Unit): () -> Unit {
    val ctx = LocalContext.current
    var pending by remember { mutableStateOf<File?>(null) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) pending?.let(onResult) }
    return {
        val dir = File(ctx.filesDir, "photos").apply { mkdirs() }
        val f = File(dir, UUID.randomUUID().toString() + ".jpg"); pending = f
        val uri: Uri = FileProvider.getUriForFile(ctx, ctx.packageName + ".files", f)
        launcher.launch(uri)
    }
}

fun fmtAgo(ms: Long): String {
    if (ms <= 0) return "لم يحدث بعد"
    val s = (System.currentTimeMillis() - ms) / 1000
    return when { s < 60 -> "قبل $s ث"; s < 3600 -> "قبل ${s / 60} د"; s < 86400 -> "قبل ${s / 3600} س"; else -> "قبل ${s / 86400} يوم" }
}
