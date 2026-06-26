/* Калькулятор подбора редуктора — общий для главной и /podbor.html.
   Активируется при наличии #pfCalc. Считает передаточное число, момент на выходе,
   момент с сервис-фактором и выдаёт рекомендацию по типу. */
(function(){
  var btn=document.getElementById('pfCalc');
  if(!btn)return;
  var EFF={cyl:0.97,bevel:0.95,flat:0.96,worm:0.80,planet:0.97};
  var TYPE_NAME={cyl:'цилиндрический / соосный',bevel:'цилиндро-конический',flat:'плоский цилиндрический',worm:'червячный',planet:'планетарный'};
  function num(id){var el=document.getElementById(id);if(!el)return 0;var v=parseFloat((el.value||'').replace(',','.'));return isNaN(v)?0:v;}
  function fmt(x,d){return x.toLocaleString('ru-RU',{minimumFractionDigits:d,maximumFractionDigits:d});}
  function set(id,v){var el=document.getElementById(id);if(el)el.textContent=v;}
  btn.addEventListener('click',function(){
    var t=(document.getElementById('pfType')||{}).value||'cyl';
    var P=num('pfPow'), n1=num('pfIn'), n2=num('pfOut');
    var sfEl=document.getElementById('pfEff'); var sf=sfEl?parseFloat(sfEl.value):1.25;
    var res=document.getElementById('pfResult');
    if(P<=0||n1<=0||n2<=0){if(res)res.hidden=false;set('pfI','—');set('pfT','—');set('pfTs','—');set('pfRec','Заполните мощность и обороты (входные и выходные) — все значения больше нуля.');return;}
    var i=n1/n2, eff=EFF[t]||0.95, T=9550*P*eff/n2, Ts=T*sf;
    set('pfI',fmt(i,i<10?2:1)); set('pfT',fmt(T,0)); set('pfTs',fmt(Ts,0));
    var rec='Тип: '+TYPE_NAME[t]+'. Передаточное число i≈'+fmt(i,i<10?2:1)+', момент на выходе ≈'+fmt(T,0)+' Н·м (с запасом ≈'+fmt(Ts,0)+' Н·м). ';
    if(t==='worm'&&i<7) rec+='Для малого передаточного числа эффективнее цилиндрический привод. ';
    if(i>250) rec+='Большое i — потребуется 3 ступени или мотор-редуктор с предступенью. ';
    rec+='Подберём конкретный типоразмер EVL / ПР / МР под этот момент и пришлём цену.';
    set('pfRec',rec);
    if(res){res.hidden=false;res.scrollIntoView({behavior:'smooth',block:'nearest'});}
  });
})();
