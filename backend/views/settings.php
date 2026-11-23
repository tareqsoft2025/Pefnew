<form method="post" action="/settings" class="card"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><div class="f">
<?php foreach ($defs as $k => $label): ?><div><label for="s_<?= e($k) ?>"><?= e($label[0]) ?> <span class="mut">(<?= e($k) ?>)</span></label>
<?php if ($label[1] === 'bool'): ?><select id="s_<?= e($k) ?>" name="s[<?= e($k) ?>]"><option value="0" <?= $vals[$k]==='0'?'selected':'' ?>>معطّل</option><option value="1" <?= $vals[$k]==='1'?'selected':'' ?>>مفعّل</option></select>
<?php else: ?><input id="s_<?= e($k) ?>" name="s[<?= e($k) ?>]" value="<?= e($vals[$k]) ?>"><?php endif; ?></div><?php endforeach; ?>
<div class="w"><button class="btn">حفظ الإعدادات</button></div></div></form>
<div class="card" style="margin-top:14px"><h2>حالة التكاملات</h2><div class="tw"><?= $integrations ?></div><p class="mut">القيم الحساسة (مفاتيح SMS وFirebase) تُضبط في ملف <code>.env</code> على الخادم وليست في الواجهة.</p></div>
