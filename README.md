# ZDIS برای cPanel با PHP و MySQL — نسخهٔ آزمایشی

بستهٔ مستقل از مخزن اصلی؛ مخزن اصلی Realjustawall/zdis دست‌نخورده است؛ این مخزن مخصوص نسخهٔ cPanel است.

این نسخهٔ preview چت اصلی را اجرا می‌کند؛ **تبدیل کامل تمام امکانات نسخهٔ Node.js نیست**. مجوزهای نقش/کانال، بخش‌هایی از ربات‌ها، API/OAuth و صف وب‌هوک اضافه و آزموده شده‌اند. SSO، SMTP/Push، صوت پیشرفته، worker رسانه و چند زیرسیستم عملیاتی هنوز منتقل نشده‌اند. همهٔ صفحات اصلی حفظ شده‌اند؛ بعضی عملیات آن‌ها هنوز خطای صریح 501 می‌دهند. قبل از استفاده، application/PORTING-STATUS.md را بخوانید.

## نصب

راهنمای گام‌به‌گام فارسی: application/INSTALL-fa.md.
- application شامل بک‌اند مستقل PHP و رابط بیلدشده است. اجرای سایت به Node.js نیاز ندارد.
- فقط application/public باید Document Root باشد؛ برای دامنهٔ اصلیِ public_html از paths.php طبق راهنما استفاده کنید.
- دیتابیس و کاربر را در cPanel بسازید؛ سپس دامنه را باز کنید. نصب‌کننده اطلاعات اتصال و رمز مدیر را می‌گیرد، اتصال را تست می‌کند و config.php و کلید امنیتی را خودکار می‌سازد.
- ثبت‌نام پیش‌فرض بسته است؛ هیچ رمز آمادهٔ مدیر وجود ندارد.

## کد و اعتبارسنجی

- source نسخهٔ سورس پروژه با همان UI و تغییر لایهٔ انتقال رویداد است. server موجود در آن، کد اصلی Node.js و صرفاً مرجع است؛ هنگام اجرای application استفاده نمی‌شود.
- گزارش واقعی: application/TEST-RESULTS.md؛ شواهد JSON در application/validation.
- ۱۹۳ بررسی HTTP/API در دو مجموعه، تست دو مرورگر برای DM رمز‌شده و دریافت RTP تماس با رسانهٔ مصنوعی موفق بوده‌اند. تست روی cPanel واقعی انجام نشده است.
- ۱۱۳ فایل ورودی سورس فرانت‌اند اصلی تغییر نکرده‌اند؛ فایل‌های صفحات، کامپوننت‌ها و CSS اصلی حفظ شده‌اند. تنها import فایل realtime.ts تغییر کرده و cpanelRealtime.ts افزوده شده است.
- برای ساخت دوبارهٔ رابط، در source وابستگی‌های client را نصب و `npm run build --workspace client` را اجرا کنید؛ سپس محتویات source/client/dist را روی application/public کپی کنید و فایل‌های PHP/.htaccess را نگه دارید. ابزار rebuild-frontend.mjs همین کپی را انجام می‌دهد.

این بسته برای دیتابیس تازه است. برای جابه‌جایی داده‌های نصب قبلی، ابزار مهاجرت هنوز آماده نیست. فهرست کامل امکانات و محدودیت‌ها در PORTING-STATUS.md آمده است.


## Build and CI

GitHub Actions builds the original React client, lints PHP, runs both disposable-database API suites (193 core/API checks plus installer/admin regression checks), and runs browser E2EE/WebRTC smoke checks. A successful run uploads `ZDis-cPanel-PHP-preview.zip` and its SHA-256 file under the **cpanel-install-package** artifact. The ZIP uses the application/source layout documented above.

To rebuild locally with Node.js 24:

```sh
cd source
npm ci --ignore-scripts
cd ..
node rebuild-frontend.mjs
python3 scripts/package.py
```

Node.js is needed for rebuilding only. The deployed application runs on PHP/MySQL. Production secrets and config.php must stay outside the public document root and outside Git.

The Node.js server in `source/server` is preserved as upstream reference; this cPanel build does not run it. Existing upstream workflows under `source/.github` are reference files. The active workflow is `.github/workflows/cpanel.yml`.


## نصب آسان و حساب مدیر

در اولین بازدید، نصب‌کنندهٔ سه‌مرحله‌ای اجرا می‌شود: بررسی پیش‌نیازها، تست اتصال MySQL، ساخت مدیر. رمز مدیر را خودتان انتخاب می‌کنید؛ رمز پیش‌فرض وجود ندارد. پس از نصب، تنظیمات خصوصی ذخیره و نصب مجدد قفل می‌شود. پنل مدیریت از منوی کاربر قابل بازکردن است. تغییر رمز خودتان از تنظیمات حساب و تعیین رمز کاربران از پنل مدیریت انجام می‌شود. آزمون نصب مرورگری و چرخهٔ تغییر رمز/ابطال نشست نیز در CI اجرا می‌شود.
