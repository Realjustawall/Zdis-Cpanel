<?php
declare(strict_types=1);
// Loaded only by the installer. No settings or passwords are sent to the client.
function installerRequirements(string $root): array {
 $checks=['PHP 8.2 یا جدیدتر'=>PHP_VERSION_ID>=80200];
 foreach(['pdo_mysql','mbstring','fileinfo','openssl','curl']as$extension)$checks['افزونهٔ '.$extension]=extension_loaded($extension);
 $document=realpath($_SERVER['DOCUMENT_ROOT']??($root.'/public'));$private=realpath($root);$checks['پوشهٔ خصوصی خارج از دسترسی وب']=$document&&$private&&$private!==$document&&!str_starts_with($private,$document.'/');
 $checks['پوشهٔ خصوصی قابل نوشتن']=is_writable($root);
 $checks['پوشهٔ فایل‌ها قابل نوشتن']=is_dir($root.'/storage')?is_writable($root.'/storage'):is_writable($root);
 return $checks;
}
function installerConfig(array $input,string $root): array {
 $host=trim((string)($input['db_host']??'localhost'));$port=(string)($input['db_port']??'3306');$name=trim((string)($input['db_name']??''));$username=trim((string)($input['db_user']??''));
 if(!preg_match('/^[a-zA-Z0-9.:-]{1,253}$/D',$host)||!ctype_digit($port)||(int)$port<1||(int)$port>65535)fail(400,'آدرس یا پورت دیتابیس صحیح نیست.');
 if(!preg_match('/^[a-zA-Z0-9_$-]{1,64}$/D',$name)||$username===''||strlen($username)>128)fail(400,'نام دیتابیس و نام کاربر کامل cPanel را وارد کنید.');
 $origin=rtrim(trim((string)($input['origin']??'')),'/');$u=parse_url($origin);$local=in_array($u['host']??'',['localhost','127.0.0.1','[::1]'],true);
 if(!$u||empty($u['host'])||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||!empty($u['path'])||(!in_array($u['scheme']??'',['https'],true)&&!(($u['scheme']??'')==='http'&&$local)))fail(400,'دامنه را به صورت https://chat.example.com و بدون مسیر وارد کنید.');
 return ['dsn'=>"mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",'db_user'=>$username,'db_password'=>(string)($input['db_password']??''),'app_key'=>bin2hex(random_bytes(32)),'setup_token'=>'','origin'=>$origin,'secure_cookies'=>!$local||($u['scheme']??'')==='https','storage'=>$root.'/storage','ice_servers'=>[['urls'=>'stun:stun.l.google.com:19302']],'session_days'=>14];
}
function installerConnection(array $config): PDO {
 try{$pdo=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>5]);$pdo->exec("SET time_zone = '+00:00'");$pdo->query('SELECT 1');return $pdo;}
 catch(PDOException $e){error_log('ZDIS installer database connection failed: '.$e->getCode());fail(400,'اتصال برقرار نشد. نام کامل دیتابیس و کاربر، رمز، آدرس سرور و دسترسی کاربر به دیتابیس را در cPanel بررسی کنید.');}
}
function installerDatabaseEmpty(PDO $pdo): void {if($pdo->query('SHOW TABLES')->fetch())fail(409,'این دیتابیس خالی نیست. برای نصب تازه، یک دیتابیس خالی مخصوص ZDIS انتخاب کنید؛ هیچ جدول موجودی حذف نمی‌شود.');}
