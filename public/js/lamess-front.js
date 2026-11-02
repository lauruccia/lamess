
(function(){
  var mb=document.getElementById('menuBtn'),nav=document.getElementById('nav');
  if(mb&&nav){
    mb.addEventListener('click',function(){var o=nav.classList.toggle('open');mb.setAttribute('aria-expanded',o)});
    nav.addEventListener('click',function(e){if(e.target.tagName==='A'){nav.classList.remove('open');mb.setAttribute('aria-expanded','false')}});
  }
  // orologio Stop Time (solo home)
  var clock=document.getElementById('clock');
  if(clock){
    var g=document.getElementById('ticks'),s='';
    for(var i=0;i<60;i++){var big=i%5===0,a=i*6*Math.PI/180,r1=big?160:168,r2=178;
      s+='<line class="tick'+(big?' big':'')+'" x1="'+(200+Math.sin(a)*r1)+'" y1="'+(200-Math.cos(a)*r1)+'" x2="'+(200+Math.sin(a)*r2)+'" y2="'+(200-Math.cos(a)*r2)+'"/>';}
    g.innerHTML=s;
    var btn=document.getElementById('stopBtn'),st=document.getElementById('stateText'),lab=document.getElementById('clockLabel'),
        H=document.getElementById('hourH'),M=document.getElementById('minH'),S=document.getElementById('secH');
    var stopped=window.matchMedia('(prefers-reduced-motion: reduce)').matches,frozen=null,raf,txtRun=st.textContent;
    var txtStop='Il tempo è fermo. Preparati una bustina, scioglila in acqua calda o fredda e prenditi cinque minuti: è questo il momento Lamèss.';
    function paint(d){var sec=d.getSeconds()+d.getMilliseconds()/1000,min=d.getMinutes()+sec/60,h=(d.getHours()%12)+min/60;
      S.style.transform='rotate('+(sec*6)+'deg)';M.style.transform='rotate('+(min*6)+'deg)';H.style.transform='rotate('+(h*30)+'deg)'}
    function loop(){paint(new Date());raf=requestAnimationFrame(loop)}
    function setState(){clock.classList.toggle('stopped',stopped);btn.textContent=stopped?'Fai ripartire il tempo':'Ferma il tempo';
      st.textContent=stopped?txtStop:txtRun;lab.textContent=stopped?'STOP TIME':'LAMÈSS';
      if(stopped){cancelAnimationFrame(raf);frozen=frozen||new Date();paint(frozen)}else{frozen=null;loop()}}
    btn.addEventListener('click',function(){stopped=!stopped;setState()});setState();
  }
  // shop: ordine singolo / abbonamento
  var seg=document.querySelectorAll('.seg button');
  if(seg.length){
    var foot=document.getElementById('shopFoot'),ships=document.querySelectorAll('.ship');
    seg.forEach(function(b){b.addEventListener('click',function(){
      seg.forEach(function(x){x.setAttribute('aria-pressed',x===b)});
      var sub=b.dataset.mode==='sub';
      ships.forEach(function(el){el.textContent=sub?'Spedizione gratuita':(el.dataset.once||'Spedizione gratuita')});
      foot.textContent=sub?'Abbonamento mensile con addebito automatico. Spedizione sempre gratuita: puoi sospendere un mese, cambiare indirizzo o interrompere quando vuoi.':'Con l’ordine singolo la spedizione costa 9 € sulla box singola. Dalle 2 box è gratuita.';
    })});
  }
  // FAQ: ricerca e categorie
  var q=document.getElementById('faqSearch');
  if(q){
    var chips=document.querySelectorAll('.chips button'),items=document.querySelectorAll('.faqgroup details'),groups=document.querySelectorAll('.faqgroup'),nores=document.getElementById('nores'),cat='all';
    function apply(){
      var t=q.value.trim().toLowerCase(),n=0;
      items.forEach(function(d){
        var ok=(cat==='all'||d.parentNode.dataset.cat===cat)&&(!t||d.textContent.toLowerCase().indexOf(t)>-1);
        d.hidden=!ok;if(ok)n++;
      });
      groups.forEach(function(g){g.hidden=!g.querySelector('details:not([hidden])')});
      nores.hidden=n>0;
    }
    q.addEventListener('input',apply);
    chips.forEach(function(c){c.addEventListener('click',function(){
      chips.forEach(function(x){x.setAttribute('aria-pressed',x===c)});cat=c.dataset.cat;apply();
    })});
    if(location.hash){var t=document.querySelector(location.hash);if(t&&t.tagName==='DETAILS')t.open=true}
  }
  // contatti
  var f=document.getElementById('contactForm');
  if(f){
    f.addEventListener('submit',function(e){
      e.preventDefault();
      var ok=true,out=document.getElementById('formMsg');
      ['cName','cMail','cMsg'].forEach(function(id){
        var el=document.getElementById(id),er=document.getElementById(id+'Err'),bad=!el.value.trim()||(id==='cMail'&&!/^\S+@\S+\.\S+$/.test(el.value));
        er.textContent=bad?(id==='cMail'?'Inserisci un indirizzo email valido.':'Compila questo campo.'):'';ok=ok&&!bad;
      });
      if(!document.getElementById('cPriv').checked){document.getElementById('cPrivErr').textContent='Per inviare serve accettare l’informativa privacy.';ok=false}else document.getElementById('cPrivErr').textContent='';
      out.hidden=false;
      out.textContent=ok?'Modulo compilato correttamente. Questo è un prototipo: il messaggio non viene ancora inviato.':'Controlla i campi evidenziati.';
    });
  }
})();
