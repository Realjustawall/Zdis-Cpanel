<?php
declare(strict_types=1);
function b64url(string $s): string {$r=base64_decode(strtr($s,'-_','+/'),true);if($r===false)fail(400,'Invalid encoding.');return $r;}
function validateEnvelope(string $content,string $sender,string $cid): void {
 if(!preg_match('/^e2ee:v1:[A-Za-z0-9_-]{20,65520}$/D',$content))fail(400,'Invalid encrypted envelope.');
 try{$e=decode(b64url(substr($content,8)));}catch(Throwable){fail(400,'Invalid encrypted JSON.');}
 if(($e['v']??0)!==1||($e['sender']??'')!==$sender||($e['alg']??'')!=='ECDH-P256+A256GCM+ES256')fail(400,'Invalid encrypted sender.');
 $i=identityFor($sender);foreach(['encryption','signing']as$purpose)foreach(['x','y']as$coord)if(($e['senderPublic'][$purpose][$coord]??null)!==$i['publicKey'][$purpose][$coord])fail(400,'Identity mismatch.');
 foreach(memberIds($cid)as$mid)if(empty($e['keys'][$mid]['iv'])||empty($e['keys'][$mid]['ciphertext']))fail(400,'Envelope missing a participant.');
 $jwk=$i['publicKey']['signing'];$spki=hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004').b64url($jwk['x']).b64url($jwk['y']);$pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
 $sig=b64url($e['signature']??'');if(strlen($sig)!==64)fail(400,'Invalid signature.');$parts=[];foreach([substr($sig,0,32),substr($sig,32)]as$v){$v=ltrim($v,"\0");if($v===''||(ord($v[0])&128))$v="\0".$v;$parts[]="\x02".chr(strlen($v)).$v;}$der=implode('',$parts);$der="\x30".chr(strlen($der)).$der;unset($e['signature']);
 if(openssl_verify(json($e),$der,$pem,OPENSSL_ALGO_SHA256)!==1)fail(400,'Invalid encrypted signature.');
}
function visibleMessage(array $m): array {
 $m['author']=publicUser(account($m['authorId']));$m['attachments']=array_map(fn($aid)=>entity($aid,'attachment'),$m['attachmentIds']??[]);$m['attachments']=array_values(array_filter($m['attachments']));foreach($m['attachments']as&$att)unset($att['path'],$att['uploaderId'],$att['messageId']);unset($att,$m['attachmentIds']);
 if($m['replyToId']){$r=entity($m['replyToId'],'message');if($r&&!$r['deleted']){$a=account($r['authorId']);$m['replyTo']=['id'=>$r['id'],'username'=>$a['username']??'','displayName'=>$a['displayName']??'','preview'=>$r['type']==='encrypted'?'Encrypted message':mb_substr($r['content'],0,160),'encrypted'=>$r['type']==='encrypted','encryptedContent'=>$r['type']==='encrypted'?$r['content']:null,'authorId'=>$r['authorId'],'conversationId'=>$r['conversationId']];}}
 if($m['poll']){unset($m['poll']['voteTimes']);$uid=user()['id'];$m['poll']['viewerOptionIds']=[];foreach($m['poll']['options']as&$o){if(in_array($uid,$o['userIds'],true))$m['poll']['viewerOptionIds'][]=$o['id'];$o['votes']=count($o['userIds']);if($m['poll']['anonymous'])$o['userIds']=[];}unset($o);if(!$m['poll']['closed'])$m['poll']['correctOptionIds']=[];}
 return $m;
}
function targetMessages(string $type,string $id): array {canTarget($type,$id);return array_values(array_filter(listEntities('message',$id),fn($m)=>!$m['deleted']&&(!$m['expiresAt']||$m['expiresAt']>now())&&messageAccessible($m)));}
function targetModerate(string $type,array $t): bool {return $type==='channels'?permissions($t['groupId'])['deleteAnyMessage']:($t['ownerId']===user()['id']&&$t['type']==='group_dm');}
function emitMessage(string $type,string $id,string $event,array $m): void {foreach(messageAudience($m,$type,$id)as$mid){$v=visibleMessage($m);if($v['poll']){$v['poll']['viewerOptionIds']=[];foreach($m['poll']['options']as$o)if(in_array($mid,$o['userIds'],true))$v['poll']['viewerOptionIds'][]=$o['id'];}emitUsers([$mid],$event,['message'=>$v]);}integrationEvent($type==='channels'?'channel':'conversation',$id,str_replace(':','.',$event),['messageId'=>$m['id']],$m['id']);}
function sendMessage(string $type,string $id,array $b): array {
 $t=canTarget($type,$id);$u=user();$uid=$u['id'];$settings=settings();$encrypted=(bool)($b['encrypted']??false);$content=(string)($b['content']??'');$ids=array_values(array_unique($b['attachmentIds']??[]));
 if(mb_strlen($content)>($encrypted?90000:10000)||(!$ids&&trim($content)===''&&!isset($b['poll'])))fail(400,'Message must have text or an attachment.');if(count($ids)>10)fail(400,'Too many attachments.');
 if($type==='conversations'){
  foreach(memberIds($id)as$mid)if(blocked($uid,$mid))fail(403,'A conversation participant is blocked.');
  if($settings['e2ee_required_for_dms']&&!$encrypted)fail(400,'This conversation requires encrypted messages.');if($encrypted)validateEnvelope($content,$uid,$id);
 }elseif($encrypted)fail(400,'Encryption is only available in conversations.');
 if($type==='channels'){cp($t,$t['type']==='forum'?'sendMessagesInThreads':'sendMessages');if($ids)cp($t,'attachFiles');if(!empty($b['poll']))cp($t,'sendPolls');if(!empty($b['tts']))cp($t,'sendTtsMessages');if($b['replyToId']??null){$parent=mustEntity($b['replyToId'],'message');requireMessageAccess($parent,true);}inspectMessagePolicies($t,$uid,$content,count($ids));}
 $client=isset($b['clientId'])?required($b,'clientId',64):null;$msgId=$client?'msg-'.substr(hash('sha256',$uid.':'.$client),0,48):uid();
 if($existing=entity($msgId,'message')){if(($existing['channelId']??$existing['conversationId'])!==$id)fail(409,'Idempotency key reused on another target.');return visibleMessage($existing);}
 throttle('message:'.$uid,(int)$settings['spam_messages_per_30s'],30);
 return transaction(function()use($type,$id,$t,$uid,$b,$msgId,$client,$content,$ids,$encrypted){
  // Lock the target to serialize slowmode, idempotency and attachment ownership checks.
  entity($id,null,true);if($existing=entity($msgId,'message'))return visibleMessage($existing);
  if($type==='channels'){$mem=member($t['groupId'],$uid);if(($mem['timeoutUntil']??0)>now())fail(403,'You are timed out.');$slow=(int)$t['slowmode'];if($slow&&!targetModerate($type,$t)){$last=row('SELECT created_at FROM entities WHERE kind=? AND parent_id=? AND owner_id=? ORDER BY created_at DESC LIMIT 1',['message',$id,$uid]);if($last&&now()-(int)$last['created_at']<$slow*1000)fail(429,'Slowmode: wait before sending.');}}
  foreach($ids as$aid){$att=mustEntity($aid,'attachment');if($att['uploaderId']!==$uid||!empty($att['messageId']))fail(403,'Attachment is not available.');if($encrypted&&$att['mime']!=='application/vnd.zdis.encrypted')fail(400,'Encrypted messages require encrypted files.');}
  $reply=$b['replyToId']??null;if($reply){$r=mustEntity($reply,'message');if(($r['channelId']??$r['conversationId'])!==$id)fail(400,'Reply belongs to a different target.');}
  $msg=putEntity('message',['id'=>$msgId,'clientId'=>$client,'channelId'=>$type==='channels'?$id:null,'conversationId'=>$type==='conversations'?$id:null,'authorId'=>$uid,'content'=>$content,'type'=>$encrypted?'encrypted':(isset($b['poll'])?'poll':(!empty($b['tts'])?'tts':'user')),'replyToId'=>$reply,'replyTo'=>null,'pinned'=>false,'editedAt'=>null,'expiresAt'=>!empty($b['expiresInSeconds'])?now()+max(10,min(31536000,(int)$b['expiresInSeconds']))*1000:null,'deleted'=>false,'attachmentIds'=>$ids,'reactions'=>[],'mentionedUserIds'=>[],'threadReplyCount'=>0,'poll'=>$b['poll']??null],$id,$uid);
  foreach($ids as$aid){$att=entity($aid,'attachment');$att['messageId']=$msgId;putEntity('attachment',$att,'',$uid);}
  if($type==='conversations'){$t['updatedAt']=now();putEntity('conversation',$t,'',$t['ownerId']);}
  emitMessage($type,$id,'message:created',$msg);foreach(audience($type,$id)as$mid)if($mid!==$uid&&$type==='conversations')notify($mid,'direct_message','New message',publicUser(account($uid))['displayName'],['conversationId'=>$id,'messageId'=>$msgId]);
  if($type==='channels')afterBuiltinMessage($t,$msg);return visibleMessage($msg);
 });
}
function newPoll(array $b): array {
 $q=required($b,'question',300);$options=$b['options']??[];if(count($options)<2||count($options)>10)fail(400,'Polls need 2 to 10 options.');$os=[];foreach($options as$o){$s=is_string($o)?$o:($o['label']??'');$os[]=['id'=>uid(),'label'=>required(['label'=>$s],'label',200),'votes'=>0,'userIds'=>[]];}
 if(count(array_unique(array_map(fn($o)=>mb_strtolower($o['label']),$os)))!==count($os))fail(400,'Poll options must be unique.');$correct=$b['correctOptionIndexes']??(isset($b['correctOptionIndex'])?[$b['correctOptionIndex']]:[]);foreach($correct as$index)if(!is_int($index)||!isset($os[$index]))fail(400,'Invalid correct answer.');if(($b['examMode']??false)&&(($b['multiple']??false)||count($correct)!==1))fail(400,'Exam mode needs exactly one correct answer.');
 return ['id'=>uid(),'creatorId'=>user()['id'],'question'=>$q,'multiple'=>(bool)($b['multiple']??false),'anonymous'=>(bool)($b['anonymous']??false),'examMode'=>(bool)($b['examMode']??false),'closesAt'=>$b['closesAt']??null,'closed'=>false,'viewerOptionIds'=>[],'correctOptionIds'=>array_map(fn($i)=>$os[$i]['id'],$correct),'voteTimes'=>[],'options'=>$os];
}
function messageRoutes(string $method,string $path,array $b): ?array {
 if($path==='/api/unread'&&$method==='GET'){
  $out=['channels'=>[],'conversations'=>[]];$id=user()['id'];foreach(rows('SELECT scope_id FROM memberships WHERE user_id=?',[$id])as$r){$scope=$r['scope_id'];$g=entity($scope,'group');$targets=$g?listEntities('channel',$scope):(($c=entity($scope,'conversation'))?[$c]:[]);foreach($targets as$t){$type=$g?'channels':'conversations';try{canTarget($type,$t['id']);}catch(HttpError){continue;}$read=entity('read-'.$id.'-'.$t['id']);$unread=count(array_filter(targetMessages($type,$t['id']),fn($m)=>$m['createdAt']>($read['at']??0)&&$m['authorId']!==$id));$out[$type][]=['id'=>$t['id'],'groupId'=>$g?$g['id']:null,'unread'=>$unread,'mentions'=>0];}}return $out;
 }
 if(in_array($path,['/api/search','/api/saved'],true)&&$method==='GET'){
  $id=user()['id'];$q=(string)($_GET['q']??'');$found=[];$saved=array_column(listEntities('saved',null,$id),'messageId');foreach(array_reverse(listEntities('message'))as$m){if($m['deleted']||($m['expiresAt']&&$m['expiresAt']<now()))continue;if($path==='/api/saved'&&!in_array($m['id'],$saved,true))continue;if($path==='/api/search'&&($m['type']==='encrypted'||mb_stripos($m['content'],$q)===false))continue;try{canTarget($m['channelId']?'channels':'conversations',$m['channelId']??$m['conversationId']);}catch(HttpError){continue;}if(!messageAccessible($m))continue;$found[]=visibleMessage($m);if(count($found)>=limit())break;}return ['messages'=>$found];
 }
 if(!preg_match('#^/api/(channels|conversations)/([^/]+)/(messages|pins|read|draft|scheduled|polls)(?:/([^/]+))?(?:/(.*))?$#',$path,$a))return null;
 $type=$a[1];$id=$a[2];$sub=$a[3];$mid=$a[4]??null;$action=$a[5]??'';$t=canTarget($type,$id);$uid=user()['id'];
 if($sub==='read'&&$method==='POST'){putEntity('read',['id'=>'read-'.$uid.'-'.$id,'at'=>now(),'messageId'=>$b['messageId']??null],$id,$uid);return ['ok'=>true];}
 if($sub==='draft'){
  $did='draft-'.$uid.'-'.$id;if($method==='GET')return ['draft'=>entity($did,'draft')];if($method==='DELETE'){removeEntity($did);return ['ok'=>true];}if($method==='PUT'){if($type==='conversations'&&settings()['e2ee_required_for_dms'])fail(400,'Server drafts disabled for encrypted conversations.');return ['draft'=>putEntity('draft',['id'=>$did,'content'=>required($b,'content',10000,0),'replyToId'=>$b['replyToId']??null],$id,$uid)];}
 }
 if($sub==='scheduled'){
  if($method==='GET')return ['scheduled'=>listEntities('scheduled',$id,$uid)];if($method==='POST'){$sendAt=(int)($b['sendAt']??0);if($sendAt<now()+1000||$sendAt>now()+31536000000)fail(400,'Invalid schedule time.');if($type==='conversations'&&settings()['e2ee_required_for_dms']){if(empty($b['encrypted']))fail(400,'Encrypted content required.');validateEnvelope((string)$b['content'],$uid,$id);}return ['scheduled'=>putEntity('scheduled',['targetType'=>$type,'targetId'=>$id,'content'=>required($b,'content',90000),'encrypted'=>(bool)($b['encrypted']??false),'sendAt'=>$sendAt,'status'=>'pending','error'=>null,'userId'=>$uid],$id,$uid)];}
  if($method==='DELETE'&&$mid){$s=mustEntity($mid,'scheduled');if($s['userId']!==$uid||$s['targetId']!==$id)fail(404,'Not found.');removeEntity($mid);return ['ok'=>true];}
 }
 if(($sub==='pins'||($sub==='messages'&&!$mid))&&$method==='GET'){
  $all=targetMessages($type,$id);if($type==='channels')cp($t,'readMessageHistory');if($sub==='pins')$all=array_values(array_filter($all,fn($m)=>$m['pinned']));
  if(!empty($_GET['before'])){$before=mustEntity((string)$_GET['before'],'message');$all=array_values(array_filter($all,fn($m)=>[$m['createdAt'],$m['id']]<[$before['createdAt'],$before['id']]));}
  $has=count($all)>limit();$all=array_slice($all,-limit());return ['messages'=>array_map('visibleMessage',$all),'hasMore'=>$has];
 }
 if($sub==='polls'&&$method==='POST'){
  if(!empty($b['encrypted']))return ['message'=>sendMessage($type,$id,$b)];$poll=newPoll($b);$b['poll']=$poll;$b['content']=$poll['question'];return ['message'=>sendMessage($type,$id,$b)];
 }
 if($sub==='messages'&&!$mid&&$method==='POST'){http_response_code(201);return ['message'=>sendMessage($type,$id,$b)];}
 if($sub!=='messages'||!$mid)return null;
 $msg=mustEntity($mid,'message');if(($msg['channelId']??$msg['conversationId'])!==$id||$msg['deleted']||($msg['expiresAt']&&$msg['expiresAt']<now()))fail(404,'Message not found.');$mine=$msg['authorId']===$uid;$manage=targetModerate($type,$t);requireMessageAccess($msg,in_array($method,['POST','PUT','PATCH'],true));if($type==='channels'&&str_starts_with($action,'reactions/'))cp($t,'addReactions');
 if($action==='thread'&&$method==='GET')return ['messages'=>array_map('visibleMessage',array_values(array_filter(targetMessages($type,$id),fn($x)=>$x['replyToId']===$mid)))];
 if($action==='report'&&$method==='POST'){putEntity('report',['messageId'=>$mid,'reporterId'=>$uid,'reason'=>required($b,'reason',500),'details'=>$b['details']??null,'status'=>'open'],$id,$uid);return ['ok'=>true];}
 if($action==='saved'&&$method==='PUT'){$sid='saved-'.$uid.'-'.$mid;if($b['saved']??true)putEntity('saved',['id'=>$sid,'messageId'=>$mid],$id,$uid);else removeEntity($sid);return ['ok'=>true];}
 if($action==='poll/analytics'&&$method==='GET')fail(501,'Poll analytics have not been ported.','not_implemented');
 return transaction(function()use($method,$action,$msg,$mid,$id,$type,$b,$uid,$mine,$manage){
  $msg=entity($mid,'message',true);
  if($action===''){
   if($method==='GET')return ['message'=>visibleMessage($msg)];
   if($method==='PATCH'){if(!$mine)fail(403,'Only the author can edit.');$content=required($b,'content',90000);if($msg['type']==='encrypted')validateEnvelope($content,$uid,$id);elseif(mb_strlen($content)>10000)fail(400,'Message too long.');if($type==='channels'){cp(canTarget($type,$id),'sendMessages');inspectMessagePolicies(canTarget($type,$id),$uid,$content);}$window=(int)settings()['message_edit_window_minutes'];if($window>0&&now()-$msg['createdAt']>$window*60000)fail(403,'Message edit window expired.');$msg['content']=$content;$msg['editedAt']=now();}
   elseif($method==='DELETE'){if(!$mine&&!$manage)fail(403,'Cannot delete this message.');$msg['deleted']=true;$msg['content']='';putEntity('message',$msg,$id,$msg['authorId']);$deletion=['messageId'=>$mid,'channelId'=>$msg['channelId'],'conversationId'=>$msg['conversationId']];emitUsers(messageAudience($msg,$type,$id),'message:deleted',$deletion);integrationEvent($type==='channels'?'channel':'conversation',$id,'message.deleted',$deletion,$mid);return ['ok'=>true,'deletion'=>$deletion];}
   else return null;
  }elseif($action==='pin'&&$method==='PUT'){if($type==='channels'&&!$manage)fail(403,'Cannot pin messages.');$msg['pinned']=(bool)($b['pinned']??true);}
  elseif(str_starts_with($action,'reactions/')&&$method==='PUT'){
   $emoji=rawurldecode(substr($action,10));if(mb_strlen($emoji)>64||$emoji==='')fail(400,'Invalid reaction.');$exists=false;foreach($msg['reactions']as&$r)if($r['emoji']===$emoji){$exists=true;if(in_array($uid,$r['userIds'],true))$r['userIds']=array_values(array_diff($r['userIds'],[$uid]));else $r['userIds'][]=$uid;$r['count']=count($r['userIds']);}unset($r);if(!$exists)$msg['reactions'][]=['emoji'=>$emoji,'count'=>1,'userIds'=>[$uid]];$msg['reactions']=array_values(array_filter($msg['reactions'],fn($r)=>$r['count']>0));putEntity('message',$msg,$id,$msg['authorId']);updateStarboard($msg);emitUsers(messageAudience($msg,$type,$id),'message:reaction',['messageId'=>$mid,'channelId'=>$msg['channelId'],'conversationId'=>$msg['conversationId'],'reactions'=>$msg['reactions']]);integrationEvent($type==='channels'?'channel':'conversation',$id,'message.reaction',['messageId'=>$mid],$mid);return ['reactions'=>$msg['reactions']];
  }elseif($action==='poll-vote'&&$method==='PUT'){
   if(!$msg['poll']||$msg['poll']['closed']||($msg['poll']['closesAt']&&$msg['poll']['closesAt']<now()))fail(400,'Poll is closed.');$ids=array_values(array_unique($b['optionIds']??[]));if(!$msg['poll']['multiple']&&count($ids)>1)fail(400,'Only one answer allowed.');foreach($ids as$oid)if(!in_array($oid,array_column($msg['poll']['options'],'id'),true))fail(400,'Invalid option.');foreach($msg['poll']['options']as&$o){$o['userIds']=array_values(array_diff($o['userIds'],[$uid]));if(in_array($o['id'],$ids,true))$o['userIds'][]=$uid;$o['votes']=count($o['userIds']);}unset($o);
  }elseif($action==='poll/close'&&$method==='POST'){if(!$mine&&!$manage)fail(403,'Cannot close poll.');$msg['poll']['closed']=true;}
  elseif($action==='poll'&&$method==='PATCH'){if(!$mine&&!$manage)fail(403,'Cannot edit poll.');$msg['poll']=newPoll($b);$msg['content']=$msg['poll']['question'];}
  else return null;
  putEntity('message',$msg,$id,$msg['authorId']);emitMessage($type,$id,'message:updated',$msg);return ['message'=>visibleMessage($msg)];
 });
}
