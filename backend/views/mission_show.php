<?php use App\Http\Labels; ?>
<div class="grid g2">
<div class="card"><h2><?= e($m['mission_no']) ?> — <?= e($m['title']) ?> <?= Labels::mission($m['status'])->html ?></h2>
<table><tr><th>الأولوية</th><td><?= Labels::priority($m['priority'])->html ?></td></tr><tr><th>الموعد</th><td><?= e(Labels::dt($m['scheduled_start'])) ?> → <?= e(Labels::dt($m['scheduled_end'])) ?></td></tr>
<tr><th>من</th><td><?= e($m['origin_name']) ?></td></tr><tr><th>إلى</th><td><?= e($m['dest_name']) ?></td></tr>
<tr><th>السائق</th><td><?= e($m['driver_name'] ?? '—') ?></td></tr><tr><th>المركبة</th><td><?= e($m['plate_no'] ?? '—') ?></td></tr>
<tr><th>الوصف</th><td><?= nl2br(e($m['description'])) ?></td></tr><tr><th>الإثبات</th><td><?= $m['proof_policy'] === 'photo_required' ? 'صورة إلزامية' : 'اختياري' ?></td></tr>
<?php if ($m['rejection_reason']): ?><tr><th>سبب الرفض</th><td class="err"><?= e($m['rejection_reason']) ?></td></tr><?php endif; ?>
<?php if ($m['cancel_reason']): ?><tr><th>سبب الإلغاء</th><td><?= e($m['cancel_reason']) ?></td></tr><?php endif; ?>
<?php if ($m['completion_notes']): ?><tr><th>ملاحظات الإنهاء</th><td><?= e($m['completion_notes']) ?></td></tr><?php endif; ?></table>
<?php if ($canAssign && !in_array($m['status'], ['completed','cancelled'], true)): ?>
<h2 style="margin-top:18px">الإسناد</h2>
<form method="post" action="/missions/<?= (int)$m['id'] ?>/assign" class="f"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<div><label>السائق</label><select name="driver_id"><option value="">بدون تغيير</option><?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['full_name']) ?></option><?php endforeach; ?></select></div>
<div><label>المركبة</label><select name="vehicle_id"><option value="">بدون تغيير</option><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['internal_no'] . ' · ' . $v['plate_no']) ?></option><?php endforeach; ?></select></div>
<div class="w"><label>سبب التغيير</label><input name="reason"></div><div class="w"><label>استثناء تعارض المركبة (موثق)</label><input name="conflict_exception_reason"></div>
<div class="w row"><button class="btn">حفظ الإسناد</button></div></form>
<div class="row" style="margin-top:12px">
<?php if (in_array($m['status'], ['draft','rejected'], true)): ?><form method="post" action="/missions/<?= (int)$m['id'] ?>/send"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn">إرسال للسائق</button></form><?php endif; ?>
<?php if ($canCancel): ?><form method="post" action="/missions/<?= (int)$m['id'] ?>/cancel" class="row" data-confirm="تأكيد إلغاء المهمة؟"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input name="reason" placeholder="سبب الإلغاء (إلزامي)" required style="width:220px"><button class="btn bad">إلغاء المهمة</button></form><?php endif; ?></div>
<?php endif; ?></div>
<div class="card"><h2>سجل الأحداث</h2><ul class="tl"><?php foreach ($events as $e): ?><li><b><?= e(Labels::MISSION[$e['to_status']] ?? $e['to_status']) ?></b> <span class="mut"><?= e(Labels::dt($e['occurred_at'])) ?><?= $e['occurred_at'] !== $e['recorded_at'] ? ' (سُجل ' . e(Labels::dt($e['recorded_at'])) . ')' : '' ?></span><?= $e['note'] ? '<br><span class="mut">' . e($e['note']) . '</span>' : '' ?></li><?php endforeach; ?></ul></div>
</div>
<div class="card" style="margin-top:14px"><h2>المسار</h2>
<div id="map" class="map" style="height:380px"></div>
<?php if ($canRoute): ?><form method="post" action="/missions/<?= (int)$m['id'] ?>/routes" class="row noprint" style="margin-top:10px" id="rform"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="geometry" id="geom"><input type="hidden" name="source" id="rsrc" value="auto">
<button class="btn sec" name="act" value="auto">اقتراح مسار آلي</button><button class="btn" name="act" value="manual" id="savemanual" type="submit">حفظ المسار اليدوي (انقر على الخريطة لإضافة نقاط)</button><button type="button" class="btn sec sm" id="clearpts">مسح النقاط</button></form><?php endif; ?>
<div class="tw" style="margin-top:10px"><?= $routesTable ?></div></div>
<div class="grid g2" style="margin-top:14px"><div class="card"><h2>سجل المركبات</h2><div class="tw"><?= $vhTable ?></div></div><div class="card"><h2>رسائل SMS (تنبيه فقط)</h2><div class="tw"><?= $smsTable ?></div></div></div>
