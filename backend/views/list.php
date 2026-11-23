<?php if (!empty($filters)): ?><form method="get" class="card noprint" style="margin-bottom:14px"><div class="f"><?= $filters ?><div><button class="btn">تطبيق</button></div></div></form><?php endif; ?>
<?php if (!empty($actions)): ?><div class="row" style="margin-bottom:12px"><?= $actions ?></div><?php endif; ?>
<?php if (!empty($extra)) echo $extra; ?>
<div class="card"><div class="tw"><?= $table ?></div>
<?php if (!empty($meta) && $meta['pages'] > 1): ?><div class="row" style="margin-top:12px"><?php for ($i = 1; $i <= $meta['pages']; $i++): $qs = http_build_query(array_merge($_GET ?? [], ['page' => $i])); ?><a class="btn sm <?= $i === $meta['page'] ? '' : 'sec' ?>" href="?<?= e($qs) ?>"><?= $i ?></a><?php endfor; ?><span class="mut">الإجمالي: <?= (int)$meta['total'] ?></span></div><?php endif; ?></div>
