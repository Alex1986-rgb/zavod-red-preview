/* Мобильное меню */
(function(){
  var burger=document.querySelector('.burger'),header=document.querySelector('header');
  if(!burger||!header)return;
  burger.addEventListener('click',function(){
    var open=header.classList.toggle('open');
    burger.setAttribute('aria-expanded',open?'true':'false');
  });
  document.querySelectorAll('.menu .drop-toggle').forEach(function(t){
    t.addEventListener('click',function(e){
      e.preventDefault();
      var dd=t.closest('.dropdown');var open=dd.classList.toggle('open');
      t.setAttribute('aria-expanded',open?'true':'false');
    });
  });
  document.querySelectorAll('.menu a:not(.drop-toggle)').forEach(function(a){
    a.addEventListener('click',function(){header.classList.remove('open');burger.setAttribute('aria-expanded','false');document.querySelectorAll('.menu .dropdown.open').forEach(function(d){d.classList.remove('open');});});
  });
  document.addEventListener('click',function(e){
    if(!e.target.closest('.menu .dropdown'))document.querySelectorAll('.menu .dropdown.open').forEach(function(d){d.classList.remove('open');});
  });
})();

/* Маска телефона + форма-заявка (email + Telegram через /api/feedback.php) */
function wireLeadForm(formId, opts){
  opts=opts||{};
  var f=document.getElementById(formId);
  if(!f)return;
  var ph=f.querySelector('input[type=tel]');
  var name=f.querySelector('input[type=text]:not([name="work_email"])');
  var consent=f.querySelector('input[type=checkbox]');
  var res=f.querySelector('.form-result');
  var action=opts.action||((/\/(catalog|cases)\//.test(location.pathname)?'../':'')+'api/feedback.php');
  function show(msg,type){res.className='form-result show'+(type?' '+type:'');res.innerHTML=msg;}
  if(ph){
    ph.addEventListener('input',function(){
      var x=this.value.replace(/\D/g,'').match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
      if(!x)return;
      if(x[1]!=='7'&&x[1]!=='8'&&x[1]!=='')x[2]=x[1]+(x[2]||'');
      x[1]='7';
      this.value=!x[2]?'+7 (':'+7 ('+x[2]+(x[3]?') '+x[3]:'')+(x[4]?'-'+x[4]:'')+(x[5]?'-'+x[5]:'');
    });
    ph.addEventListener('keydown',function(e){if(e.key==='Backspace'&&this.value.length<=4)e.preventDefault();});
    ph.addEventListener('focus',function(){if(this.value==='')this.value='+7 (';});
  }
  f.addEventListener('submit',async function(e){
    e.preventDefault();
    if(f.querySelector('input[name="work_email"]').value!=='')return;
    if(name&&name.value.trim()===''){show('Укажите, как к вам обращаться.','err');return;}
    if(ph&&ph.value.length<18){show('Введите телефон полностью: +7 (XXX) XXX-XX-XX.','err');return;}
    if(consent&&!consent.checked){show('Отметьте согласие на обработку персональных данных.','err');return;}
    var fd=new FormData();
    fd.append('work_email','');
    if(name)fd.append('text-562',name.value);
    if(ph)fd.append('tel-535',ph.value);
    fd.append('product_title',opts.title||('Заявка с сайта · '+document.title));
    show('Отправляем заявку…','');
    try{
      var r=await fetch(action,{method:'POST',body:fd});
      var d=await r.json();
      if(d.status==='success'){if(window.ym)ym(109758131,'reachGoal','zayavka');f.reset();show('Заявка принята. Инженер свяжется с вами и пришлёт коммерческое предложение.','ok');}
      else{show((d.message||'Не удалось отправить заявку.')+' Позвоните: +7 (495) 151-41-02.','err');}
    }catch(err){show('Сбой отправки. Позвоните нам: +7 (495) 151-41-02.','err');}
  });
}
