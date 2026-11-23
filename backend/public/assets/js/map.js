/* خرائط الإدارة: Leaflet مستضاف محليًا. لا يُعرض موقع قديم كمباشر. */
window.FleetMap={
  base:(function(){var m=document.querySelector('meta[name="app-base"]');return m?m.getAttribute('content'):''})(),
  init:function(id,tileUrl){var m=L.map(id,{zoomControl:true}).setView([24.7136,46.6753],6);
    L.tileLayer(tileUrl||'https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(m);return m},
  ago:function(s){if(s==null)return 'غير معروف';if(s<60)return 'قبل '+s+' ث';if(s<3600)return 'قبل '+Math.floor(s/60)+' د';return 'قبل '+Math.floor(s/3600)+' س'},
  esc:function(t){var d=document.createElement('div');d.textContent=t==null?'':t;return d.innerHTML},
  live:function(map,listEl,csrf){
    var markers={},fitted=false;
    function icon(v){var c=!v.is_live?'#8a94a6':(v.motion==='stopped'?'#b26a00':'#157347');
      return L.divIcon({className:'',html:'<div style="width:22px;height:22px;border-radius:50%;background:'+c+';border:3px solid #fff;box-shadow:0 0 0 2px '+c+(v.is_live?'':';opacity:.7')+'"></div>',iconSize:[22,22],iconAnchor:[11,11]})}
    function render(data){
      listEl.innerHTML='';
      if(!data.length){listEl.innerHTML='<div class="empty">لا توجد رحلات نشطة حاليًا</div>'}
      var seen={};
      data.forEach(function(v){
        seen[v.trip_id]=1;
        var label=(v.is_live?'مباشر':'آخر موقع معروف')+' · '+FleetMap.ago(v.position_age_s);
        if(v.lat!=null){
          var html='<b>'+FleetMap.esc(v.driver_name)+'</b><br>'+(v.vehicle?FleetMap.esc(v.vehicle.plate_no)+'<br>':'')+label+'<br><a href="'+FleetMap.base+'/trips/'+v.trip_id+'">سجل الرحلة</a>';
          if(markers[v.trip_id]){markers[v.trip_id].setLatLng([v.lat,v.lon]).setIcon(icon(v)).setPopupContent(html)}
          else markers[v.trip_id]=L.marker([v.lat,v.lon],{icon:icon(v)}).addTo(map).bindPopup(html);
        }
        var el=document.createElement('div');el.className='vitem'+(v.is_live?'':' stale');el.tabIndex=0;
        el.innerHTML='<div class="row"><b>'+FleetMap.esc(v.driver_name)+'</b><span class="sp"></span><span class="badge '+(v.is_live?'b-ok':'b-mut')+'">'+(v.connection==='online'?(v.motion==='stopped'?'متوقف':'يتحرك'):'دون اتصال')+'</span></div>'+
          '<div class="mut">'+(v.vehicle?FleetMap.esc(v.vehicle.plate_no)+' · ':'')+v.active_missions+' مهمة نشطة</div><div class="mut">'+label+'</div>'+(v.open_deviation?'<div class="err">منحرف عن المسار</div>':'');
        function go(){if(v.lat!=null){map.setView([v.lat,v.lon],15);markers[v.trip_id].openPopup()}}
        el.addEventListener('click',go);el.addEventListener('keydown',function(e){if(e.key==='Enter')go()});listEl.appendChild(el);
      });
      Object.keys(markers).forEach(function(k){if(!seen[k]){map.removeLayer(markers[k]);delete markers[k]}});
      if(!fitted&&data.some(function(v){return v.lat!=null})){map.fitBounds(data.filter(function(v){return v.lat!=null}).map(function(v){return [v.lat,v.lon]}),{maxZoom:14,padding:[30,30]});fitted=true}
    }
    function tick(){fetch(FleetMap.base+'/api/v1/admin/live',{credentials:'same-origin'}).then(function(r){if(!r.ok)throw 0;return r.json()}).then(function(j){render(j.data);document.getElementById('live-status').textContent='آخر تحديث للصفحة: '+new Date().toLocaleTimeString('ar')}).catch(function(){document.getElementById('live-status').textContent='تعذر التحديث — البيانات المعروضة قد تكون قديمة'})}
    tick();return setInterval(tick,10000);
  },
  track:function(map,t){
    var pts=t.points.map(function(p){return [p[0],p[1]]});
    if(pts.length){var pl=L.polyline(pts,{color:'#1d4a85',weight:4}).addTo(map);map.fitBounds(pl.getBounds(),{padding:[20,20]});
      L.circleMarker(pts[0],{color:'#157347',radius:7}).addTo(map).bindPopup('البداية');L.circleMarker(pts[pts.length-1],{color:'#b42318',radius:7}).addTo(map).bindPopup('آخر نقطة')}
    (t.approved_routes||[]).forEach(function(r){L.polyline(r,{color:'#157347',weight:3,dashArray:'6 6'}).addTo(map)});
    t.stops.forEach(function(s){L.marker([s.lat,s.lon]).addTo(map).bindPopup('توقف '+Math.round(s.duration_s/60)+' د'+(s.reason?' — '+FleetMap.esc(s.reason):' — دون تفسير'))});
    t.deviations.forEach(function(d){L.circle([d.lat,d.lon],{color:'#b42318',radius:60}).addTo(map).bindPopup('انحراف '+d.max_distance_m+' م')});
  }
};
