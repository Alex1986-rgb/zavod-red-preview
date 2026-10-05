/* Страницы типов: блок «Типоразмеры и модели» выводил все 30 карточек сразу —
   на телефоне это ~15 000px. Показываем первые 6, остальные по кнопке. */
(function(){
  var wrap=document.querySelector('.catcard-wrap .pcard-grid'); if(!wrap) return;
  var cards=wrap.querySelectorAll('.pcard'); if(cards.length<=6) return;
  wrap.classList.add('is-collapsed');
  var b=document.createElement('button'); b.type='button'; b.className='btn ghost catcard-more';
  b.textContent='Показать все '+cards.length+' типоразмеров';
  b.addEventListener('click',function(){ wrap.classList.remove('is-collapsed'); b.remove(); });
  wrap.parentNode.insertBefore(b,wrap.nextSibling);
})();
