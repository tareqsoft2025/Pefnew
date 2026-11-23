<div class="grid g4">
<?php foreach ([['vehicles_total','إجمالي المركبات',''],['moving','تتحرك الآن',''],['stopped','متوقفة',''],['offline','دون اتصال',$k['offline']?'alert':''],['active_missions','مهام نشطة',''],['pending_missions','مهام بانتظار الرد',''],['open_sos','بلاغات SOS مفتوحة',$k['open_sos']?'alert':''],['unacked_sos','SOS غير مستلمة',$k['unacked_sos']?'alert':''],['open_deviations','انحرافات مفتوحة',$k['open_deviations']?'alert':''],['unexplained_stops','توقفات بلا تفسير',$k['unexplained_stops']?'alert':'']] as [$key,$lbl,$cls]): ?>
<div class="card kpi <?= $cls ?>"><b><?= (int)$k[$key] ?></b><span><?= e($lbl) ?></span></div>
<?php endforeach; ?>
</div>
<div class="grid g2" style="margin-top:14px">
<div class="card"><h2>بلاغات الطوارئ المفتوحة</h2><div class="tw"><?= $sos ?></div></div>
<div class="card"><h2>المهام النشطة</h2><div class="tw"><?= $missions ?></div></div>
</div>
<p class="mut">الأرقام أعلاه محسوبة من قاعدة البيانات ضمن نطاق وصولك فقط. «دون اتصال» = لم يصل أي إرسال من التطبيق خلال المهلة المحددة، وآخر موقع معروف يُعرض بوقته في صفحة الخريطة.</p>
