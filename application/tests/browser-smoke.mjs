import {createRequire} from 'node:module';
if(process.env.ZDIS_TEST_DISPOSABLE!=='1')throw new Error('Use an isolated test deployment and set ZDIS_TEST_DISPOSABLE=1.');
const require=createRequire(import.meta.url);const {chromium}=require('playwright');
const base=process.env.ZDIS_TEST_URL??'http://127.0.0.1:8087';
const browser=await chromium.launch({headless:true,...(process.env.ZDIS_CHROMIUM?{executablePath:process.env.ZDIS_CHROMIUM}:{}),args:['--no-sandbox','--no-zygote','--disable-dev-shm-usage','--use-fake-ui-for-media-stream','--use-fake-device-for-media-stream','--allow-loopback-in-peer-connection','--disable-features=WebRtcHideLocalIpsWithMdns']});
const errors=[];
async function signIn(name){
 const context=await browser.newContext({serviceWorkers:'block',permissions:['microphone','camera']});
 await context.addInitScript(()=>{localStorage.setItem('zdis.locale','en');const Base=RTCPeerConnection;window.__testPeers=[];window.RTCPeerConnection=class extends Base {constructor(...args){super(...args);window.__testPeers.push(this);}};});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(base);
 await page.locator('input[autocomplete="username"]').fill(name);await page.locator('input[autocomplete="current-password"]').fill('TestAdmin12345!');await page.locator('button.auth-submit').click();await page.locator('.rail-item').first().waitFor();return {context,page};
}
try{
 const alice=await signIn('alice'),bob=await signIn('bob');
 await alice.page.locator('.rail-item[title="آزمایش ZDIS"]').click();await alice.page.locator('.channel-nav-row .nav-item').filter({has:alice.page.locator('.label',{hasText:/^general$/})}).click();
 const message='Browser check '+Date.now();await alice.page.locator('.composer textarea').fill(message);await alice.page.locator('.composer textarea').press('Enter');await alice.page.locator('.message').getByText(message,{exact:true}).waitFor();
 await alice.page.locator('.rail-item').first().click();await alice.page.locator('.sidebar .nav-item').filter({has:alice.page.locator('.label',{hasText:/^bob/})}).click();
 await bob.page.locator('.sidebar .nav-item').filter({has:bob.page.locator('.label',{hasText:/آلیس آزمایشی/})}).click();
 const secret='E2EE '+Date.now();await alice.page.locator('.composer textarea').fill(secret);await alice.page.locator('.composer textarea').press('Enter');await bob.page.locator('.message').getByText(secret,{exact:true}).waitFor({timeout:20000});
 await alice.page.getByRole('button',{name:'Start video call',exact:true}).click();await bob.page.locator('.incoming-call button.accept').waitFor();await bob.page.locator('.incoming-call button.accept').click();
 await bob.page.waitForFunction(async()=>{for(const p of window.__testPeers){const s=await p.getStats();for(const v of s.values())if(v.type==='inbound-rtp'&&v.bytesReceived>0)return true;}return false;},{},{timeout:20000,polling:200});
 if(errors.length)throw new Error(JSON.stringify(errors));console.log('PASS: UI login, MySQL-backed messages, two-account E2EE and synthetic WebRTC media.');
}finally{await browser.close();}
