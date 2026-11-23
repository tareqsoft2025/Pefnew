(function(){
  var b=document.querySelector('.menu-btn');if(b)b.addEventListener('click',function(){var o=document.body.classList.toggle('nav-open');b.setAttribute('aria-expanded',o)});
  // خطوات النموذج المتعدد
  document.querySelectorAll('[data-steps]').forEach(function(root){
    var btns=root.querySelectorAll('.steps button'),panes=root.querySelectorAll('.pane'),cur=0;
    function show(i){cur=i;panes.forEach(function(p,j){p.hidden=j!==i});btns.forEach(function(x,j){if(j===i)x.setAttribute('aria-current','step');else x.removeAttribute('aria-current')});
      if(i===panes.length-1&&window.fillReview)window.fillReview()}
    btns.forEach(function(x,i){x.addEventListener('click',function(){show(i)})});
    root.querySelectorAll('[data-next]').forEach(function(x){x.addEventListener('click',function(){var p=panes[cur],ok=true;p.querySelectorAll('[required]').forEach(function(f){if(!f.value){f.reportValidity();ok=false}});if(ok)show(Math.min(cur+1,panes.length-1))})});
    root.querySelectorAll('[data-prev]').forEach(function(x){x.addEventListener('click',function(){show(Math.max(cur-1,0))})});
    show(0);
  });
  document.querySelectorAll('form[data-confirm]').forEach(function(f){f.addEventListener('submit',function(e){if(!confirm(f.dataset.confirm))e.preventDefault()})});
})();
