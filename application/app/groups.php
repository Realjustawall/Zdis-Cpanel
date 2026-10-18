<?php
declare(strict_types=1);
function newChannel(string $g,array $b,bool $bootstrap=false): array {
 $policy=accountAccess(user()['id']);if(!$bootstrap&&count(listEntities('channel',null,user()['id']))>=$policy['maxCreatedChannels']&&user()['role']!=='admin')fail(403,'Channel creation limit reached.');if(count(listEntities('channel',$g))>=$policy['maxChannelsPerGroup'])fail(403,'Group channel limit reached.');
 $name=required($b,'name',80);$type=enumValue($b['type']??'text',['text','voice','forum','stage','announcement']);$category=$b['categoryId']??null;if($category&&mustEntity($category,'category')['groupId']!==$g)fail(400,'Invalid category.');
 return putEntity('channel',['groupId'=>$g,'categoryId'=>$category,'name'=>$name,'topic'=>$b['topic']??null,'type'=>$type,'position'=>$b['position']??count(listEntities('channel',$g)),'isPrivate'=>(bool)($b['isPrivate']??false),'slowmode'=>max(0,min(21600,(int)($b['slowmode']??0))),'voiceStatus'=>null,'permissionsSynced'=>true],$g,$bootstrap?'':user()['id']);
}
function joinGroup(array $g,string $id): void {
 if(entity('ban-'.$g['id'].'-'.$id))fail(403,'You are banned from this group.');if(member($g['id'],$id))return;guardJoin($g['id'],$id);addMember($g['id'],$id);membershipBotEvent($g['id'],$id,'join');emitUsers([$id],'group:joined',['group'=>$g]);emitGroup($g['id'],'group:member-added',['groupId'=>$g['id'],'member'=>publicUser(account($id))]);
}
function groupDetail(string $id): array {$g=groupAccess($id);$chs=array_values(array_filter(listEntities('channel',$id),function($c){try{canTarget('channels',$c['id']);return true;}catch(HttpError){return false;}}));usort($chs,fn($a,$b)=>$a['position']<=>$b['position']);return ['group'=>groupView($g),'channels'=>$chs,'categories'=>listEntities('category',$id),'members'=>members($id),'invites'=>permissions($id)['createInvite']?listEntities('invite',$id):[],'roles'=>listEntities('role',$id),'permissions'=>permissions($id)];}
function groupRoutes(string $m,string $p,array $b): ?array {
 if(!str_starts_with($p,'/api/groups'))return null;$u=user();$id=$u['id'];
 if($p==='/api/groups'){
  if($m==='GET'){$gs=[];foreach(rows('SELECT scope_id FROM memberships WHERE user_id=?',[$id])as$r){$g=entity($r['scope_id'],'group');if($g)$gs[]=groupView($g);}return ['groups'=>$gs];}
  if($m==='POST'){
   if($u['role']!=='admin'&&!settings()['members_can_create_groups']&&!(settings()['youtubers_can_create_groups']&&$u['role']==='youtuber'))fail(403,'Group creation disabled.');
   if(count(listEntities('group',null,$id))>=accountAccess($id)['maxOwnedGroups']&&$u['role']!=='admin')fail(403,'Group limit reached.');
   return transaction(function()use($b,$id){sql('SELECT id FROM accounts WHERE id=? FOR UPDATE',[$id]);
   $g=putEntity('group',['name'=>required($b,'name',80),'slug'=>uid(),'description'=>$b['description']??null,'iconUrl'=>null,'accentColor'=>'#5865f2','discoveryRequested'=>false,'discoverable'=>false,'require2faModeration'=>false,'ownerId'=>$id], '',$id);
   addMember($g['id'],$id,['memberRole'=>'owner']);ensureEveryone($g['id']);newChannel($g['id'],['name'=>'general','topic'=>'Everything else goes here'],true);newChannel($g['id'],['name'=>'announcements','topic'=>'Read-only-ish updates for the team'],true);newChannel($g['id'],['name'=>'Voice Lounge','type'=>'voice'],true);audit('group.created',$g['id']);http_response_code(201);return ['group'=>groupView($g)];
   });
  }
 }
 if($p==='/api/groups/discover'&&$m==='GET')return ['groups'=>array_map('groupView',array_values(array_filter(listEntities('group'),fn($g)=>($g['discoverable']??false)&&(!($_GET['search']??'')||mb_stripos($g['name'],$_GET['search'])!==false))))];
 if(preg_match('#^/api/groups/(?:discover/([^/]+)/join|join/([^/]+))$#',$p,$a)&&$m==='POST'){
  if(!empty($a[1])){$g=mustEntity($a[1],'group');if(!$g['discoverable'])fail(404,'Group not found.');joinGroup($g,$id);}else{
   $code=$a[2];$inv=null;foreach(listEntities('invite')as$i)if(hash_equals($i['code'],$code)){$inv=$i;break;}if(!$inv)fail(404,'Invite not found.');$g=mustEntity($inv['groupId'],'group');
   transaction(function()use($inv,$g,$id){$i=entity($inv['id'],'invite',true);if(($i['expiresAt']&&$i['expiresAt']<now())||($i['maxUses']&&$i['uses']>=$i['maxUses']))fail(400,'Invite expired or used.');if(!member($g['id'],$id)){$i['uses']++;putEntity('invite',$i,$g['id'],$i['createdBy']);joinGroup($g,$id);}});
  }return ['group'=>groupView($g)];
 }
 if(!preg_match('#^/api/groups/([^/]+)(?:/(.*))?$#',$p,$a))return null;$gid=$a[1];$sub=$a[2]??'';$g=groupAccess($gid);
 if($sub===''){
  if($m==='GET')return groupDetail($gid);
  if($m==='PATCH'){groupAccess($gid,true);$patch=array_intersect_key($b,array_flip(['name','description','accentColor','discoveryRequested','require2faModeration']));if(isset($patch['name']))required($patch,'name',80);$g=putEntity('group',array_replace($g,$patch),'',$g['ownerId']);emitGroup($gid,'group:updated',['group'=>groupView($g)]);return ['group'=>groupView($g)];}
  if($m==='DELETE'){if($g['ownerId']!==$id&&$u['role']!=='admin')fail(403,'Only owner can delete.');emitGroup($gid,'group:deleted',['groupId'=>$gid]);sql('DELETE FROM entities WHERE parent_id=?',[$gid]);sql('DELETE FROM memberships WHERE scope_id=?',[$gid]);removeEntity($gid);return ['ok'=>true];}
 }
 if($sub==='icon'&&$m==='PUT'){groupAccess($gid,true);$att=mustEntity(required($b,'attachmentId',64),'attachment');if($att['uploaderId']!==$id||!str_starts_with($att['mime'],'image/'))fail(403,'Invalid image.');$g['iconUrl']=$att['url'];putEntity('group',$g,'',$g['ownerId']);return ['group'=>groupView($g)];}
 if($sub==='members'&&$m==='POST'){groupAccess($gid,true);joinGroup($g,required($b,'userId',64));return ['ok'=>true];}
 if(preg_match('#^members/([^/]+)(?:/roles/([^/]+)|/(timeout))?$#',$sub,$a)){
  $mid=$a[1];$member=member($gid,$mid)??fail(404,'Member not found.');
  if($m==='DELETE'&&!isset($a[2])){if($mid!==$id){groupAccess($gid,true);requireOutrank($gid,$mid);}if($mid===$g['ownerId'])fail(400,'Owner cannot leave.');emitGroup($gid,'group:member-removed',['groupId'=>$gid,'userId'=>$mid]);sql('DELETE FROM memberships WHERE scope_id=? AND user_id=?',[$gid,$mid]);emitUsers([$mid],'group:left',['groupId'=>$gid]);return ['ok'=>true];}
  if($m==='PATCH'||$m==='PUT'){
   if($mid!==$id||isset($b['memberRole'])||isset($b['muted'])||!empty($a[2])||!empty($a[3]))groupAccess($gid,true);
   if(isset($b['memberRole'])&&$g['ownerId']!==$id&&!capability('admin'))fail(403,'Only owner may change base membership roles.');if($mid!==$id)requireOutrank($gid,$mid);
   if($mid===$g['ownerId']&&(isset($b['memberRole'])||isset($b['muted'])))fail(403,'Cannot moderate owner.');
   if(!empty($a[2])){$role=mustEntity($a[2],'role');if($role['groupId']!==$gid)fail(404,'Role not found.');$roles=array_values(array_filter($member['serverRoles'],fn($r)=>$r['id']!==$role['id']));if($b['assigned']??$b['enabled']??true)$roles[]=$role;$member['serverRoles']=$roles;}
   elseif(!empty($a[3])){$member['timeoutUntil']=($b['durationSeconds']??0)>0?now()+min(2419200,(int)$b['durationSeconds'])*1000:null;}
   else {$patch=array_intersect_key($b,array_flip(['nickname','muted','memberRole']));if(isset($patch['memberRole']))enumValue($patch['memberRole'],['member','moderator','admin']);$member=array_replace($member,$patch);}
   addMember($gid,$mid,$member);emitGroup($gid,'group:member-updated',['groupId'=>$gid,'userId'=>$mid]);return ['ok'=>true,'member'=>publicUser(account($mid))+$member];
  }
 }
 if($sub==='channels'&&$m==='POST'){groupAccess($gid,true);$c=newChannel($gid,$b);emitGroup($gid,'channel:created',['channel'=>$c]);return ['channel'=>$c];}
 if(preg_match('#^channels/([^/]+)(?:/(members|permissions)(?:/([^/]+))?)?$#',$sub,$a)){
  $cid=$a[1];$c=mustEntity($cid,'channel');if($c['groupId']!==$gid)fail(404,'Channel not found.');groupAccess($gid,true);
  if(!empty($a[2])){
   if($a[2]==='members'){
    if($m==='GET')return ['members'=>members($cid)];if($m==='POST'){addMember($cid,required($b,'userId',64));return ['ok'=>true];}if($m==='DELETE'){sql('DELETE FROM memberships WHERE scope_id=? AND user_id=?',[$cid,$a[3]??'']);return ['ok'=>true];}
   }else{if($m==='GET')return ['overrides'=>listEntities('override',$cid)];fail(501,'Granular channel permission overrides are not ported in this edition.','not_implemented');}
  }
  if($m==='PATCH'){$patch=array_intersect_key($b,array_flip(['name','topic','position','categoryId','isPrivate','slowmode']));if(isset($patch['categoryId'])&&mustEntity($patch['categoryId'],'category')['groupId']!==$gid)fail(400,'Invalid category.');$c=putEntity('channel',array_replace($c,$patch),$gid,$g['ownerId']);emitGroup($gid,'channel:updated',['channel'=>$c]);return ['channel'=>$c];}
  if($m==='DELETE'){removeEntity($cid);sql('DELETE FROM memberships WHERE scope_id=?',[$cid]);emitGroup($gid,'channel:deleted',['groupId'=>$gid,'channelId'=>$cid]);return ['ok'=>true];}
 }
 if(preg_match('#^(categories|roles|invites|expressions)(?:/([^/]+))?$#',$sub,$a)){
  $collection=$a[1];$rid=$a[2]??null;$kind=['categories'=>'category','roles'=>'role','invites'=>'invite','expressions'=>'expression'][$collection];
  if($m==='GET')return [$collection=>listEntities($kind,$gid)];groupAccess($gid,true);
  if($collection==='roles'&&$rid==='reorder'&&$m==='PUT'){foreach($b['roleIds']??[] as $pos=>$rid){$r=mustEntity($rid,'role');if($r['groupId']!==$gid)fail(400,'Invalid role.');$r['position']=$pos;putEntity('role',$r,$gid,$id);}return ['roles'=>listEntities('role',$gid)];}
  if($m==='POST'&&!$rid){
   $data=['groupId'=>$gid,'createdBy'=>$id];
   if($kind==='invite'){$data+=['code'=>bin2hex(random_bytes(12)),'uses'=>0,'maxUses'=>isset($b['maxUses'])?(int)$b['maxUses']:null,'expiresAt'=>!empty($b['expiresInHours'])?now()+(int)$b['expiresInHours']*3600000:($b['expiresAt']??null),'creatorDisplayName'=>$u['displayName']];}
   elseif($kind==='role'){if(!empty($b['permissions']))fail(501,'Custom role permission enforcement is not ported; permission-bearing roles cannot be saved.','not_implemented');$data+=['name'=>required($b,'name',80),'color'=>null,'unicodeEmoji'=>null,'iconAttachmentId'=>null,'iconUrl'=>null,'secondaryColor'=>null,'tertiaryColor'=>null,'position'=>count(listEntities('role',$gid)),'permissions'=>[],'isDefault'=>false,'hoist'=>false,'mentionable'=>false,'inPrompt'=>false,'managed'=>false,'managedBy'=>null];$data=array_replace($data,array_intersect_key($b,$data));}
   elseif($kind==='expression'){$att=mustEntity(required($b,'attachmentId',64),'attachment');if($att['uploaderId']!==$id)fail(403,'Not your file.');$data+=array_intersect_key($att,array_flip(['mime','size','width','height','url']));$data+=['name'=>required($b,'name',48),'attachmentId'=>$att['id'],'type'=>enumValue($b['type']??'emoji',['emoji','sticker','gif','sound'])];}
   else $data+=['name'=>required($b,'name',80),'position'=>count(listEntities('category',$gid))];
   $r=putEntity($kind,$data,$gid,$id);emitGroup($gid,'group:updated',['group'=>groupView($g)]);return [$kind=>$r];
  }
  if($rid){$r=mustEntity($rid,$kind);if($r['groupId']!==$gid)fail(404,'Not found.');if($m==='DELETE'){removeEntity($rid);if($kind==='category'){foreach(listEntities('channel',$gid)as$c)if($c['categoryId']===$rid){$c['categoryId']=null;putEntity('channel',$c,$gid,$id);}}return ['ok'=>true];}
   if($m==='PATCH'){if($kind==='role'&&array_key_exists('permissions',$b))fail(501,'Custom role permission enforcement is not ported.','not_implemented');$keys=$kind==='role'?['name','color','unicodeEmoji','secondaryColor','tertiaryColor','position','permissions','hoist','mentionable','inPrompt']:['name','position'];$r=putEntity($kind,array_replace($r,array_intersect_key($b,array_flip($keys))),$gid,$id);return [$kind=>$r];}
  }
 }
 if($sub==='moderation'&&$m==='GET'){groupAccess($gid,true);return ['bans'=>listEntities('ban',$gid),'timeouts'=>array_values(array_filter(members($gid),fn($m)=>($m['timeoutUntil']??0)>now())),'rules'=>listEntities('automod',$gid)];}
 if(preg_match('#^bans/([^/]+)$#',$sub,$a)){groupAccess($gid,true);$mid=$a[1];if($mid===$g['ownerId'])fail(403,'Cannot ban owner.');if($m==='PUT'){putEntity('ban',['id'=>'ban-'.$gid.'-'.$mid,'userId'=>$mid,'user'=>publicUser(account($mid)),'reason'=>$b['reason']??null],$gid,$id);sql('DELETE FROM memberships WHERE scope_id=? AND user_id=?',[$gid,$mid]);emitUsers([$mid],'group:left',['groupId'=>$gid]);return ['ok'=>true];}if($m==='DELETE'){removeEntity('ban-'.$gid.'-'.$mid);return ['ok'=>true];}}
 return null;
}
function conversationRoutes(string $m,string $p,array $b): ?array {
 if(!str_starts_with($p,'/api/conversations'))return null;$u=user();$id=$u['id'];
 if($p==='/api/conversations'&&$m==='GET'){$cs=[];foreach(rows('SELECT scope_id FROM memberships WHERE user_id=?',[$id])as$r){$c=entity($r['scope_id'],'conversation');if($c)$cs[]=conversationView($c);}usort($cs,fn($a,$b)=>$b['updatedAt']<=>$a['updatedAt']);return ['conversations'=>$cs];}
 if(in_array($p,['/api/conversations/dm','/api/conversations/group'],true)&&$m==='POST'){
  if(!settings()['allow_dms'])fail(403,'Direct messages disabled.');$dm=str_ends_with($p,'/dm');if(!$dm&&!settings()['allow_group_dms'])fail(403,'Group direct messages disabled.');
  $ids=array_values(array_unique(array_merge([$id],$dm?[required($b,'userId',64)]:($b['userIds']??[]))));if(count($ids)<2||count($ids)>25)fail(400,'Invalid member count.');foreach($ids as$mid){if(!account($mid))fail(404,'User not found.');if(blocked($id,$mid))fail(403,'User is blocked.');}
  sort($ids);$cid=$dm?'dm-'.substr(hash('sha256',implode(':',$ids)),0,40):uid();
  $c=transaction(function()use($cid,$dm,$id,$ids,$b){sql('INSERT IGNORE INTO entities(id,kind,parent_id,owner_id,data,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$cid,'conversation','',$id,'{}',now(),now()]);$c=entity($cid,'conversation',true);if(!isset($c['type']))$c=putEntity('conversation',['id'=>$cid,'type'=>$dm?'dm':'group_dm','name'=>$b['name']??null,'iconUrl'=>null,'ownerId'=>$id,'createdBy'=>$id],'',$id);foreach($ids as$mid){if(!member($cid,$mid))addMember($cid,$mid);identityFor($mid);}return $c;});
  foreach($ids as$mid){$v=conversationView($c);$v['otherMembers']=array_values(array_filter($v['members'],fn($x)=>$x['id']!==$mid));emitUsers([$mid],'conversation:created',['conversation'=>$v]);}return ['conversation'=>conversationView($c)];
 }
 if(preg_match('#^/api/conversations/([^/]+)(?:/(preferences|members)(?:/([^/]+))?)?$#',$p,$a)){
  $cid=$a[1];$c=canTarget('conversations',$cid);$sub=$a[2]??'';
  if($sub==='preferences'){$pref=entity('pref-'.$cid.'-'.$id)??['muted'=>false,'hidden'=>false,'pinned'=>false,'favorite'=>false,'archived'=>false];if($m==='PUT')$pref=putEntity('preference',array_replace($pref,array_intersect_key($b,array_flip(['muted','hidden','pinned','favorite','archived'])),['id'=>'pref-'.$cid.'-'.$id]),$cid,$id);return ['preference'=>$pref];}
  if($sub==='members'){
   $mid=$a[3]??$b['userId']??'';if($c['type']!=='group_dm')fail(400,'Not a group conversation.');if($c['ownerId']!==$id&& !($m==='DELETE'&&$mid===$id))fail(403,'Only conversation owner can manage members.');
   if($m==='POST'){if(count(memberIds($cid))>=25)fail(400,'Conversation full.');addMember($cid,$mid);identityFor($mid);emitUsers(memberIds($cid),'conversation:member-added',['conversationId'=>$cid,'member'=>publicUser(account($mid))]);return ['ok'=>true];}
   if($m==='DELETE'){sql('DELETE FROM memberships WHERE scope_id=? AND user_id=?',[$cid,$mid]);emitUsers([$mid],'conversation:removed',['conversationId'=>$cid]);return ['ok'=>true];}
  }
  if($sub===''){
   if($m==='GET')return ['conversation'=>conversationView($c)];
   if($m==='PATCH'){if($c['type']!=='group_dm'||$c['ownerId']!==$id)fail(403,'Cannot rename this conversation.');$c['name']=$b['name']??null;putEntity('conversation',$c,'',$c['ownerId']);emitUsers(memberIds($cid),'conversation:updated',['conversation'=>conversationView($c)]);return ['conversation'=>conversationView($c)];}
   if($m==='DELETE'){sql('DELETE FROM memberships WHERE scope_id=? AND user_id=?',[$cid,$id]);emitUsers([$id],'conversation:removed',['conversationId'=>$cid]);return ['ok'=>true];}
  }
 }
 return null;
}
function blocked(string $a,string $b): bool {return (bool)(entity('block-'.$a.'-'.$b)||entity('block-'.$b.'-'.$a));}
