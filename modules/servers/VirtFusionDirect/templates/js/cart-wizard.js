(function(){
var C=window.vfCartConfig;if(!C)return;
var G=C.osGroups,K=C.sshKeys,B=C.baseUrl,OI=document.querySelector('[name="customfield['+C.osFieldId+']"]'),SI=C.sshFieldId?document.querySelector('[name="customfield['+C.sshFieldId+']"]'):null;
if(!OI)return;
var F=null;try{F=OI.closest('form')||document.querySelector('#orderfrm');}catch(e){}
if(!SI&&C.sshFieldId){SI=document.createElement('input');SI.type='hidden';SI.name='customfield['+C.sshFieldId+']';SI.setAttribute('data-vf-synth','1');if(F)F.appendChild(SI);else document.body.appendChild(SI);}
function fg(el){if(!el)return null;return el.closest&&(el.closest('.form-group')||el.closest('.custom-field')||el.closest('.customfield'))||el.parentElement;}
function hf(el){if(!el)return;var s=el.getAttribute&&el.getAttribute('data-vf-synth')==='1';if(s)return;var g=fg(el);if(g)g.style.display='none';}
function iu(f){var n=(f||'').toLowerCase(),m={windows:'windows_logo',ubuntu:'ubuntu_logo',almalinux:'almalinux_logo',centos:'centos_logo',debian:'debian_logo',fedora:'fedora_logo',rocky:'rocky_linux_logo',alpine:'alpine_linux_logo',freebsd:'freebsd_logo'};for(var k in m){if(n.indexOf(k)!==-1)return B+'/img/logo/'+m[k]+'.png';}return B+'/img/logo/linux_logo.png';}
function iw(l){return(l||'').toLowerCase().indexOf('windows')!==-1;}
function vp(n){var r=/\d+(?:\.\d+)*/g,m,t=(n||'').toLowerCase();while((m=r.exec(t))!==null){var i=m.index,p=i>0?t.charAt(i-1):'',v=m[0];if((p==='x'||p==='X')&&(v.indexOf('86')===0||v.indexOf('64')===0))continue;return v.split('.').map(function(x){return parseInt(x,10)||0;});}return[];}
function cv(a,b){var l=Math.max(a.length,b.length);for(var i=0;i<l;i++){var x=a[i]||0,y=b[i]||0;if(x!==y)return y-x;}return 0;}

var selFam=null,selTid=null,selWin=false,sshSel=false,pwdSel=false,genActive=false,selKeyId='';
var wm={};G.forEach(function(g){var w=iw(g.label);(g.templates||[]).forEach(function(t){wm[String(t.id)]=w;});});

function setOsId(id){selTid=(id||'').toString();OI.value=selTid;if(selTid&&oe)oe.classList.remove('vf-visible');try{OI.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}updSsh();}
function authReq(){if(!SI)return false;if(selTid&&wm[selTid])return false;return!selWin;}

// DOM
var wiz=document.createElement('div');wiz.id='vf-os-wizard';
var rb=document.createElement('div');rb.id='vf-os-rowbar';
var rl=document.createElement('span');rl.className='vf-rowbar-col vf-left';rl.textContent='Choose OS Family';
var rs=document.createElement('span');rs.className='vf-rowbar-divider';
var rr=document.createElement('span');rr.className='vf-rowbar-col vf-right';rr.textContent='Versions';
rb.appendChild(rl);rb.appendChild(rs);rb.appendChild(rr);wiz.appendChild(rb);
var fr=document.createElement('div');fr.className='vf-os-family-row';wiz.appendChild(fr);
var vw=document.createElement('div');vw.id='vf-os-versions-wrap';
var vp2=document.createElement('div');vp2.className='vf-os-version-panel';
var vl=document.createElement('div');vl.id='vf-os-versions-list';
vp2.appendChild(vl);vw.appendChild(vp2);wiz.appendChild(vw);
var oe=document.createElement('div');oe.className='vf-os-error';oe.textContent='Please select an Operating System version to continue.';
wiz.appendChild(oe);

var fbs=[];
function renderVer(idx,keep){
while(vl.firstChild)vl.removeChild(vl.firstChild);
var g=G[idx]||null,ts=(g&&Array.isArray(g.templates))?g.templates.slice():[];
ts.sort(function(a,b){var la=(a.name||'').toLowerCase().indexOf('latest')!==-1,lb=(b.name||'').toLowerCase().indexOf('latest')!==-1;if(la!==lb)return la?-1:1;var vc=cv(vp(a.name),vp(b.name));return vc!==0?vc:(a.name||'').localeCompare(b.name||'');});
vl.classList.remove('vf-grid','vf-grid-4');
if(ts.length>3){vl.classList.add('vf-grid');if(ts.length===8)vl.classList.add('vf-grid-4');}
ts.forEach(function(t){
var tid=String(t.id);if(!tid)return;
var c=document.createElement('button');c.type='button';c.className='vf-os-version-card';c.setAttribute('aria-pressed','false');c.setAttribute('data-tid',tid);
var r=document.createElement('span');r.className='vf-os-radio';
var x=document.createElement('span');x.className='vf-os-version-text';x.textContent=t.name;
c.appendChild(r);c.appendChild(x);
c.addEventListener('click',function(){selTid=tid;setOsId(tid);rb.classList.add('vf-has-version');var cs=vl.querySelectorAll('.vf-os-version-card');for(var i=0;i<cs.length;i++)cs[i].setAttribute('aria-pressed',cs[i].getAttribute('data-tid')===tid?'true':'false');});
vl.appendChild(c);});
vw.classList.add('vf-visible');
if(!keep){selTid=null;setOsId('');rb.classList.remove('vf-has-version');}}

function selFamFn(idx,keep){
selFam=idx;var g=G[idx];selWin=g?iw(g.label):false;
for(var i=0;i<fbs.length;i++)fbs[i].setAttribute('aria-pressed',fbs[i].getAttribute('data-fi')===String(idx)?'true':'false');
renderVer(idx,keep);updSsh();}

G.forEach(function(g,idx){
var ts=(g&&Array.isArray(g.templates))?g.templates:[];if(!ts.length)return;
var b=document.createElement('button');b.type='button';b.className='vf-os-family-card';b.setAttribute('aria-pressed','false');b.setAttribute('data-fi',String(idx));
var ic=document.createElement('span');ic.className='vf-os-icon';
var im=document.createElement('img');im.className='vf-os-icon-img';im.src=iu(g.label);im.alt=g.label;im.decoding='async';im.loading='lazy';
var ab=(g.label||'OS').replace(/[^a-zA-Z0-9]/g,'').substring(0,2).toUpperCase()||'OS';
im.onerror=function(){try{this.remove();}catch(e){}ic.textContent=ab;ic.style.background='linear-gradient(135deg,#64748b,#0f172a)';ic.style.color='#fff';ic.style.fontWeight='900';ic.style.fontSize='12px';};
ic.appendChild(im);var lb=document.createElement('span');lb.textContent=g.label;
b.appendChild(ic);b.appendChild(lb);
b.addEventListener('click',function(){var ci=parseInt(this.getAttribute('data-fi'),10);if(isNaN(ci)||ci===selFam)return;selFamFn(ci,false);});
fr.appendChild(b);fbs.push(b);});

hf(OI);OI.style.display='none';

// Restore selection
try{var ev=(OI.value||'').trim();if(ev){var fd=null;G.forEach(function(g,i){if(fd)return;(g.templates||[]).forEach(function(t){if(fd)return;if(String(t.id)===ev)fd={gi:i,tid:String(t.id)};});});if(fd){selFamFn(fd.gi,true);selTid=fd.tid;setOsId(fd.tid);rb.classList.add('vf-has-version');var cs=vl.querySelectorAll('.vf-os-version-card');for(var i=0;i<cs.length;i++)cs[i].setAttribute('aria-pressed',cs[i].getAttribute('data-tid')===fd.tid?'true':'false');}}}catch(e){}

// OS guard
if(F&&!F.getAttribute('data-vf-os-guard')){F.setAttribute('data-vf-os-guard','1');F.addEventListener('submit',function(e){if(selTid&&selTid.trim())return;e.preventDefault();e.stopPropagation();if(e.stopImmediatePropagation)e.stopImmediatePropagation();oe.classList.add('vf-visible');try{rb.scrollIntoView({behavior:"smooth",block:"center"});}catch(x){}},true);}

// AUTH PANEL
var ae=null,pwCard=null,sCard=null,pwBtn=null,sBtn=null,paste=null,contBtn=null,genBtn=null,pkPanel=null,pkArea=null,skbs=[];
function updSsh(){
var show=authReq();var card=document.getElementById('vf-provisioning-card');var rc=document.getElementById('vf-provisioning-right');
if(rc)rc.style.display=show?'flex':'none';
if(card){if(show){card.classList.remove('vf-ssh-hidden');if(card.clientWidth>=980)card.classList.add('vf-two-col');else card.classList.remove('vf-two-col');}else{card.classList.add('vf-ssh-hidden');card.classList.remove('vf-two-col');}}}
function syncAuth(){if(!contBtn||!genBtn)return;if(pwdSel){contBtn.disabled=true;genBtn.disabled=true;return;}var has=selKeyId||(paste&&paste.value.trim().length>0);if(genActive){contBtn.disabled=true;genBtn.disabled=false;}else{contBtn.disabled=!has;genBtn.disabled=!!has;}}
function setSsh(v){if(ae)ae.classList.remove('vf-visible');sshSel=!!v;if(SI)SI.disabled=!sshSel;if(sshSel&&pwdSel)setPwd(false);if(sshSel){sCard.classList.remove('vf-ssh-collapsed');sCard.classList.add('vf-selected');sBtn.textContent='SSH Key Selected';}else{sCard.classList.add('vf-ssh-collapsed');sCard.classList.remove('vf-selected');sBtn.textContent='Continue with SSH Key';}syncAuth();}
function setPwd(v){if(ae)ae.classList.remove('vf-visible');pwdSel=!!v;if(pwdSel){sshSel=false;selKeyId='';if(paste){paste.value='';paste.readOnly=false;}genActive=false;if(pkPanel)pkPanel.style.display='none';pwCard.classList.add('vf-selected');pwBtn.textContent='Password Selected';sCard.classList.add('vf-ssh-collapsed');sCard.classList.remove('vf-selected');sBtn.textContent='Continue with SSH Key';if(SI){SI.value='';SI.disabled=true;}if(contBtn)contBtn.disabled=true;if(genBtn)genBtn.disabled=true;}else{pwCard.classList.remove('vf-selected');pwBtn.textContent='Continue with Password';if(SI)SI.disabled=!sshSel;syncAuth();}}
function setKey(kid){setSsh(true);selKeyId=(kid||'').toString();for(var i=0;i<skbs.length;i++)skbs[i].setAttribute('aria-pressed',skbs[i].getAttribute('data-kid')===selKeyId?'true':'false');if(selKeyId){paste.value='';paste.readOnly=true;SI.value=selKeyId;}else{paste.readOnly=false;SI.value=paste.value.trim();}genActive=false;if(pkPanel)pkPanel.style.display='none';syncAuth();}

if(SI){
SI.style.display='none';SI.disabled=true;hf(SI);
var st=document.createElement('div');st.id='vf-ssh-title';st.textContent='Authentication Method';
var ss=document.createElement('div');ss.id='vf-ssh-subtitle';ss.textContent='Choose how you want to access your server.';
ae=document.createElement('div');ae.className='vf-auth-error';ae.textContent='Please select an authentication method to continue.';
var aw=document.createElement('div');aw.id='vf-auth-method';
var ag=document.createElement('div');ag.id='vf-auth-grid';

// Password card
pwCard=document.createElement('div');pwCard.className='vf-auth-option';
var pwL=document.createElement('div');pwL.className='vf-auth-left';
var pwTR=document.createElement('div');pwTR.className='vf-auth-title-row';
var pwI=document.createElement('span');pwI.className='vf-auth-icon';pwI.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>';
var pwT=document.createElement('span');pwT.className='vf-auth-title';pwT.textContent='Password Authentication';
pwTR.appendChild(pwI);pwTR.appendChild(pwT);
var pwD=document.createElement('div');pwD.className='vf-auth-desc';pwD.textContent='Quick access with password. Ideal for quick trials or remote login.';
pwL.appendChild(pwTR);pwL.appendChild(pwD);
pwBtn=document.createElement('button');pwBtn.type='button';pwBtn.className='vf-auth-cta';pwBtn.textContent='Continue with Password';
var pwC=document.createElement('div');pwC.className='vf-auth-cta-area';pwC.appendChild(pwBtn);
pwCard.appendChild(pwL);pwCard.appendChild(pwC);
pwBtn.addEventListener('click',function(){setPwd(!pwdSel);});

// SSH card
sCard=document.createElement('div');sCard.className='vf-auth-option vf-ssh-collapsed';
var sL=document.createElement('div');sL.className='vf-auth-left';
var sTR=document.createElement('div');sTR.className='vf-auth-title-row';
var sI=document.createElement('span');sI.className='vf-auth-icon';sI.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M7 14a5 5 0 1 1 3.9 4.8L9 21H7v-2H5v-2h2l1.2-1.2A5 5 0 0 1 7 14Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
var sT=document.createElement('span');sT.className='vf-auth-title';sT.textContent='SSH Key Authentication';
var sBdg=document.createElement('span');sBdg.className='vf-auth-badge';sBdg.textContent='Recommended';
sTR.appendChild(sI);sTR.appendChild(sT);sTR.appendChild(sBdg);
var sD=document.createElement('div');sD.className='vf-auth-desc';sD.textContent='Secure and password-less login using a public key.';
sL.appendChild(sTR);sL.appendChild(sD);
sBtn=document.createElement('button');sBtn.type='button';sBtn.className='vf-auth-cta';sBtn.textContent='Continue with SSH Key';
var sC=document.createElement('div');sC.className='vf-auth-cta-area';sC.appendChild(sBtn);
sBtn.addEventListener('click',function(){setSsh(!sshSel);});

// SSH details
var det=document.createElement('div');det.className='vf-ssh-details';
var hint=document.createElement('div');hint.className='vf-auth-hint';hint.textContent='Generate a new key or provide your existing public key.';
var flEx=document.createElement('div');flEx.className='vf-auth-flow';
var fxT=document.createElement('div');fxT.className='vf-auth-flow-title';fxT.textContent='Use Existing Public Key';
flEx.appendChild(fxT);

if(Array.isArray(K)&&K.length){var kO=document.createElement('div');kO.className='vf-key-options';
function akp(kid,lbl){var b=document.createElement('button');b.type='button';b.className='vf-key-option';b.setAttribute('data-kid',kid);b.setAttribute('aria-pressed','false');b.textContent=lbl;b.addEventListener('click',function(){setKey(kid);});skbs.push(b);kO.appendChild(b);}
akp('','Paste a new key');K.forEach(function(k){akp(String(k.id),k.name);});flEx.appendChild(kO);}

paste=document.createElement('textarea');paste.className='form-control';paste.id='vf-ssh-paste';paste.setAttribute('rows','3');paste.setAttribute('placeholder','ssh-rsa AAAA... or ssh-ed25519 AAAA...');
contBtn=document.createElement('button');contBtn.type='button';contBtn.id='vf-ssh-continue';contBtn.textContent='Continue with This Public Key';contBtn.disabled=true;
var fxA=document.createElement('div');fxA.className='vf-auth-actions';fxA.appendChild(contBtn);
flEx.appendChild(paste);flEx.appendChild(fxA);
paste.addEventListener('input',function(){if(paste.readOnly)return;setSsh(true);if(selKeyId){selKeyId='';for(var i=0;i<skbs.length;i++)skbs[i].setAttribute('aria-pressed','false');}SI.value=this.value.trim();genActive=false;if(pkPanel)pkPanel.style.display='none';syncAuth();});
contBtn.addEventListener('click',function(){setSsh(true);if(selKeyId)SI.value=selKeyId;else SI.value=paste.value.trim();syncAuth();});

var dv=document.createElement('div');dv.className='vf-auth-divider';dv.textContent='OR';
var flG=document.createElement('div');flG.className='vf-auth-flow';
var fgT=document.createElement('div');fgT.className='vf-auth-flow-title';fgT.textContent='Generate a New Key';
genBtn=document.createElement('button');genBtn.type='button';genBtn.id='vf-ssh-generate';genBtn.textContent='Generate New SSH Key';
var gErr=document.createElement('div');gErr.style.display='none';gErr.style.marginTop='8px';gErr.style.color='#dc3545';gErr.textContent='Browser does not support key generation. Paste your key manually.';
pkPanel=document.createElement('div');pkPanel.id='vf-privkey-panel';pkPanel.style.display='none';
var pkW=document.createElement('div');pkW.className='vf-privkey-title';pkW.textContent='Private Key - Save This Now!';
pkArea=document.createElement('textarea');pkArea.className='form-control';pkArea.setAttribute('rows','6');pkArea.setAttribute('readonly','readonly');
var pkBs=document.createElement('div');pkBs.className='vf-privkey-actions';
var dlB=document.createElement('button');dlB.type='button';dlB.className='btn btn-primary btn-sm';dlB.textContent='Download';
var cpB=document.createElement('button');cpB.type='button';cpB.className='btn btn-default btn-sm';cpB.textContent='Copy';
pkBs.appendChild(dlB);pkBs.appendChild(cpB);pkPanel.appendChild(pkW);pkPanel.appendChild(pkArea);pkPanel.appendChild(pkBs);
dlB.addEventListener('click',function(){var bl=new Blob([pkArea.value],{type:'text/plain'}),a=document.createElement('a');a.href=URL.createObjectURL(bl);a.download='id_ed25519';document.body.appendChild(a);a.click();document.body.removeChild(a);});
cpB.addEventListener('click',function(){navigator.clipboard.writeText(pkArea.value).then(function(){cpB.textContent='Copied!';setTimeout(function(){cpB.textContent='Copy';},2000);});});
flG.appendChild(fgT);flG.appendChild(genBtn);flG.appendChild(gErr);flG.appendChild(pkPanel);
genBtn.addEventListener('click',async function(){setSsh(true);genActive=true;if(contBtn)contBtn.disabled=true;genBtn.disabled=true;genBtn.textContent='Generating...';try{if(skbs.length){selKeyId='';for(var i=0;i<skbs.length;i++)skbs[i].setAttribute('aria-pressed','false');}paste.readOnly=false;gErr.style.display='none';pkPanel.style.display='none';var keys=await vfGenerateSSHKey();paste.value=keys.publicKey;SI.value=keys.publicKey;pkArea.value=keys.privateKey;pkPanel.style.display='block';gErr.style.display='none';}catch(e){gErr.style.display='block';pkPanel.style.display='none';genActive=false;}finally{genBtn.disabled=false;genBtn.textContent='Generate New SSH Key';syncAuth();}});

det.appendChild(hint);det.appendChild(flEx);det.appendChild(dv);det.appendChild(flG);
sCard.appendChild(sL);sCard.appendChild(sC);sCard.appendChild(det);
ag.appendChild(pwCard);ag.appendChild(sCard);
aw.appendChild(ae);aw.appendChild(ag);
syncAuth();

// Auth guard
if(F&&!F.getAttribute('data-vf-auth-guard')){F.setAttribute('data-vf-auth-guard','1');F.addEventListener('submit',function(e){if(!authReq())return;if(pwdSel||sshSel)return;e.preventDefault();e.stopPropagation();if(e.stopImmediatePropagation)e.stopImmediatePropagation();ae.classList.add('vf-visible');try{st.scrollIntoView({behavior:"smooth",block:"center"});}catch(x){}},true);}
}// end SI

// LAYOUT INJECTION
try{
var og=fg(OI),sg=SI?fg(SI):null;
var ip=(og&&og.parentNode)?og.parentNode:OI.parentNode,ib=og||OI,hr=null;
function hc(el){if(!el||!el.classList)return false;for(var i=0;i<el.classList.length;i++){var c=el.classList[i];if(c==='col'||c.indexOf('col-')===0)return true;}return false;}
function frow(el){var c=el;while(c&&c!==document.body){if(c.classList&&(c.classList.contains('row')||c.classList.contains('form-row')))return c;c=c.parentNode;}return null;}
function fcol(el,s){var c=el;while(c&&c!==s&&c!==document.body){if(hc(c))return c;c=c.parentNode;}return null;}
var or=og?frow(og):null,sr=sg?frow(sg):null;
var rc=(or&&sr&&or===sr)?or:(or&&!sr)?or:null;
if(rc){var oc=og?fcol(og,rc):null;if(oc||sg){if(rc.parentNode){ip=rc.parentNode;ib=rc;hr=rc;}}}
else if(ip&&hc(ip)&&ip.parentNode){ib=ip;ip=ip.parentNode;hr=ib;}

if(ip&&!document.getElementById('vf-provisioning-card')){
var card=document.createElement('div');card.id='vf-provisioning-card';
var lc=document.createElement('div');lc.id='vf-provisioning-left';lc.appendChild(wiz);
var rc2=document.createElement('div');rc2.id='vf-provisioning-right';
if(SI&&st)rc2.appendChild(st);if(SI&&ss)rc2.appendChild(ss);if(SI&&aw)rc2.appendChild(aw);
card.appendChild(lc);card.appendChild(rc2);
ip.insertBefore(card,ib);
(function(c){if(!c)return;var a=function(){if(c.classList.contains('vf-ssh-hidden')){c.classList.remove('vf-two-col');return;}if(c.clientWidth>=980)c.classList.add('vf-two-col');else c.classList.remove('vf-two-col');};a();if(window.ResizeObserver)(new ResizeObserver(a)).observe(c);else window.addEventListener('resize',a);})(card);
if(og)og.style.display='none';if(sg&&sg!==og)sg.style.display='none';if(hr&&hr!==og&&hr!==sg)hr.style.display='none';
updSsh();}}catch(e){}
})();
