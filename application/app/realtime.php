<?php
declare(strict_types=1);
function voiceAccess(string $room,?string $uid=null): array {$uid??=user()['id'];if(str_starts_with($room,'dm:'))return canTarget('conversations',substr($room,3),$uid);$c=canTarget('channels',$room,$uid);if(!in_array($c['type'],['voice','stage'],true))fail(400,'This is not a voice channel.');return $c;}
function voiceAudience(string $room): array {return str_starts_with($room,'dm:')?audience('conversations',substr($room,3)):audience('channels',$room);}
function voicePeers(string $room): array {return array_map(fn($v)=>['userId'=>$v['userId']]+$v['state'],array_values(array_filter(listEntities('voice',$room),fn($v)=>$v['expiresAt']>now())));}
function leaveVoice(array $v): void {removeEntity($v['id']);$room=$v['channelId'];emitUsers(array_column(voicePeers($room),'userId'),'voice:peer-left',['channelId'=>$room,'userId'=>$v['userId']]);emitUsers(voiceAudience($room),'voice:state',['channelId'=>$room,'participants'=>voicePeers($room)]);}
function eventVisible(array $payload): bool {
 $m=$payload['message']??(isset($payload['messageId'])?entity($payload['messageId'],'message'):null);if($m&&!messageAccessible($m))return false;if(isset($payload['postId'])){$post=entity($payload['postId'],'post');if($post&&!messageAccessible(mustEntity($post['rootMessageId'],'message')))return false;}$cid=$m['channelId']??$payload['channelId']??null;$conv=$m['conversationId']??$payload['conversationId']??null;
 try{if($cid){if(str_starts_with($cid,'dm:'))canTarget('conversations',substr($cid,3));else canTarget('channels',$cid);}elseif($conv)canTarget('conversations',$conv);elseif(isset($payload['groupId']))groupAccess($payload['groupId']);return true;}catch(HttpError){return false;}
}
function realtimeRoutes(string $method,string $path,array $b): ?array {
 if($path!=='/api/realtime')return null;$u=user();$id=$u['id'];$connection=(string)($method==='GET'?($_GET['connection']??''):($b['connection']??''));if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$connection))fail(400,'Invalid connection.');
 if($method==='GET'){
  throttle('poll:'.$id,240,60);
  foreach(listEntities('voice')as$v){if($v['expiresAt']<now())leaveVoice($v);elseif($v['userId']===$id&&$v['connection']===$connection){try{voiceAccess($v['channelId']);$v['expiresAt']=now()+30000;putEntity('voice',$v,$v['channelId'],$id);}catch(HttpError){leaveVoice($v);}}}
  // Each inbox is filtered again at read time so revoking membership also revokes queued content.
  $cursor=isset($_GET['cursor'])?max(0,(int)$_GET['cursor']):(int)(row('SELECT MAX(id) AS n FROM events WHERE user_id=?',[$id])['n']??0);
  $events=[];$rs=rows('SELECT id,name,payload FROM events WHERE user_id=? AND id>? ORDER BY id LIMIT 100',[$id,$cursor]);foreach($rs as$r){$cursor=(int)$r['id'];$payload=decode($r['payload']);if(eventVisible($payload))$events[]=['name'=>$r['name'],'payload'=>$payload];}
  $voice=[];foreach(listEntities('voice')as$v){try{voiceAccess($v['channelId']);$voice[$v['channelId']]=voicePeers($v['channelId']);}catch(HttpError){}}
  return ['cursor'=>$cursor,'events'=>$events,'voice'=>(object)$voice,'more'=>count($rs)===100];
 }
 if($method!=='POST')return null;throttle('events:'.$id,500,60);$e=required($b,'event',80);$p=$b['payload']??[];if(!is_array($p))fail(400,'Invalid event payload.');
 if($e==='rooms:refresh')return ['ok'=>true];
 if($e==='presence:set'){$u['presence']=enumValue($p['presence']??null,['online','idle','dnd','offline']);saveUser($u);emitUsers(array_column(rows('SELECT id FROM accounts'),'id'),'presence:update',['userId'=>$id,'presence'=>$u['presence']]);return ['ok'=>true];}
 if(in_array($e,['typing:start','typing:stop'],true)){
  $type=!empty($p['channelId'])?'channels':'conversations';$target=(string)($p['channelId']??$p['conversationId']??'');canTarget($type,$target);emitUsers(array_values(array_diff(audience($type,$target),[$id])),$e,['userId'=>$id,'channelId'=>$type==='channels'?$target:null,'conversationId'=>$type==='conversations'?$target:null]);return ['ok'=>true];
 }
 if($e==='voice:join'){
  $policy=accountAccess($id);if(!$policy['connectVoice']||!$policy['speak']||!$policy['video']||!$policy['screenShare'])fail(403,'Restricted publication permissions require an SFU; mesh calls cannot enforce these restrictions.');if(!settings()['feature_voice_calls'])fail(403,'Voice calls disabled.');$room=required($p,'channelId',80);voiceAccess($room);
  return transaction(function()use($room,$id,$p,$connection){
   $target=str_starts_with($room,'dm:')?substr($room,3):$room;entity($target,null,true);foreach(listEntities('voice',null,$id)as$old)leaveVoice($old);$peers=voicePeers($room);if(count($peers)>=8)fail(409,'Voice room is full (8 max).');
   $state=['muted'=>(bool)($p['muted']??false),'deafened'=>false,'video'=>(bool)($p['video']??false),'screen'=>false,'speaking'=>false,'priority'=>false,'joinedAt'=>now()];putEntity('voice',['id'=>'voice-'.$id,'userId'=>$id,'channelId'=>$room,'connection'=>$connection,'state'=>$state,'expiresAt'=>now()+30000],$room,$id);
   $audience=voiceAudience($room);emitUsers(array_column($peers,'userId'),'voice:peer-joined',['channelId'=>$room,'userId'=>$id,'state'=>$state]);emitUsers($audience,'voice:state',['channelId'=>$room,'participants'=>voicePeers($room)]);
   if(!$peers&&str_starts_with($room,'dm:'))emitUsers(array_values(array_diff($audience,[$id])),'voice:incoming',['channelId'=>$room,'conversationId'=>substr($room,3),'fromUserId'=>$id,'video'=>$state['video'],'startedAt'=>$state['joinedAt']]);return ['ok'=>true,'channelId'=>$room,'peers'=>$peers,'max'=>8];
  });
 }
 if($e==='voice:decline'){$room=required($p,'channelId',80);voiceAccess($room);emitUsers(voiceAudience($room),'voice:declined',['channelId'=>$room,'userId'=>$id]);return ['ok'=>true];}
 if(in_array($e,['voice:leave','connection:close'],true)){
  $v=entity('voice-'.$id,'voice');if($v&&$v['connection']===$connection)leaveVoice($v);return ['ok'=>true];
 }
 if(in_array($e,['voice:offer','voice:answer','voice:ice','voice:update'],true)){
  $v=entity('voice-'.$id,'voice');if(!$v||$v['connection']!==$connection||$v['expiresAt']<now())fail(403,'Join a voice room first.');$room=$v['channelId'];voiceAccess($room);
  if($e==='voice:update'){
   foreach(['muted','deafened','video','screen','speaking']as$key)if(isset($p[$key]))$v['state'][$key]=(bool)$p[$key];putEntity('voice',$v,$room,$id);emitUsers(voiceAudience($room),'voice:state',['channelId'=>$room,'participants'=>voicePeers($room)]);return ['ok'=>true];
  }
  $to=required($p,'to',64);$target=entity('voice-'.$to,'voice');if(!$target||$target['channelId']!==$room||$target['expiresAt']<now())fail(403,'Peer is not in the same voice room.');voiceAccess($room,$to);if(strlen(json($p['payload']??null))>65000)fail(413,'Signalling payload too large.');emitUsers([$to],$e,['channelId'=>$room,'from'=>$id,'payload'=>$p['payload']??null]);return ['ok'=>true];
 }
 fail(501,'This realtime event has not been ported.','not_implemented');
}
