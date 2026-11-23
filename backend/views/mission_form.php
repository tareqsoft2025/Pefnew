<form method="post" action="/missions" class="card" data-steps id="mform">
<input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<div class="steps" role="tablist"><button type="button">1. بيانات المهمة</button><button type="button">2. السائق والمركبة</button><button type="button">3. المسار والتوقفات</button><button type="button">4. مراجعة وإرسال</button></div>
<section class="pane"><div class="f">
<div><label for="title">العنوان *</label><input id="title" name="title" required maxlength="200"></div>
<div><label for="type">النوع</label><select id="type" name="type"><option value="transport">نقل</option><option value="delegation">وفد</option><option value="delivery">توصيل</option><option value="other">أخرى</option></select></div>
<div><label for="priority">الأولوية</label><select id="priority" name="priority"><?php foreach (App\Http\Labels::PRIORITY as $k => $v): ?><option value="<?= $k ?>" <?= $k==='normal'?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
<div><label for="ss">موعد البداية *</label><input id="ss" type="datetime-local" name="scheduled_start" required></div>
<div><label for="se">موعد النهاية المتوقع</label><input id="se" type="datetime-local" name="scheduled_end"></div>
<div class="w"><label for="desc">الوصف</label><textarea id="desc" name="description"></textarea></div>
<div class="w"><label for="ins">تعليمات للسائق</label><textarea id="ins" name="instructions"></textarea></div>
<div class="w"><label for="party">الوفد / الحمولة</label><input id="party" name="party_info"></div>
<div><label for="team">الفريق</label><input id="team" name="team" list="teams"><datalist id="teams"><?php foreach ($units['team'] as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist></div>
<div><label for="region">المنطقة</label><input id="region" name="region" list="regions"><datalist id="regions"><?php foreach ($units['region'] as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist></div>
<div><label for="fleet">الأسطول</label><input id="fleet" name="fleet" list="fleets"><datalist id="fleets"><?php foreach ($units['fleet'] as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist></div>
</div><div class="row" style="margin-top:14px"><button type="button" class="btn" data-next>التالي</button></div></section>
<section class="pane" hidden><div class="f">
<div><label for="driver_id">السائق</label><select id="driver_id" name="driver_id"><option value="">— غير مسند —</option><?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['full_name']) ?></option><?php endforeach; ?></select></div>
<div><label for="vehicle_id">المركبة</label><select id="vehicle_id" name="vehicle_id"><option value="">— غير مسندة —</option><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['internal_no'] . ' · ' . $v['plate_no']) ?></option><?php endforeach; ?></select></div>
<div class="w"><label for="cx">استثناء تعارض المركبة (اختياري، يُوثق ويُدقق)</label><input id="cx" name="conflict_exception_reason" placeholder="يملأ فقط إذا ظهر تعارض مع سائق آخر"></div>
</div><p class="mut">يتحقق الخادم من تعارض المركبة مع سائق آخر في الفترة نفسها ولا يسمح إلا باستثناء موثق.</p>
<div class="row" style="margin-top:14px"><button type="button" class="btn sec" data-prev>السابق</button><button type="button" class="btn" data-next>التالي</button></div></section>
<section class="pane" hidden>
<div class="f"><div><label for="on">اسم نقطة البداية</label><input id="on" name="origin_name"></div><div><label for="ola">خط عرض البداية</label><input id="ola" name="origin_lat" inputmode="decimal"></div><div><label for="olo">خط طول البداية</label><input id="olo" name="origin_lon" inputmode="decimal"></div>
<div><label for="dn">اسم الوجهة</label><input id="dn" name="dest_name"></div><div><label for="dla">خط عرض الوجهة</label><input id="dla" name="dest_lat" inputmode="decimal"></div><div><label for="dlo">خط طول الوجهة</label><input id="dlo" name="dest_lon" inputmode="decimal"></div>
<div><label for="rad">نصف قطر الوصول (م)</label><input id="rad" name="arrival_radius_m" type="number" min="20" value="150"></div></div>
<p class="row"><button type="button" class="btn sec sm" id="pick-o">اختيار البداية من الخريطة</button><button type="button" class="btn sec sm" id="pick-d">اختيار الوجهة</button><button type="button" class="btn sec sm" id="pick-s">إضافة نقطة توقف</button></p>
<div id="map" class="map" style="height:340px;margin-bottom:12px"></div>
<h2>نقاط التوقف</h2><div id="stops"></div>
<div class="f" style="margin-top:12px"><div><label for="proof">سياسة الإثبات</label><select id="proof" name="proof_policy"><option value="optional">اختيارية (صور وملاحظات)</option><option value="photo_required">صورة إلزامية</option></select></div>
<div><label for="oph">صورة العداد</label><select id="oph" name="odometer_photo_required"><option value="0">اختيارية</option><option value="1">إلزامية لهذه المهمة</option></select></div>
<div><label><input type="checkbox" name="auto_route" value="1" style="width:auto;min-height:0"> اقتراح مسار آلي بعد الحفظ</label></div></div>
<div class="row" style="margin-top:14px"><button type="button" class="btn sec" data-prev>السابق</button><button type="button" class="btn" data-next>التالي</button></div></section>
<section class="pane" hidden><h2>المراجعة</h2><div id="review" class="mut"></div>
<p><label><input type="checkbox" name="send_now" value="1" style="width:auto;min-height:0"> إرسال المهمة للسائق فور الحفظ (يتطلب سائقًا ومركبة)</label></p>
<div class="row"><button type="button" class="btn sec" data-prev>السابق</button><button class="btn">حفظ المهمة</button></div></section>
</form>
