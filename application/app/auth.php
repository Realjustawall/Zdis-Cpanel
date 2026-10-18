<?php
declare(strict_types=1);
function base32(string $bytes): string {$bits='';foreach(str_split($bytes)as$c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5)as$b)$out.='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec(str_pad($b,5,'0'))];return $out;}
function totpValid(string $secret,string $token): bool {$bits='';foreach(str_split($secret)as$c){$v=strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c);if($v===false)return false;$bits.=str_pad(decbin($v),5,'0',STR_PAD_LEFT);}$key='';foreach(str_split($bits,8)as$b)if(strlen($b)===8)$key.=chr(bindec($b));for($d=-1;$d<=1;$d++){$counter=intdiv(time(),30)+$d;$hash=hash_hmac('sha1',pack('N2',0,$counter),$key,true);$off=ord($hash[19])&15;$v=(unpack('N',substr($hash,$off,4))[1]&0x7fffffff)%1000000;if(hash_equals(str_pad((string)$v,6,'0',STR_PAD_LEFT),$token))return true;}return false;}
function secretBox(string $value): string {$iv=random_bytes(12);$key=hex2bin(cfg()['app_key']);$cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$cipher);}
function secretOpen(string $value): string {$v=base64_decode($value,true);$out=openssl_decrypt(substr($v,28),'aes-256-gcm',hex2bin(cfg()['app_key']),OPENSSL_RAW_DATA,substr($v,0,12),substr($v,12,16));if($out===false)throw new RuntimeException('Cannot decrypt secret.');return $out;}
function identityFor(string $id): array {
 $existing=entity('key-'.$id,'identity');if($existing)return $existing;
 $pub=[];$priv=[];foreach(['encryption','signing'] as $purpose){$k=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$k)throw new RuntimeException('EC key generation failed');$d=openssl_pkey_get_details($k)['ec'];$p=['kty'=>'EC','crv'=>'P-256','x'=>rtrim(strtr(base64_encode(str_pad($d['x'],32,"\0",STR_PAD_LEFT)),'+/','-_'),'='),'y'=>rtrim(strtr(base64_encode(str_pad($d['y'],32,"\0",STR_PAD_LEFT)),'+/','-_'),'='),'ext'=>true];$pub[$purpose]=$p;$priv[$purpose]=$p+['d'=>rtrim(strtr(base64_encode(str_pad($d['d'],32,"\0",STR_PAD_LEFT)),'+/','-_'),'=')];}
 return putEntity('identity',['id'=>'key-'.$id,'publicKey'=>$pub,'privateBox'=>secretBox(json($priv)),'version'=>1],$id,$id);
}
function authRoutes(string $method,string $path,array $b): ?array {
 if($path==='/api/auth/sso/providers'&&$method==='GET')return ['providers'=>[]];
 if($path==='/api/auth/public-settings'&&$method==='GET')return ['settings'=>settings()];
 if($path==='/api/auth/me'&&$method==='GET'){
  $s=session();$u=$s?account($s['user_id']):null;if($u&&(!$u['isActive']||($u['bannedAt']??null)||($u['suspendedUntil']??0)>now()))$u=null;
  return ['user'=>$u,'csrfToken'=>$u?$s['csrf']:null,'settings'=>array_replace(settings(),$u?['accountAccess'=>accountAccess($u['id'])]:[]),'ice'=>cfg()['ice_servers']??[],'voice'=>['mode'=>'mesh','livekitUrl'=>null]];
 }
 if($path==='/api/auth/register'&&$method==='POST'){
  throttle('register:'.($_SERVER['REMOTE_ADDR']??''),5,900);if(!settings()['registration_enabled'])fail(403,'Public registration is disabled.');
  foreach(['firstName','lastName','nickname','phone']as$key)required($b,$key,48);if(!preg_match('/^\+?[0-9]{7,20}$/D',$b['phone']))fail(400,'Invalid phone.');
  $u=createUser($b);identityFor($u['id']);audit('auth.register',$u['id']);http_response_code(201);return ['ok'=>true,'username'=>$u['username']];
 }
 if($path==='/api/auth/login'&&$method==='POST'){
  $identifier=strtolower(required($b,'identifier',254));throttle('loginip:'.($_SERVER['REMOTE_ADDR']??''),40,900);throttle('login:'.$identifier,12,900);
  $r=row('SELECT * FROM accounts WHERE username=? OR email=? OR phone=?',[$identifier,$identifier,$identifier]);
  $hash=$r['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
  if(!verifyAccountPassword((string)($b['password']??''),$hash)||!$r)fail(401,'Incorrect credentials.','invalid_credentials');
  $u=account($r['id']);if(!$u['isActive']||($u['bannedAt']??null)||($u['suspendedUntil']??0)>now())fail(403,'Account is not active.');
  if($u['totpEnabled']){$secret=entity('totp-'.$u['id']);if(empty($b['totp']))return ['mfaRequired'=>true];if(!totpValid(secretOpen($secret['box']),(string)$b['totp'])){ $ok=false;foreach(listEntities('recovery',$u['id'])as$c)if(password_verify((string)$b['totp'],$c['hash'])){removeEntity($c['id']);$ok=true;break;}if(!$ok)fail(401,'Invalid authentication code.');}}
  identityFor($u['id']);audit('auth.login',$u['id']);return loginSession($u);
 }
 if($path==='/api/auth/logout'&&$method==='POST'){
  if(session())sql('DELETE FROM sessions WHERE id=?',[session()['id']]);cookie('zdis_session','',time()-3600);cookie('ytbl_csrf','',time()-3600,false);return ['ok'=>true];
 }
 if(str_starts_with($path,'/api/auth/')){
  $u=user();$id=$u['id'];
  if($path==='/api/auth/profile'&&$method==='PATCH'){
   foreach(['displayName','email','phone','firstName','lastName','nickname','username','bio','customStatus','bannerColor','presence','ttsButtonEnabled'] as $key)if(array_key_exists($key,$b))$u[$key]=$b[$key];
   required($u,'displayName',48);if($u['bio']!==null)required($u,'bio',300,0);if(!filter_var($u['email'],FILTER_VALIDATE_EMAIL))fail(400,'Invalid email.');if(!preg_match('/^[a-z0-9._-]{3,32}$/D',(string)$u['username']))fail(400,'Invalid username.');enumValue($u['presence'],['online','offline','idle','dnd']);
   saveUser($u);emitUsers(array_column(rows('SELECT id FROM accounts'),'id'),'presence:update',['userId'=>$id,'presence'=>$u['presence']]);return ['user'=>$u];
  }
  if($path==='/api/auth/password'&&$method==='POST'){
   $r=row('SELECT password_hash FROM accounts WHERE id=?',[$id]);if(!verifyAccountPassword($b['currentPassword']??'',$r['password_hash']))fail(401,'Current password is incorrect.');
   $pw=checkedPassword($b,'newPassword');sql('UPDATE accounts SET password_hash=? WHERE id=?',[hashAccountPassword($pw),$id]);sql('DELETE FROM sessions WHERE user_id=? AND id<>?',[$id,session()['id']]);$u['mustChangePassword']=false;$u['passwordChangedAt']=now();saveUser($u);audit('auth.password_changed',$id);return ['ok'=>true,'user'=>$u];
  }
  if($path==='/api/auth/sessions'&&$method==='GET')return ['sessions'=>array_map(fn($s)=>decode($s['data'])+['id'=>$s['id'],'expiresAt'=>(int)$s['expires_at'],'current'=>$s['id']===session()['id'],'active'=>(int)$s['expires_at']>now()],rows('SELECT * FROM sessions WHERE user_id=?',[$id]))];
  if($path==='/api/auth/sessions/revoke-others'&&$method==='POST')return ['ok'=>true,'revoked'=>sql('DELETE FROM sessions WHERE user_id=? AND id<>?',[$id,session()['id']])->rowCount()];
  if(preg_match('#^/api/auth/sessions/([^/]+)$#',$path,$p)&&$method==='DELETE'){sql('DELETE FROM sessions WHERE id=? AND user_id=?',[$p[1],$id]);return ['ok'=>true];}
  if($path==='/api/auth/totp/setup'&&$method==='POST'){
   if($u['totpEnabled'])fail(400,'Two-factor authentication is already enabled.');$secret=base32(random_bytes(20));putEntity('totp_pending',['id'=>'pending-'.$id,'box'=>secretBox($secret),'expiresAt'=>now()+600000],$id,$id);return ['secret'=>$secret,'uri'=>'otpauth://totp/'.rawurlencode('ZDIS:'.$u['email']).'?secret='.$secret.'&issuer=ZDIS'];
  }
  if($path==='/api/auth/totp/enable'&&$method==='POST'){
   $pending=entity('pending-'.$id,'totp_pending');if(!$pending||$pending['expiresAt']<now()||!totpValid(secretOpen($pending['box']),$b['token']??''))fail(400,'Invalid code or setup expired.');putEntity('totp',['id'=>'totp-'.$id,'box'=>$pending['box']],$id,$id);removeEntity($pending['id']);$u['totpEnabled']=true;saveUser($u);return ['ok'=>true,'user'=>$u,'recoveryCodes'=>recoveryCodes($id)];
  }
  if($path==='/api/auth/totp/disable'&&$method==='POST'){
   if($u['role']==='admin'&&settings()['require_2fa_for_admins'])fail(403,'Two-factor authentication is mandatory for administrators.');
   if(!verifyAccountPassword($b['password']??'',row('SELECT password_hash FROM accounts WHERE id=?',[$id])['password_hash'])||!totpValid(secretOpen(mustEntity('totp-'.$id,'totp')['box']),$b['token']??''))fail(401,'Invalid password or authentication code.');$u['totpEnabled']=false;saveUser($u);removeEntity('totp-'.$id);return ['ok'=>true,'user'=>$u];
  }
  if($path==='/api/auth/totp/recovery-codes'&&$method==='GET')return ['remaining'=>count(listEntities('recovery',$id)),'generatedAt'=>null];
  if($path==='/api/auth/totp/recovery-codes/regenerate'&&$method==='POST'){
   if(!verifyAccountPassword($b['password']??'',row('SELECT password_hash FROM accounts WHERE id=?',[$id])['password_hash'])||!totpValid(secretOpen(mustEntity('totp-'.$id,'totp')['box']),$b['token']??''))fail(401,'Invalid password or code.');return ['recoveryCodes'=>recoveryCodes($id)];
  }
  if($path==='/api/auth/security-events'&&$method==='GET')return ['events'=>listEntities('security',$id)];
 }
 if(str_starts_with($path,'/api/e2ee/')){
  $u=user();$id=$u['id'];$i=identityFor($id);
  if($path==='/api/e2ee/identity'&&$method==='GET'){unset($i['privateBox']);return ['identity'=>$i];}
  if($path==='/api/e2ee/identity/bootstrap'&&$method==='GET')return ['identity'=>['publicKey'=>$i['publicKey'],'privateKey'=>isset($i['privateBox'])?decode(secretOpen($i['privateBox'])):null,'version'=>$i['version']]];
  if($path==='/api/e2ee/identity'&&$method==='PUT'){
   foreach(['encryption','signing']as$key){$k=$b['publicKey'][$key]??[];if(($k['kty']??'')!=='EC'||($k['crv']??'')!=='P-256'||!preg_match('/^[a-zA-Z0-9_-]{43}$/D',$k['x']??'')||!preg_match('/^[a-zA-Z0-9_-]{43}$/D',$k['y']??'')||isset($k['d']))fail(400,'Invalid public key.');}
   if(json($i['publicKey'])!==json($b['publicKey'])){$i['publicKey']=$b['publicKey'];$i['version']++;unset($i['privateBox']);putEntity('identity',$i,$id,$id);}unset($i['privateBox']);return ['identity'=>$i];
  }
  if(preg_match('#^/api/e2ee/conversations/([^/]+)/participants$#',$path,$p)&&$method==='GET'){
   canTarget('conversations',$p[1]);return ['participants'=>array_map(function($mid){$k=identityFor($mid);$u=account($mid);return ['userId'=>$mid,'username'=>$u['username'],'displayName'=>$u['displayName'],'publicKey'=>$k['publicKey'],'version'=>$k['version'],'updatedAt'=>$k['updatedAt']];},memberIds($p[1]))];
  }
 }
 return null;
}
function recoveryCodes(string $id): array {foreach(listEntities('recovery',$id)as$c)removeEntity($c['id']);$out=[];for($n=0;$n<8;$n++){$code=bin2hex(random_bytes(5));$out[]=$code;putEntity('recovery',['hash'=>password_hash($code,PASSWORD_DEFAULT)],$id,$id);}return $out;}
