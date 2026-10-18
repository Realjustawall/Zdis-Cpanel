<?php
declare(strict_types=1);
function adminRoutes(string $m,string $p,array $b): ?array {
 if(!str_starts_with($p,'/api/admin'))return null;$me=admin();$id=$me['id'];
 if($p==='/api/admin/settings'){
  if($m==='GET')return ['settings'=>settings()];
  if($m==='PATCH'){$s=settings();foreach($b as$k=>$v)if(array_key_exists($k,$s)&&!in_array($k,['id','createdAt','updatedAt'],true)){
    if(gettype($v)!==gettype($s[$k]))fail(400,'Invalid setting type: '.$k);
    if(in_array($k,['feature_push_notifications'],true)&&$v)fail(501,'This integration is not ported in the PHP edition.','not_implemented');
    if($k==='app_name'&&$v!=='ZDIS')fail(400,'Application name must remain ZDIS.');
    if(is_int($v)&&($v<0||$v>100000))fail(400,'Invalid numeric setting.');$s[$k]=$v;
   }if($s['max_upload_mb']<1||$s['max_upload_mb']>256||$s['spam_messages_per_30s']<1)fail(400,'Invalid upload or message limit.');if($s['require_2fa_for_admins']&&!$me['totpEnabled'])fail(400,'Enable your own 2FA first.');
   putEntity('settings',$s+['id'=>'settings']);audit('settings.updated',null,array_keys($b));emitUsers(array_column(rows('SELECT id FROM accounts'),'id'),'settings:updated',settings());return ['settings'=>settings()];
  }
 }
 if($p==='/api/admin/overview'&&$m==='GET'){
  $roles=[];foreach(rows('SELECT id FROM accounts')as$r){$role=account($r['id'])['role'];$roles[$role]=($roles[$role]??0)+1;}
  return ['users'=>(int)row('SELECT COUNT(*) AS n FROM accounts')['n'],'groups'=>count(listEntities('group')),'messages'=>count(listEntities('message')),'attachments'=>count(listEntities('attachment')),'attachmentBytes'=>array_sum(array_column(listEntities('attachment'),'size')),'activeSessions'=>(int)row('SELECT COUNT(*) AS n FROM sessions WHERE expires_at>?',[now()])['n'],'openReports'=>count(array_filter(listEntities('report'),fn($r)=>$r['status']==='open')),'openDeadLetters'=>0,'roles'=>(object)$roles,'runtime'=>['database'=>'mysql','cache'=>'mysql','uptimeSeconds'=>0,'node'=>'PHP '.PHP_VERSION,'appName'=>'ZDIS','region'=>'cpanel','role'=>'web','writes'=>true,'memory'=>['rss'=>memory_get_usage(true),'heapUsed'=>memory_get_usage(),'heapTotal'=>memory_get_peak_usage(true),'external'=>0],'sockets'=>count(listEntities('voice'))]];
 }
 if($p==='/api/admin/analytics'&&$m==='GET'){
  $hours=max(1,min(168,(int)($_GET['hours']??24)));$series=[];for($n=$hours-1;$n>=0;$n--){$at=(int)(floor(now()/3600000)*3600000)-$n*3600000;$pt=['at'=>$at,'messages'=>0,'users'=>0,'audits'=>0,'reports'=>0];foreach(['messages'=>'message','audits'=>'audit','reports'=>'report']as$key=>$kind)$pt[$key]=(int)row('SELECT COUNT(*) n FROM entities WHERE kind=? AND created_at>=? AND created_at<?',[$kind,$at,$at+3600000])['n'];$pt['users']=(int)row('SELECT COUNT(*) n FROM accounts WHERE created_at>=? AND created_at<?',[$at,$at+3600000])['n'];$series[]=$pt;}return ['series'=>$series];
 }
 if($p==='/api/admin/users/suggest-password'&&$m==='GET')return ['password'=>'Aa1!'.bin2hex(random_bytes(14))];
 if($p==='/api/admin/users'){
  if($m==='GET'){$term=(string)($_GET['search']??'');$us=rows('SELECT id FROM accounts WHERE username LIKE ? OR email LIKE ? OR data LIKE ? ORDER BY created_at DESC LIMIT 500',['%'.$term.'%','%'.$term.'%','%'.$term.'%']);return ['users'=>array_map(fn($r)=>adminUser(account($r['id'])),$us)];}
  if($m==='POST'){$role=enumValue($b['role']??'member',['admin','youtuber','member','staff','developer']);$u=createUser($b,$role);identityFor($u['id']);audit('admin.user_created',$u['id']);return ['user'=>adminUser($u)];}
 }
 if(preg_match('#^/api/admin/users/([^/]+)(?:/(.*))?$#',$p,$a)){
  $uid=$a[1];$sub=$a[2]??'';$u=account($uid)??fail(404,'User not found.');
  if($sub===''){
   if($m==='GET')return ['user'=>adminUser($u)];
   if($m==='PATCH'){
    $patch=array_intersect_key($b,array_flip(['username','email','phone','displayName','firstName','lastName','nickname','bio','customStatus','role','isActive','mustChangePassword','bannerColor']));
    if(isset($patch['role']))enumValue($patch['role'],['admin','member','youtuber','staff','developer']);if($uid===$id&&((isset($patch['role'])&&$patch['role']!=='admin')||($patch['isActive']??true)===false))fail(400,'Cannot demote or disable your own administrator account.');
    if(isset($patch['username'])&&!preg_match('/^[a-z0-9._-]{3,32}$/D',$patch['username']))fail(400,'Invalid username.');if(isset($patch['email'])&&!filter_var($patch['email'],FILTER_VALIDATE_EMAIL))fail(400,'Invalid email.');
    $u=saveUser(array_replace($u,$patch));audit('admin.user_updated',$uid);return ['user'=>adminUser($u)];
   }
   if($m==='DELETE')fail(501,'Account deletion and ownership transfer are not ported. Disable the account instead.','not_implemented');
  }
  if($sub==='access'){
   if($m==='GET')return ['overrides'=>entity('access-'.$uid)??(object)[],'effective'=>accountAccess($uid)];
   if($m==='PUT'){foreach($b['overrides']??$b as$k=>$v)if(!array_key_exists($k,accountAccess($uid))||(!is_bool($v)&&!is_int($v)&&$v!==null))fail(400,'Invalid policy override.');$v=$b['overrides']??$b;$v['id']='access-'.$uid;putEntity('access',$v,$uid,$uid);emitUsers([$uid],'access:updated',['effective'=>accountAccess($uid)]);return ['overrides'=>entity('access-'.$uid),'effective'=>accountAccess($uid)];}
  }
  if($sub==='badges'&&$m==='PUT'){
   $badges=[];foreach($b['badgeIds']??[]as$bid){$found=false;foreach(catalogue()as$badge)if($badge['id']===$bid){$badges[]=$badge;$found=true;}if(!$found)fail(400,'Unknown badge.');}
   // Capability badges do not grant a PHP backend role implicitly.
   $u['badges']=$badges;saveUser($u);return ['user'=>adminUser($u)];
  }
  if($sub==='sessions'&&$m==='GET')return ['sessions'=>array_map(fn($s)=>decode($s['data'])+['id'=>$s['id'],'expiresAt'=>(int)$s['expires_at'],'active'=>(int)$s['expires_at']>now()],rows('SELECT * FROM sessions WHERE user_id=?',[$uid]))];
  if($sub==='logout'&&$m==='POST'){sql('DELETE FROM sessions WHERE user_id=?',[$uid]);audit('admin.user_logout',$uid);return ['ok'=>true];}
  if($sub==='unlock'&&$m==='POST'){foreach([$u['username'],$u['email'],$u['phone']] as $identifier)if($identifier){$bucket=hash('sha256','login:'.strtolower($identifier).':'.(string)intdiv(time(),900));sql('DELETE FROM rate_limits WHERE bucket=?',[$bucket]);}audit('admin.user_unlock',$uid);return ['ok'=>true];}
  if($sub==='disable-2fa'&&$m==='POST'){$u['totpEnabled']=false;saveUser($u);removeEntity('totp-'.$uid);audit('admin.2fa_disabled',$uid);return ['ok'=>true];}
  if($sub==='password'&&$m==='POST'){$pw=checkedPassword($b,'password');sql('UPDATE accounts SET password_hash=? WHERE id=?',[hashAccountPassword($pw),$uid]);sql('DELETE FROM sessions WHERE user_id=?',[$uid]);$u['mustChangePassword']=(bool)($b['mustChangePassword']??true);saveUser($u);audit('admin.password_reset',$uid);return ['ok'=>true];}
 }
 if($p==='/api/admin/groups'&&$m==='GET')return ['groups'=>array_map('groupView',listEntities('group'))];
 if(preg_match('#^/api/admin/groups/([^/]+)(?:/(discovery))?$#',$p,$a)){
  $g=mustEntity($a[1],'group');if(($a[2]??'')==='discovery'&&$m==='PATCH'){$g['discoverable']=(bool)($b['approved']??false);$g['discoveryRequested']=false;putEntity('group',$g,'',$g['ownerId']);audit('group.discovery',$g['id']);return ['group'=>groupView($g)];}
  if($m==='DELETE'){emitGroup($g['id'],'group:deleted',['groupId'=>$g['id']]);removeEntity($g['id']);sql('DELETE FROM memberships WHERE scope_id=?',[$g['id']]);return ['ok'=>true];}
 }
 if($p==='/api/admin/badges'&&$m==='GET')return ['badges'=>catalogue()];
 if($p==='/api/admin/audit'&&$m==='GET'){
  $logs=array_reverse(listEntities('audit'));if(!empty($_GET['action']))$logs=array_values(array_filter($logs,fn($v)=>str_contains($v['action'],$_GET['action'])));$total=count($logs);$page=max(1,(int)($_GET['page']??1));return ['logs'=>array_slice($logs,($page-1)*limit(),limit()),'pagination'=>['page'=>$page,'limit'=>limit(),'total'=>$total,'pages'=>(int)ceil($total/limit()),'hasMore'=>$page*limit()<$total]];
 }
 if($p==='/api/admin/platform'&&$m==='GET')return ['configuration'=>['storageDriver'=>'local','queueEnabled'=>false],'providers'=>['smtp'=>['configured'=>false],'fishAudio'=>['configured'=>false],'s3'=>['configured'=>false],'livekit'=>['configured'=>false]],'backups'=>[],'storage'=>['driver'=>'local','ok'=>is_writable(storageDir()),'count'=>count(listEntities('attachment')),'bytes'=>array_sum(array_column(listEntities('attachment'),'size')),'quarantined'=>0,'quotaCapacity'=>0,'quotaUsed'=>array_sum(array_column(listEntities('attachment'),'size')),'quotaReserved'=>0,'cdn'=>false,'bucket'=>null],'queue'=>['enabled'=>false,'waiting'=>0,'active'=>0,'delayed'=>count(listEntities('scheduled')),'completed'=>0,'failed'=>0,'jobs'=>[]],'retentionDays'=>30];
 if($p==='/api/admin/maintenance/purge'&&$m==='POST'){ $sessions=sql('DELETE FROM sessions WHERE expires_at<?',[now()])->rowCount();sql('DELETE FROM events WHERE created_at<?',[now()-86400000]);sql('DELETE FROM rate_limits WHERE expires_at<?',[now()]);audit('maintenance.purge');return ['sessions'=>$sessions,'attachments'=>0];}
 return null;
}
function adminUser(array $u): array {return $u+['failedLogins'=>0,'lockedUntil'=>null,'bannedAt'=>null,'suspendedUntil'=>null,'moderationReason'=>null,'lastLoginIp'=>null,'createdBy'=>null,'updatedAt'=>$u['createdAt']];}
function accountAccess(string $id): array {
 $effective=[];$mapping=['max_owned_groups'=>'maxOwnedGroups','max_joined_groups'=>'maxJoinedGroups','max_created_channels'=>'maxCreatedChannels','max_channels_per_group'=>'maxChannelsPerGroup'];
 foreach(settings()as$k=>$v)if(str_starts_with($k,'default_')){$short=substr($k,8);$effective[$mapping[$short]??$short]=$v;}
 foreach(entity('access-'.$id)??[]as$k=>$v)if(array_key_exists($k,$effective)&&$v!==null)$effective[$k]=$v;return $effective;
}
