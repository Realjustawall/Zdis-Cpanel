<?php
declare(strict_types=1);
ini_set('display_errors','0');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
$error='';$success=false;$appRoot=require __DIR__.'/paths.php';
if(!is_file($appRoot.'/config.php')){http_response_code(503);exit('Create config.php outside the public folder using config.example.php, then set app_key and setup_token.');}
require $appRoot.'/app/core.php';require $appRoot.'/app/auth.php';
if(strlen(cfg()['setup_token']??'')<32){http_response_code(404);exit('The installer is disabled. Set a random setup_token of at least 32 characters in config.php.');}
try{
 $lock=fopen(storageDir().'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Installer lock unavailable.');
 $installed=false;try{$installed=(int)row('SELECT COUNT(*) n FROM accounts')['n']>0;}catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}
 if($installed){http_response_code(403);exit('Installation is locked because an account already exists. Sign in at /.');}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals(cfg()['setup_token'],(string)($_POST['token']??'')))throw new HttpError(403,'توکن نصب صحیح نیست.');
  $origin=$_SERVER['HTTP_ORIGIN']??null;if($origin!==null&&!hash_equals(cfg()['origin'],$origin))throw new HttpError(403,'دامنه با origin تنظیم‌شده یکسان نیست.');
  if(strlen(cfg()['app_key']??'')!==64||!ctype_xdigit(cfg()['app_key']))throw new RuntimeException('Invalid app key.');
  // Validate input before applying schema; never create a partial administrator.
  foreach(explode(';',file_get_contents($appRoot.'/app/schema.sql'))as$stmt)if(trim($stmt)!=='')db()->exec($stmt);
  transaction(function(){if((int)row('SELECT COUNT(*) n FROM accounts')['n']>0)fail(403,'Already installed.');$u=createUser($_POST,'admin');identityFor($u['id']);});$success=true;
 }
}catch(Throwable $e){http_response_code($e instanceof HttpError?$e->status:500);$error=$e instanceof HttpError?$e->getMessage():'خطا در نصب؛ تنظیمات دیتابیس و PHP error log را بررسی کنید.';error_log('ZDIS setup: '.$e->getMessage());}
?>
<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب ZDIS برای cPanel</title><style>
*{box-sizing:border-box}body{margin:0;background:#101118;color:#e8e9f1;font:16px/1.8 Tahoma,sans-serif;min-height:100vh;display:grid;place-items:center;background-image:radial-gradient(ellipse at 20% 10%,#45367c66,transparent 55%)}main{width:min(92vw,650px);margin:40px auto;background:#191b26;border:1px solid #34364b;border-radius:24px;padding:36px;box-shadow:0 30px 80px #0005}.logo{width:58px;height:58px;display:grid;place-items:center;background:#8463ff;border-radius:17px;font:bold 30px Arial}h1{font-size:25px;margin-bottom:4px}p{color:#a7adbd}label{display:block;margin-top:15px}input{width:100%;background:#11131b;border:1px solid #383c50;border-radius:10px;padding:12px;color:white;font:inherit;direction:ltr}input:focus{outline:2px solid #977aff}button,a{display:block;background:#8463ff;color:white;border:0;border-radius:10px;padding:13px 20px;text-decoration:none;font:inherit;margin-top:24px;cursor:pointer;width:100%;text-align:center}.tag{color:#a891ff}.error{padding:14px;background:#602736;border-radius:10px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:500px){.grid{display:block}main{padding:24px}}
</style><main><div class="logo">Z</div><span class="tag">PHP · MySQL · cPanel</span><h1>راه‌اندازی ZDIS</h1><?php if($success):?><p>نصب با موفقیت انجام شد. نصب‌کننده اکنون قفل است. setup_token را از تنظیمات پاک کنید.</p><a href="/">ورود به ZDIS</a><?php else:?><p>دیتابیس را در cPanel بسازید و اطلاعات آن را در config.php قرار دهید. این مرحله حساب مدیر اولیه را ایجاد می‌کند.</p><?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?><form method="post" autocomplete="off"><label>توکن یک‌بارمصرف نصب<input name="token" type="password" required minlength="32" autocomplete="off"></label><div class="grid"><label>نام کاربری مدیر<input name="username" required minlength="3" maxlength="32" pattern="[a-z0-9._-]+" autocomplete="username"></label><label>نام نمایشی<input name="displayName" maxlength="48" value="Administrator"></label></div><label>ایمیل مدیر<input name="email" type="email" required autocomplete="email"></label><label>رمز مدیر — حداقل ۱۰ نویسه؛ حرف کوچک، حرف بزرگ، عدد و نماد<input name="password" type="password" required minlength="10" maxlength="200" autocomplete="new-password"></label><button type="submit">ایجاد جداول و حساب مدیر</button></form><?php endif;?></main></html>
