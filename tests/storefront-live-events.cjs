// Read-only production-page browser acceptance. Candidate JS can be intercepted locally.
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
 const scratch = 'Q:/CodexTemp/storefront-event-delivery'; fs.mkdirSync(scratch,{recursive:true});
 const profile = path.join(scratch,'profile-'+Date.now()); fs.mkdirSync(profile);
 const child = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--no-first-run','--disable-extensions','--remote-debugging-port=0','--remote-debugging-address=127.0.0.1','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let ws;
 try {
  let port; for(let i=0;i<60&&!port;i++){await sleep(200);try{port=fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];}catch{}}
  assert(port,'Chrome debugger');
  const tabs=await fetch('http://127.0.0.1:'+port+'/json/list').then(r=>r.json());
  ws=new WebSocket(tabs.find(t=>t.type==='page').webSocketDebuggerUrl); await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
  let id=0; const pending=new Map(); const errors=[]; const requests=[];
  function call(method,params={}){return new Promise((resolve,reject)=>{const n=++id; const timer=setTimeout(()=>{pending.delete(n);reject(Error(method+' timeout'));},25000);pending.set(n,{resolve:r=>{clearTimeout(timer);resolve(r);},reject});ws.send(JSON.stringify({id:n,method,params}));});}
  ws.onmessage=async e=>{const m=JSON.parse(e.data);if(pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);return;}
   if(m.method==='Runtime.exceptionThrown') errors.push({text:m.params.exceptionDetails.text,description:m.params.exceptionDetails.exception?.description,url:m.params.exceptionDetails.url});
   if(m.method==='Network.requestWillBeSent') requests.push({url:m.params.request.url,time:Date.now()});
   if(m.method==='Fetch.requestPaused') {const p=m.params;const name=new URL(p.request.url).pathname.split('/').pop();
    if(process.argv.includes('--candidate')&&['storefront-catalog.js','storefront-realtime.js'].includes(name))await call('Fetch.fulfillRequest',{requestId:p.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/javascript'}],body:fs.readFileSync(path.join(__dirname,'../assets/js',name)).toString('base64')});
    else if(!['GET','HEAD','OPTIONS'].includes(p.request.method))await call('Fetch.failRequest',{requestId:p.requestId,errorReason:'Aborted'});
    else await call('Fetch.continueRequest',{requestId:p.requestId});
   }
  };
  async function evaluate(expression){const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;}
  await call('Page.enable');await call('Runtime.enable');await call('Network.enable');await call('Fetch.enable',{patterns:[{urlPattern:'*'}]});
  const evidence=[];
  for(const [url,selector,event] of [['https://digitalogic.ir/catalog/','.dgl-catalog-results','digitalogic:catalog-refreshed'],['https://digitalogic.ir/shop/','.products','digitalogic:cards-refreshed']]) {
   await call('Page.navigate',{url});let ready=false;for(let i=0;i<80&&!ready;i++){await sleep(250);ready=await evaluate(`!!document.querySelector(${JSON.stringify(selector)}) && document.readyState==='complete'`);}
   assert(ready,url+' ready');await sleep(1000);
   const result=await evaluate(`(async()=>{const before=location.href;let done;const p=new Promise(r=>done=r);const start=performance.now();window.addEventListener(${JSON.stringify(event)},()=>done({elapsed_ms:Math.round(performance.now()-start),url_unchanged:location.href===before}),{once:true});for(let i=0;i<10;i++)window.dispatchEvent(new CustomEvent('digitalogic:product-invalidated',{detail:{id:Date.now()+i,name:'product.updated',data:{product_id:11589}}}));return await Promise.race([p,new Promise(r=>setTimeout(()=>r({timeout:true}),15000))]);})()`);
   assert(!result.timeout,url+' invalidation render');assert(result.url_unchanged);evidence.push({url,...result});
  }
  fs.writeFileSync(path.join(scratch,'acceptance.json'),JSON.stringify({candidate:process.argv.includes('--candidate'),evidence,errors,requests:requests.filter(r=>/catalog_page|digitalogic_realtime|search-freshness/.test(r.url))},null,2));
  console.log(JSON.stringify({evidence,errors}));await call('Browser.close');
 } finally {if(ws)ws.close();child.kill();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});

