import {createRequire} from 'node:module';
if(process.env.ZDIS_TEST_DISPOSABLE!=='1')throw new Error('Disposable tests only');
const {chromium}=createRequire(import.meta.url)('playwright');
const browser=await chromium.launch({headless:true,...(process.env.ZDIS_CHROMIUM?{executablePath:process.env.ZDIS_CHROMIUM}:{}),args:['--no-sandbox','--disable-dev-shm-usage']});
try{
 const page=await browser.newPage();await page.addInitScript(()=>localStorage.setItem('zdis.locale','en'));const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8089/');await page.locator('#next').click();
 const input=JSON.parse(process.env.ZDIS_INSTALLER_INPUT);
 for(const [name,value]of Object.entries(input))await page.locator(`[name="${name}"]`).fill(String(value));
 await page.locator('#next').click();await page.locator('[name="username"]').waitFor({state:'visible'});
 await page.locator('[name="username"]').fill('siteadmin');await page.locator('[name="email"]').fill('owner@example.test');await page.locator('[name="password"]').fill('InstallerAdmin123!');await page.locator('[name="password_confirmation"]').fill('InstallerAdmin123!');
 await Promise.all([page.waitForNavigation(),page.locator('#install').click()]);if(await page.locator('.notice.error').count())throw Error(await page.locator('.notice.error').innerText());await page.getByRole('heading',{name:'نصب با موفقیت انجام شد'}).waitFor();
 await page.getByRole('link',{name:'ورود و بازکردن پنل مدیریت'}).click();await page.locator('input[autocomplete="username"]').fill('siteadmin');await page.locator('input[autocomplete="current-password"]').fill('InstallerAdmin123!');await page.locator('button.auth-submit').click();await page.locator('.rail-item[title="Administration"]').click();await page.locator('.admin-nav button[aria-label="تنظیمات"]').click();await page.locator('.admin-main h1').filter({hasText:'تنظیمات'}).waitFor();await page.locator('.admin-nav .back').click();await page.locator('.head-btn[title="Account settings"]').click();await page.getByRole('tab',{name:'Security',exact:true}).click();await page.locator('input[autocomplete="current-password"]').waitFor();
 if(errors.length)throw Error(JSON.stringify(errors));console.log('PASS: first-visit installer wizard connects MySQL and creates administrator in browser.');
}finally{await browser.close();}
