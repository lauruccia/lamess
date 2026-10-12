/* Home Lamèss — animazione dell'hero (tazza WebGL + pulviscolo dorato), ripresa dalla pagina «coming soon». */
(function(){
  const reduce=matchMedia('(prefers-reduced-motion: reduce)').matches;
  const hero=document.querySelector('.hero'),space=document.getElementById('cupSpace');
  const mq=matchMedia('(max-width:900px)');
  let mx=.5,my=.5,tx=.5,ty=.5;
  addEventListener('pointermove',e=>{tx=e.clientX/innerWidth;ty=e.clientY/innerHeight},{passive:true});

  const cv=document.getElementById('silk');
  const gl=cv.getContext('webgl',{antialias:false,alpha:false,powerPreference:'low-power'});
  let cup={x:0,y:0,r:100};
  function layout(){
    const hw=hero.clientWidth,hh=hero.clientHeight;
    if(mq.matches){const r=space.getBoundingClientRect(),h=hero.getBoundingClientRect();
      cup={x:hw/2,y:r.top-h.top+r.height/2,r:r.height/2*.98}}
    else cup={x:hw*.72,y:hh*.52,r:Math.min(hh*.34,hw*.21)};
  }
  if(gl){
    const vs='attribute vec2 p;void main(){gl_Position=vec4(p,0.,1.);}';
    const fs=`
#ifdef GL_FRAGMENT_PRECISION_HIGH
precision highp float;
#else
precision mediump float;
#endif
uniform vec2 R;uniform float T;uniform vec2 M;uniform vec3 C;
float h(vec2 p){p=fract(p*vec2(.1031,.1030));p+=dot(p,p.yx+33.33);return fract((p.x+p.y)*p.x);}
float n(vec2 p){vec2 i=floor(p),f=fract(p);f=f*f*(3.-2.*f);
  return mix(mix(h(i),h(i+vec2(1,0)),f.x),mix(h(i+vec2(0,1)),h(i+vec2(1,1)),f.x),f.y);}
float fbm(vec2 p){float a=.5,s=0.;for(int i=0;i<4;i++){s+=a*n(p);p=mat2(1.6,1.2,-1.2,1.6)*p;a*=.5;}return s;}
float seg(vec2 p,vec2 a,vec2 b){vec2 pa=p-a,ba=b-a;float t=clamp(dot(pa,ba)/dot(ba,ba),0.,1.);return length(pa-ba*t);}
void main(){
  vec2 fc=gl_FragCoord.xy;
  vec2 ctr=C.xy+(M-.5)*C.z*vec2(-.05,.05);
  float S=C.z;vec2 d=(fc-ctr)/S;float px=1.3/S;
  float r=length(d);
  // --- marmo scuro
  vec2 bp=fc/min(R.x,R.y)*2.;
  float v=fbm(bp*1.3+vec2(T*.004,0.));
  float vein=pow(1.-abs(2.*fbm(bp*1.1+v*1.9+7.)-1.),11.);
  vec3 col=mix(vec3(.045,.033,.028),vec3(.115,.083,.066),v);
  col+=vec3(.86,.62,.36)*vein*.20;
  col+=vec3(.55,.30,.14)*exp(-dot(d,d)*.42)*.38;
  // --- chicchi
  for(int i=0;i<9;i++){
    float fi=float(i);
    float ang=-1.95+fi*.46+sin(fi*3.7)*.16;
    float rad=1.18+.22*fract(sin(fi*12.9)*437.5);
    vec2 pos=vec2(cos(ang),sin(ang))*rad;
    float rot=fi*1.9+.4;vec2 q=d-pos;
    float cs=cos(rot),sn=sin(rot);q=vec2(cs*q.x+sn*q.y,-sn*q.x+cs*q.y);
    vec2 sq=q/vec2(.125,.082);float e=length(sq);
    // ombra
    vec2 qs=q-vec2(.02,-.03);col*=1.-.55*smoothstep(1.25,.5,length(qs/vec2(.125,.082)));
    float m=smoothstep(1.+px*8.,1.-px*8.,e);
    float shade=clamp(.5+.5*(-sq.x*.35+sq.y*.6),0.,1.);
    vec3 bc=mix(vec3(.10,.055,.035),vec3(.36,.2,.11),shade*(1.-e*.35));
    float crease=smoothstep(.1,.0,abs(q.y-.016*sin(q.x*26.))-.004)*smoothstep(1.,.55,e);
    bc*=1.-.8*crease;
    bc+=vec3(1.,.85,.6)*pow(max(0.,shade-.55),2.)*.35*(1.-crease);
    col=mix(col,bc,m);
  }
  // --- ombra piattino
  col*=1.-.78*smoothstep(1.2,.92,length(d-vec2(.05,-.09)));
  // --- piattino
  float ms=smoothstep(1.+px,1.-px,r);
  vec3 sc=vec3(.95,.92,.88)*(.88+.12*(-d.x*.4+d.y*.6));
  sc*=1.-.12*smoothstep(.95,1.,r);
  sc*=1.-.10*smoothstep(.78,.62,r)*0.;
  sc=mix(sc,vec3(.76,.40,.23),smoothstep(.010,.0,abs(r-.955)-.003));
  // marchio LAMESS sul piattino: quadrato terracotta con la L
  vec2 lq=(d-vec2(-.04,-.80))/.075;
  float sqd=max(abs(lq.x),abs(lq.y));
  float frame=smoothstep(.07,.0,abs(sqd-.95)-.06);
  float lv=step(abs(lq.x+.12),.075)*step(abs(lq.y-.02),.52);
  float lh=step(abs(lq.x-.12),.30)*step(abs(lq.y+.40),.11);
  float inner=step(sqd,1.0);
  sc=mix(sc,vec3(.99,.97,.95),inner*.85);
  sc=mix(sc,vec3(.76,.40,.23),frame*inner);
  sc=mix(sc,vec3(.10,.08,.07),max(lv,lh)*inner*step(sqd,.82));
  col=mix(col,sc,ms);
  // --- manico (ombra + corpo)
  vec2 ha=vec2(.56,-.03),hb=vec2(.90,-.12);
  float hd=seg(d,ha,hb);
  col=mix(col,col*.55,smoothstep(.14,.05,seg(d-vec2(.025,-.04),ha,hb))*ms);
  float mh=smoothstep(.058+px,.058-px,hd);
  vec2 hv=normalize(hb-ha);float side=dot(d-ha,vec2(-hv.y,hv.x))/.058;
  vec3 hc=vec3(.96,.93,.89)*(.78+.22*clamp(side*.5+.5,0.,1.));
  hc=mix(hc,vec3(.82,.62,.36),smoothstep(.12,.0,abs(abs(side)-.55)-.02)*.0);
  col=mix(col,hc,mh);
  // --- ombra tazza
  col*=1.-.4*smoothstep(.80,.63,length(d-vec2(.035,-.055)))*(1.-smoothstep(.64+px,.64-px,r)*1.);
  // --- tazza: bordo
  float mc=smoothstep(.64+px,.64-px,r);
  vec3 rim=vec3(.99,.97,.94)*(.9+.1*clamp(dot(normalize(d+1e-4),vec2(-.6,.8)),-1.,1.));
  rim=mix(rim,vec3(.76,.40,.23)*(.9+.12*clamp(dot(normalize(d+1e-4),vec2(-.6,.8)),-1.,1.)),smoothstep(.012,.0,abs(r-.6125)-.0225));
  rim=mix(rim,vec3(.99,.97,.94),smoothstep(.006,.0,abs(r-.585)-.002)*.8);
  rim+=vec3(.06)*pow(max(0.,dot(normalize(d+1e-4),vec2(-.65,.76))),8.)*smoothstep(.54,.6,r);
  col=mix(col,rim,mc);
  // --- parete interna
  float mw=smoothstep(.535+px,.535-px,r);
  vec3 wall=mix(vec3(.62,.50,.42),vec3(.93,.88,.82),smoothstep(.45,.535,r));
  wall*=.78+.3*clamp(dot(normalize(d+1e-4),vec2(.5,-.8))*.5+.5,0.,1.);
  col=mix(col,wall,mw);
  // --- caffe': espresso con crema naturale
  float mf=smoothstep(.5+px,.5-px,r);
  float rr=r/.5;float ang=atan(d.y,d.x);
  float tw=ang+T*.02+rr*1.6;
  vec2 pc=vec2(cos(tw),sin(tw))*(.6+rr*1.9);
  float f1=fbm(pc*1.6+vec2(2.,7.));
  float f2=fbm(pc*3.4+2.3*f1);
  float flame=fbm(vec2(cos(ang),sin(ang))*2.1+vec2(rr*2.8+f1*1.2,T*.015)+4.);   // venature radiali della crema
  vec3 dark=vec3(.14,.055,.026),mid=vec3(.40,.20,.085),gold=vec3(.66,.38,.15);
  vec3 cof=mix(dark,mid,smoothstep(.15,.65,f1*.6+flame*.6));
  cof=mix(cof,gold,smoothstep(.55,.95,f2*.5+flame*.55+rr*.18)*.75);
  cof=mix(cof,dark*.8,smoothstep(.0,.35,.35-rr)*.55);           // centro piu' scuro
  cof=mix(cof,vec3(.09,.04,.02),smoothstep(.86,1.,rr)*.9);       // contatto con la parete
  cof=mix(cof,vec3(.50,.28,.11),smoothstep(.60,.80,rr)*smoothstep(1.,.85,rr)*.35); // anello di crema
  // bollicine
  float bub=smoothstep(.80,.9,n(pc*34.+5.))*smoothstep(.45,.9,rr)*smoothstep(1.,.9,rr);
  cof+=vec3(.55,.34,.15)*bub*.45;
  cof*=.97+.05*n(pc*60.);
  // riflesso morbido (finestra) e lucidita'
  vec2 g=(d-vec2(-.17,.2));
  cof+=vec3(1.,.82,.6)*smoothstep(.55,.0,length(g*vec2(1.2,2.8)))*.11;
  cof+=vec3(.9,.6,.3)*pow(max(0.,dot(normalize(d+1e-4),vec2(-.55,.83))),10.)*smoothstep(.3,.5,r)*.10;
  col=mix(col,cof,mf);
  // --- cucchiaino sul piattino
  {
    vec2 sa=vec2(-.74,-.40),sb=vec2(-.47,-.69);
    vec2 bc=sa;float cs2=cos(.85),sn2=sin(.85);vec2 qb=d-bc;qb=vec2(cs2*qb.x+sn2*qb.y,-sn2*qb.x+cs2*qb.y);
    float bowl=length(qb/vec2(.115,.072));
    float stem=seg(d,sa+vec2(.06,-.06),sb)-.017;
    float sm=max(smoothstep(1.+px*6.,1.-px*6.,bowl),smoothstep(px*2.,-px*2.,stem));
    col*=1.-.5*max(smoothstep(1.3,.6,length((qb-vec2(.015,-.02))/vec2(.115,.072))),smoothstep(.04,.0,seg(d-vec2(.012,-.018),sa+vec2(.06,-.06),sb)-.015));
    float sh2=clamp(.5+.5*(-qb.x*5.+qb.y*7.),0.,1.);
    vec3 steel=mix(vec3(.45,.45,.47),vec3(.95,.95,.96),sh2);
    steel=mix(steel,vec3(.62,.55,.50),smoothstep(.9,.2,bowl)*.35);
    col=mix(col,steel,sm);
  }
  // --- vapore
  {
    float up=d.y;
    vec2 u=vec2(d.x*1.7+.35*sin(d.y*2.6+T*.35),d.y*1.15-T*.14);
    float w1=fbm(u*2.0+vec2(1.,T*.02));
    float w2=fbm(u*4.1+vec2(7.,3.)+w1*1.4);
    float wisp=smoothstep(.34,.78,w1*.65+w2*.45);
    float mask=smoothstep(-.35,.3,up)*smoothstep(1.55,.15,up)*exp(-pow(d.x-.04*up,2.)*1.5);
    col=mix(col,vec3(.97,.93,.88),clamp(wisp*mask*.62,0.,.75));
  }
  // luce calda esterna
  col+=vec3(.9,.6,.32)*smoothstep(.3,.0,abs(r-.52))*0.;
  col=pow(col,vec3(.96));
  gl_FragColor=vec4(col,1.);}`;
    const sh=(t,s)=>{const o=gl.createShader(t);gl.shaderSource(o,s);gl.compileShader(o);if(!gl.getShaderParameter(o,gl.COMPILE_STATUS))console.warn(gl.getShaderInfoLog(o));return o};
    const pr=gl.createProgram();gl.attachShader(pr,sh(gl.VERTEX_SHADER,vs));gl.attachShader(pr,sh(gl.FRAGMENT_SHADER,fs));gl.linkProgram(pr);
    if(gl.getProgramParameter(pr,gl.LINK_STATUS)){
      gl.useProgram(pr);
      const b=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,b);
      gl.bufferData(gl.ARRAY_BUFFER,new Float32Array([-1,-1,3,-1,-1,3]),gl.STATIC_DRAW);
      const loc=gl.getAttribLocation(pr,'p');gl.enableVertexAttribArray(loc);gl.vertexAttribPointer(loc,2,gl.FLOAT,false,0,0);
      const uR=gl.getUniformLocation(pr,'R'),uT=gl.getUniformLocation(pr,'T'),uM=gl.getUniformLocation(pr,'M'),uC=gl.getUniformLocation(pr,'C');
      const coarse=matchMedia('(pointer:coarse)').matches;
      const dpr=Math.min(devicePixelRatio||1,coarse?1.5:2)*(coarse?.85:1);
      function size(){layout();cv.width=Math.max(2,Math.round(hero.clientWidth*dpr));cv.height=Math.max(2,Math.round(hero.clientHeight*dpr));gl.viewport(0,0,cv.width,cv.height);draw(performance.now())}
      let t0=performance.now(),vis=true;
      function draw(now){
        mx+=(tx-mx)*.05;my+=(ty-my)*.05;
        gl.uniform2f(uR,cv.width,cv.height);gl.uniform1f(uT,reduce?20:(now-t0)/1000+20);gl.uniform2f(uM,mx,1-my);
        gl.uniform3f(uC,cup.x*dpr,cv.height-cup.y*dpr,cup.r*dpr);
        gl.drawArrays(gl.TRIANGLES,0,3);}
      new IntersectionObserver(e=>vis=e[0].isIntersecting).observe(hero);
      addEventListener('resize',size);
      if('ResizeObserver' in window)new ResizeObserver(size).observe(hero);
      if(document.fonts&&document.fonts.ready)document.fonts.ready.then(size);
      size();
      (function frame(now){if(vis)draw(now);if(!reduce)requestAnimationFrame(frame)})(performance.now());
    }
  }

  // pulviscolo dorato
  const dc=document.getElementById('dust'),ctx=dc.getContext('2d');
  let W,Hh,parts=[];
  function dsize(){const d=Math.min(devicePixelRatio||1,2);W=dc.width=hero.clientWidth*d;Hh=dc.height=hero.clientHeight*d;
    const N=Math.round(Math.min(60,hero.clientWidth/16));
    parts=Array.from({length:N},()=>({x:Math.random()*W,y:Math.random()*Hh,r:(Math.random()*2.4+.6)*d,v:(Math.random()*.25+.06)*d,s:Math.random()*Math.PI*2,a:Math.random()*.55+.2}))}
  dsize();addEventListener('resize',dsize);
  function dust(now){
    ctx.clearRect(0,0,W,Hh);
    for(const p of parts){
      p.y-=p.v;p.x+=Math.sin(now/2600+p.s)*.3;
      if(p.y<-10){p.y=Hh+10;p.x=Math.random()*W}
      const tw=.6+.4*Math.sin(now/900+p.s);
      const g=ctx.createRadialGradient(p.x,p.y,0,p.x,p.y,p.r*4);
      g.addColorStop(0,`rgba(255,226,180,${p.a*tw})`);g.addColorStop(1,'rgba(255,200,140,0)');
      ctx.fillStyle=g;ctx.beginPath();ctx.arc(p.x,p.y,p.r*4,0,7);ctx.fill();
    }
    if(!reduce)requestAnimationFrame(dust)}
  requestAnimationFrame(dust);
})();

/* Intestazione che diventa piena allo scorrimento + comparsa graduale delle sezioni */
(function(){
  const h=document.querySelector('.site-header');
  if(h){const f=()=>h.classList.toggle('solid',scrollY>24);f();addEventListener('scroll',f,{passive:true});}
  document.documentElement.classList.add('js');
  const els=document.querySelectorAll('.reveal');
  if(!('IntersectionObserver' in window)){els.forEach(e=>e.classList.add('in'));return;}
  const io=new IntersectionObserver(es=>es.forEach(x=>{if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target)}}),{threshold:.12});
  els.forEach(e=>io.observe(e));
})();
