<?php
declare(strict_types=1);
function catalogue(): array {return array_merge(decode(file_get_contents(__DIR__.'/badges.json')),listEntities('badge'));}
function friendships(string $id): array {$out=[];foreach(listEntities('friendship')as$f)if(in_array($id,[$f['fromId'],$f['toId']],true)){$other=$f['fromId']===$id?$f['toId']:$f['fromId'];$out[]=$f+['direction'=>$f['fromId']===$id?'outgoing':'incoming','user'=>publicUser(account($other))];}return $out;}
function socialRoutes(string $m,string $p,array $b): ?array {
 if(!preg_match('#^/api/(users|network|notifications)(?:/|$)#',$p))return null;$u=user();$id=$u['id'];
 if($p==='/api/users'&&$m==='GET'){$search=mb_substr((string)($_GET['search']??''),0,100);$us=rows('SELECT id FROM accounts WHERE username LIKE ? OR data LIKE ? ORDER BY created_at LIMIT 500',['%'.$search.'%','%'.$search.'%']);return ['users'=>array_values(array_filter(array_map(fn($r)=>publicUser(account($r['id'])),$us),fn($v)=>$v['isActive']))];}
 if($p==='/api/users/me/blocks'&&$m==='GET')return ['blocked'=>array_map(fn($x)=>publicUser(account($x['blockedId'])),listEntities('block',null,$id))];
 if(preg_match('#^/api/users/([^/]+)(?:/(block|report))?$#',$p,$a)){
  $target=account($a[1])??fail(404,'User not found.');$sub=$a[2]??'';
  if(!$sub&&$m==='GET')return ['user'=>publicUser($target),'linkedAccounts'=>listEntities('linked',null,$target['id'])];
  if($sub==='block'){$bid='block-'.$id.'-'.$target['id'];if($target['id']===$id)fail(400,'Cannot block yourself.');if($m==='POST'){putEntity('block',['id'=>$bid,'blockedId'=>$target['id']],'',$id);return ['ok'=>true];}if($m==='DELETE'){removeEntity($bid);return ['ok'=>true];}}
  if($sub==='report'&&$m==='POST'){putEntity('report',['targetUserId'=>$target['id'],'reporterId'=>$id,'reason'=>required($b,'reason',500),'details'=>$b['details']??null,'status'=>'open'],'',$id);return ['ok'=>true];}
 }
 if($p==='/api/network'&&$m==='GET')return ['friendships'=>friendships($id),'collabs'=>networkRequests('collab',$id),'accessRequests'=>networkRequests('accessRequest',$id),'linkedAccounts'=>listEntities('linked',null,$id)];
 if($p==='/api/network/badges'&&$m==='GET')return ['badges'=>catalogue()];
 if($p==='/api/network/runtime'&&$m==='GET'){admin();return ['database'=>'mysql','cache'=>'mysql','uptimeSeconds'=>0,'node'=>'PHP '.PHP_VERSION];}
 if($p==='/api/network/friends'&&$m==='GET')return ['friendships'=>friendships($id)];
 if(preg_match('#^/api/network/friends(?:/([^/]+))?$#',$p,$a)){
  $rid=$a[1]??null;
  if($m==='POST'){
   if($rid)$other=account($rid);else{$r=row('SELECT id FROM accounts WHERE username=?',[ltrim(required($b,'username',33),'@')]);$other=$r?account($r['id']):null;}if(!$other)fail(404,'No account has that username.');if($other['id']===$id)fail(400,'Cannot add yourself.');if(blocked($id,$other['id']))fail(403,'User blocked.');
   $pair=[$id,$other['id']];sort($pair);$fid='friend-'.substr(hash('sha256',implode(':',$pair)),0,40);$f=entity($fid,'friendship');if(!$f||$f['status']==='rejected')$f=putEntity('friendship',['id'=>$fid,'fromId'=>$id,'toId'=>$other['id'],'status'=>'pending','respondedAt'=>null]);elseif($f['toId']===$id&&$f['status']==='pending'){$f['status']='accepted';$f['respondedAt']=now();putEntity('friendship',$f);}
   emitUsers($pair,'network:updated',['type'=>'friendship','action'=>'requested']);notify($other['id'],'friend_request','Friend request',$u['displayName'],['friendshipId'=>$fid]);return ['friendship'=>$f+['user'=>publicUser($other),'direction'=>'outgoing']];
  }
  if($rid){$f=mustEntity($rid,'friendship');if(!in_array($id,[$f['fromId'],$f['toId']],true))fail(404,'Friend request not found.');if($m==='PATCH'){if($f['toId']!==$id||$f['status']!=='pending')fail(403,'Only recipient can respond.');$f['status']=enumValue($b['status']??null,['accepted','rejected']);$f['respondedAt']=now();putEntity('friendship',$f);}elseif($m==='DELETE')removeEntity($rid);else return null;emitUsers([$f['fromId'],$f['toId']],'network:updated',['type'=>'friendship']);return ['ok'=>true];}
 }
 if(preg_match('#^/api/network/(collabs|access-requests)(?:/([^/]+))?$#',$p,$a)){
  $kind=$a[1]==='collabs'?'collab':'accessRequest';$key=$a[1]==='collabs'?'collabs':'accessRequests';$rid=$a[2]??null;
  if($m==='GET')return [$key=>networkRequests($kind,$id)];
  if($m==='POST'){$to=required($b,$kind==='collab'?'partnerId':'userId',64);if($to===$id||!account($to))fail(400,'Invalid target.');$v=putEntity($kind,['fromId'=>$id,'toId'=>$to,'status'=>'pending','title'=>$b['title']??null,'note'=>$b['note']??null,'message'=>$b['message']??null,'groupId'=>null,'respondedAt'=>null]);emitUsers([$id,$to],'network:updated',['type'=>$kind]);return [$kind=>$v];}
  if($rid){$v=mustEntity($rid,$kind);if(!in_array($id,[$v['fromId'],$v['toId']],true))fail(404,'Not found.');if($m==='DELETE')removeEntity($rid);elseif($m==='PATCH'){if($v['toId']!==$id)fail(403,'Only recipient can respond.');$v['status']=enumValue($b['status']??null,['accepted','rejected']);$v['respondedAt']=now();putEntity($kind,$v);}else return null;emitUsers([$v['fromId'],$v['toId']],'network:updated',['type'=>$kind]);return ['ok'=>true];}
 }
 if(preg_match('#^/api/network/linked-accounts(?:/([^/]+))?$#',$p,$a)){
  if($m==='GET')return ['linkedAccounts'=>listEntities('linked',null,$id),'platforms'=>['youtube','twitch','kick','instagram','tiktok','other']];
  if($m==='POST'){$url=$b['url']??null;if($url&&!preg_match('#^https?://#',$url))fail(400,'Invalid URL.');return ['linkedAccount'=>putEntity('linked',['userId'=>$id,'platform'=>required($b,'platform',32),'handle'=>required($b,'handle',100),'url'=>$url,'verified'=>false],'',$id)];}
  if(!empty($a[1])){$v=mustEntity($a[1],'linked');if($v['userId']!==$id)fail(404,'Not found.');if($m==='DELETE'){removeEntity($v['id']);return ['ok'=>true];}}
 }
 if($p==='/api/network/roster/suggest-password'&&$m==='GET'){if(!in_array($u['role'],['admin','youtuber'],true))fail(403,'Streamer role required.');return ['password'=>bin2hex(random_bytes(10))];}
 if($p==='/api/network/roster'){
  if(!in_array($u['role'],['admin','youtuber'],true))fail(403,'Streamer role required.');$us=array_values(array_filter(array_map(fn($r)=>account($r['id']),rows('SELECT id FROM accounts')),fn($x)=>$x['ownerStreamerId']===$id));
  if($m==='GET')return ['users'=>array_map('publicUser',$us),'quota'=>['used'=>count($us),'limit'=>10]];
  if($m==='POST'){if(count($us)>=10)fail(403,'Roster is full.');$v=createUser($b);$v['ownerStreamerId']=$id;saveUser($v);identityFor($v['id']);return ['user'=>publicUser($v)];}
 }
 if($p==='/api/notifications'&&$m==='GET'){$ns=array_reverse(listEntities('notification',$id));return ['notifications'=>array_slice($ns,0,limit()),'unread'=>count(array_filter($ns,fn($n)=>!$n['readAt']))];}
 if($p==='/api/notifications/read'&&$m==='POST'){foreach(listEntities('notification',$id)as$n)if(empty($b['id'])||$b['id']===$n['id']){$n['readAt']=now();putEntity('notification',$n,$id,$id);}return ['ok'=>true];}
 if($p==='/api/notifications/preferences'){
  $prefs=entity('notify-'.$id,'notification_prefs')??['inApp'=>true,'email'=>false,'push'=>false,'mentions'=>true,'directMessages'=>true,'moderation'=>true,'quietStart'=>null,'quietEnd'=>null,'timezone'=>'UTC','digestFrequency'=>'off','digestHour'=>8];
  if($m==='PATCH'){$prefs=array_replace($prefs,array_intersect_key($b,$prefs));putEntity('notification_prefs',$prefs+['id'=>'notify-'.$id],$id,$id);}if(in_array($m,['GET','PATCH'],true))return ['preferences'=>$prefs,'vapidPublicKey'=>null];
 }
 if(preg_match('#^/api/notifications/channels/(channel|conversation)/([^/]+)$#',$p,$a)){
  canTarget($a[1]==='channel'?'channels':'conversations',$a[2]);$pid='notif-'.$id.'-'.$a[2];$pref=entity($pid)??['level'=>'all','email'=>false,'push'=>false];if($m==='PUT')$pref=putEntity('notification_channel',array_replace($pref,['level'=>enumValue($b['level']??'all',['all','mentions','none']),'email'=>(bool)($b['email']??false),'push'=>(bool)($b['push']??false),'id'=>$pid]),$a[2],$id);return ['preference'=>$pref];
 }
 return null;
}
function networkRequests(string $kind,string $id): array {return array_map(function($v)use($kind){if($kind==='collab')return $v+['initiator'=>publicUser(account($v['fromId'])),'partner'=>publicUser(account($v['toId']))];return $v+['streamer'=>publicUser(account($v['fromId'])),'user'=>publicUser(account($v['toId']))];},array_values(array_filter(listEntities($kind),fn($v)=>in_array($id,[$v['fromId'],$v['toId']],true))));}
