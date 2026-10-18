<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/core.php';
if(strlen(cfg()['app_key']??'')!==64||!ctype_xdigit(cfg()['app_key']))throw new RuntimeException('Generate a 64-character hexadecimal app_key.');
foreach(explode(';',file_get_contents(dirname(__DIR__).'/app/schema.sql'))as$stmt)if(trim($stmt)!=='')db()->exec($stmt);
if((int)row('SELECT COUNT(*) n FROM accounts')['n']>0)throw new RuntimeException('An account already exists; installer is locked.');
echo 'Administrator username: ';$username=trim(fgets(STDIN));echo 'Administrator email: ';$email=trim(fgets(STDIN));
$password=getenv('ZDIS_ADMIN_PASSWORD');if(!$password){echo "Administrator password (use a private terminal): ";$password=trim(fgets(STDIN));}
$u=createUser(['username'=>$username,'email'=>$email,'password'=>$password,'displayName'=>'Administrator'],'admin');
require dirname(__DIR__).'/app/auth.php';identityFor($u['id']);echo "Installed ZDIS PHP. Sign in with your administrator credentials.\n";
