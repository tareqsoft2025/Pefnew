<div class="grid g4"><div class="card kpi"><b><?= number_format($t['trip']['distance_gps_m']/1000,1) ?></b><span>كم (GPS)</span></div>
<div class="card kpi"><b><?= count($t['points']) ?></b><span>نقطة GPS</span></div><div class="card kpi"><b><?= count($t['stops']) ?></b><span>توقف</span></div><div class="card kpi"><b><?= count($t['deviations']) ?></b><span>انحراف</span></div></div>
<div id="map" class="map" style="margin:14px 0"></div>
<div class="grid g2"><div class="card"><h2>التوقفات</h2><div class="tw"><?= $stopsTable ?></div></div><div class="card"><h2>الانحرافات</h2><div class="tw"><?= $devTable ?></div></div></div>
