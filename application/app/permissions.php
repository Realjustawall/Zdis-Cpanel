<?php
declare(strict_types=1);
function permissionDefaults(): array {static $p;return $p??=decode(file_get_contents(__DIR__.'/permission-defaults.json'));}
function permissionKeys(): array {return array_merge(permissionDefaults()['DISCORD_PERMISSION_KEYS'],permissionDefaults()['PLATFORM_PERMISSION_KEYS']);}
function normalizePermissions($p): array {if(!is_array($p)||!array_is_list($p))fail(400,'Permission list required.');foreach($p as$x)if(!is_string($x)||!in_array($x,permissionKeys(),true))fail(400,'Unknown permission.');return array_values(array_unique($p));}
function capability(string $cap,?string $uid=null): bool {
 $uid??=user()['id'];$u=account($uid);if(!$u||!$u['isActive'])return false;
 if($u['role']==='admin'||in_array('admin',array_column($u['badges'],'id'),true))return true;
 $badges=array_column($u['badges'],'id');if($cap==='moderate'&&(in_array('staff',$badges,true)||in_array('community_moderator',$badges,true)))return true;if($cap==='debug'&&in_array('developer',$badges,true))return true;if($cap==='stream'&&($u['role']==='youtuber'||in_array('streamer',$badges,true)))return true;
 foreach(listEntities('platform_role_grant',$uid)as$grant){$role=entity($grant['roleId'],'platform_role');if($role&&(in_array('admin',$role['capabilities'],true)||in_array($cap,$role['capabilities'],true)))return true;}return false;
}
function requireCapability(string $cap): array {$u=user();if(!capability($cap,$u['id']))fail(403,'Capability required: '.$cap);return $u;}
function ensureEveryone(string $gid): array {$id='everyone-'.$gid;if($r=entity($id,'role'))return $r;return putEntity('role',['id'=>$id,'groupId'=>$gid,'name'=>'@everyone','color'=>null,'unicodeEmoji'=>null,'iconAttachmentId'=>null,'iconUrl'=>null,'secondaryColor'=>null,'tertiaryColor'=>null,'position'=>0,'permissions'=>permissionDefaults()['DEFAULT_EVERYONE_PERMISSIONS'],'isDefault'=>true,'hoist'=>false,'mentionable'=>false,'inPrompt'=>false,'managed'=>false,'managedBy'=>null,'createdBy'=>mustEntity($gid,'group')['ownerId']],$gid,mustEntity($gid,'group')['ownerId']);}
function memberServerRoles(string $gid,string $uid): array {
 $member=member($gid,$uid);if(!$member)return [];$default=ensureEveryone($gid);$ids=array_column($member['serverRoles']??[],'id');$roles=[$default];foreach($ids as$id)if($r=entity($id,'role'))if($r['groupId']===$gid&&!$r['isDefault'])$roles[]=$r;usort($roles,fn($a,$b)=>$b['position']<=>$a['position']);return $roles;
}
function groupContext(string $gid,?string $uid=null): array {
 $uid??=user()['id'];$u=account($uid)??fail(401,'Unknown user.');$g=mustEntity($gid,'group');$m=member($gid,$uid);$pa=capability('admin',$uid);$pm=capability('moderate',$uid);if(!$m&&!$pa&&!$pm)fail(404,'Group not found.');
 $baseRole=$m['memberRole']??($pa?'owner':'moderator');$roles=memberServerRoles($gid,$uid);$perms=array_values(array_unique(array_merge(...array_map(fn($r)=>$r['permissions'],$roles?:[['permissions'=>[]]]))));$serverAdmin=in_array('administrator',$perms,true);$twoFactor=($g['require2faModeration']??false)&&!$u['totpEnabled']&&!$pa;
 $bypass=($pa||$baseRole==='owner'||$serverAdmin)&&!$twoFactor;$timeout=!$pa&&$baseRole!=='owner'&&!$serverAdmin&&($m['timeoutUntil']??0)>now();$external=$pm&&!$m&&!$pa;
 $effective=[];$protected=['administrator','kickMember','banMembers','manageGroup','manageChannels','manageRoles','manageWebhooks','manageExpressions','deleteAnyMessage','manageEvents','manageThreads','moderateMembers','manageMembers','viewCreatorMonetizationAnalytics'];
 foreach(permissionKeys()as$key){if($twoFactor&&in_array($key,$protected,true))continue;if($timeout){if(in_array($key,['viewChannel','readMessageHistory'],true))$effective[]=$key;continue;}
  if($pa||$baseRole==='owner'||$serverAdmin||($external?in_array($key,['deleteAnyMessage','pinMessage','viewAuditLog','moderateMembers'],true):(in_array($baseRole,permissionDefaults()['GROUP_PERMISSIONS'][$key]??[],true)||in_array($key,$perms,true))))$effective[]=$key;
 }
 $position=max(['owner'=>PHP_INT_MAX,'admin'=>2000000,'moderator'=>1000000,'member'=>0][$baseRole]??0,...array_column($roles?:[['position'=>0]],'position'));
 return ['group'=>$g,'userId'=>$uid,'member'=>$m,'role'=>$baseRole,'roles'=>$roles,'effective'=>$effective,'bypass'=>$bypass,'platformAdmin'=>$pa,'timedOut'=>$timeout,'position'=>$position];
}
function groupCan(string $gid,string $permission,?string $uid=null): bool {return in_array($permission,groupContext($gid,$uid)['effective'],true);}
function gp(string $gid,string $permission,?string $uid=null): array {$c=groupContext($gid,$uid);if(!in_array($permission,$c['effective'],true))fail(403,'Permission required: '.$permission);return $c;}
function outranks(string $gid,string $target): bool {$c=groupContext($gid);if($target===$c['userId'])return false;if($c['platformAdmin']||$c['role']==='owner')return true;return $c['position']>groupContext($gid,$target)['position'];}
function requireOutrank(string $gid,string $target): void {if(!outranks($gid,$target))fail(403,'You cannot manage an equal or higher member.');}
function channelOverrides(array $c): array {return listEntities('override',($c['categoryId']&&$c['permissionsSynced'])?$c['categoryId']:$c['id']);}
function channelCan(array $channel,string $permission,?string $uid=null,array $visiting=[]): bool {
 $uid??=user()['id'];$ctx=groupContext($channel['groupId'],$uid);$accountFeature=['attachFiles'=>'sendFiles','sendImages'=>'sendImages','sendVideos'=>'sendVideos','sendAudio'=>'sendAudio','connectVoice'=>'connectVoice','speak'=>'speak','video'=>'video','screenShare'=>'screenShare'][$permission]??null;
 if($accountFeature&&!accountAccess($uid)[$accountFeature])return false;
 $muteKind=in_array($permission,['sendMessages','sendMessagesInThreads','sendVoiceMessages','createPublicThreads','createPrivateThreads'],true)?'text':($permission==='speak'?'voice':null);if($muteKind){$mute=entity('mute-'.$channel['groupId'].'-'.$uid.'-'.$muteKind);if($mute&&$mute['expiresAt']>now())return false;}
 if($ctx['bypass'])return true;if($ctx['timedOut'])return in_array($permission,['viewChannel','readMessageHistory'],true);
 if(in_array($permission,$visiting,true))return false;$visiting[]=$permission;
 if($permission!=='viewChannel'&&!channelCan($channel,'viewChannel',$uid,$visiting))return false;
 if(in_array($permission,['mentionEveryone','sendTtsMessages','attachFiles','embedLinks','sendVoiceMessages','sendPolls','useApplicationCommands','useExternalApps','useExternalEmojis','useExternalStickers'],true)&&!channelCan($channel,$channel['type']==='forum'?'sendMessagesInThreads':'sendMessages',$uid,$visiting))return false;
 if(in_array($permission,['speak','video','screenShare','useVoiceActivity','prioritySpeaker','muteMembers','deafenMembers','moveMembers','requestToSpeak','useEmbeddedActivities','useSoundboard','useExternalSounds','setVoiceChannelStatus'],true)&&!channelCan($channel,'connectVoice',$uid,$visiting))return false;
 $allow=in_array($permission,$ctx['effective'],true);$overrides=channelOverrides($channel);$apply=function($o)use($permission,&$allow){if(in_array($permission,$o['deny'],true))$allow=false;if(in_array($permission,$o['allow'],true))$allow=true;};
 foreach($overrides as$o)if($o['targetType']==='everyone')$apply($o);
 $roleIds=array_column(array_filter($ctx['roles'],fn($r)=>!$r['isDefault']),'id');$ros=array_filter($overrides,fn($o)=>$o['targetType']==='role'&&in_array($o['targetId'],$roleIds,true));foreach($ros as$o)if(in_array($permission,$o['deny'],true))$allow=false;foreach($ros as$o)if(in_array($permission,$o['allow'],true))$allow=true;
 foreach($overrides as$o)if($o['targetType']==='member'&&$o['targetId']===$uid)$apply($o);return $allow;
}
function cp(array $channel,string $permission): void {if(!channelCan($channel,$permission))fail(403,'Channel permission required: '.$permission);}
function channelVisible(array $c,string $uid): bool {
 $ctx=groupContext($c['groupId'],$uid);if(!channelCan($c,'viewChannel',$uid))return false;if(!$c['isPrivate']||$ctx['platformAdmin']||groupCan($c['groupId'],'manageChannels',$uid)||member($c['id'],$uid))return true;
 $roleIds=array_column($ctx['roles'],'id');foreach(channelOverrides($c)as$o)if(in_array('viewChannel',$o['allow'],true)&&($o['targetType']==='everyone'||($o['targetType']==='role'&&in_array($o['targetId'],$roleIds,true))||($o['targetType']==='member'&&$o['targetId']===$uid)))return true;return false;
}
function groupActionPermission(string $method,string $path): string {
 if(str_contains($path,'/overrides')||str_contains($path,'/permissions/sync')||str_contains($path,'/channels')||str_contains($path,'/categories'))return 'manageChannels';
 if(str_contains($path,'/roles')||str_contains($path,'/onboarding'))return 'manageRoles';if(str_contains($path,'/timeout'))return 'moderateMembers';if(str_contains($path,'/bans'))return 'banMembers';if(str_contains($path,'/invites'))return 'createInvite';if(str_contains($path,'/expressions'))return 'manageExpressions';if(str_contains($path,'/members'))return $method==='DELETE'?'kickMember':'manageMembers';if(str_contains($path,'/automod-rules'))return 'manageGroup';if(preg_match('#^/api/groups/[^/]+$#',$path)&&$method==='DELETE')return 'deleteGroup';return 'manageGroup';
}
function roleValue(string $gid,array $b,?array $r=null): array {
 $ctx=gp($gid,'manageRoles');$default=$r['isDefault']??false;$managed=$r['managed']??false;if($managed)fail(403,'Integration-managed roles cannot be edited.');if($r&&!$default&&$r['position']>=$ctx['position'])fail(403,'Role is above your highest role.');
 $name=$default?'@everyone':required($b+($r??[]),'name',80);foreach(listEntities('role',$gid)as$existing)if(mb_strtolower($existing['name'])===mb_strtolower($name)&&$existing['id']!==($r['id']??null))fail(409,'Duplicate role name.');
 $perms=array_key_exists('permissions',$b)?normalizePermissions($b['permissions']):($r['permissions']??[]);foreach($perms as$p)if(!in_array($p,$ctx['effective'],true))fail(403,'Cannot grant a permission you do not possess.');
 $value=$r??['id'=>uid(),'groupId'=>$gid,'color'=>null,'unicodeEmoji'=>null,'iconAttachmentId'=>null,'iconUrl'=>null,'secondaryColor'=>null,'tertiaryColor'=>null,'position'=>min($ctx['position']-1,max([0,...array_column(listEntities('role',$gid),'position')])+1),'isDefault'=>false,'hoist'=>false,'mentionable'=>false,'inPrompt'=>false,'managed'=>false,'managedBy'=>null,'createdBy'=>user()['id']];
 foreach(['color','unicodeEmoji','secondaryColor','tertiaryColor','hoist','mentionable','inPrompt','iconAttachmentId']as$k)if(array_key_exists($k,$b)&&!$default)$value[$k]=$b[$k];foreach(['color','secondaryColor','tertiaryColor']as$k)if($value[$k]!==null&&(!is_string($value[$k])||!preg_match('/^#[a-f0-9]{6}$/iD',$value[$k])))fail(400,'Invalid role color.');foreach(['hoist','mentionable','inPrompt']as$k)if(!is_bool($value[$k]))fail(400,'Invalid role flag.');
 if($value['iconAttachmentId']){$a=mustEntity($value['iconAttachmentId'],'attachment');if($a['uploaderId']!==user()['id']||!str_starts_with($a['mime'],'image/'))fail(403,'Invalid role icon.');$value['iconUrl']=$a['url'];}if(!$value['iconAttachmentId'])$value['iconUrl']=null;if($default)$value['position']=0;$value['name']=$name;$value['permissions']=$perms;return putEntity('role',$value,$gid,$value['createdBy']);
}
function permissionRoutes(string $method,string $path,array $b): ?array {
 if(!preg_match('#^/api/groups/([^/]+)/(.*)$#',$path,$a))return null;$gid=$a[1];$sub=$a[2];if(!preg_match('#^(roles(?:/|$)|members/[^/]+/roles/|onboarding/roles/|(?:channels|categories)/[^/]+/(?:overrides|permissions/sync))#',$sub))return null;groupAccess($gid);
 if(preg_match('#^(channels|categories)/([^/]+)/(overrides|permissions/sync)(?:/(everyone|role|member)/([^/]+))?$#',$sub,$p)){
  $type=$p[1]==='channels'?'channel':'category';$resource=mustEntity($p[2],$type);if($resource['groupId']!==$gid)fail(404,'Resource not found.');gp($gid,'manageChannels');$rid=$resource['id'];
  if($method==='GET')return ['overrides'=>listEntities('override',$rid)];
  if($p[3]==='permissions/sync'&&$method==='POST'){if(!$resource['categoryId'])fail(400,'Channel has no category.');$resource['permissionsSynced']=true;putEntity('channel',$resource,$gid,user()['id']);return ['channel'=>$resource];}
  $tt=$p[4]??enumValue($b['targetType']??null,['everyone','role','member']);$tid=$p[5]??required($b,'targetId',64);if($tt==='everyone'&&$tid!==$gid)fail(400,'Invalid everyone target.');if($tt==='role'&&mustEntity($tid,'role')['groupId']!==$gid)fail(400,'Invalid role target.');if($tt==='member'&&!member($gid,$tid))fail(400,'Invalid member target.');$oid='ov-'.hash('sha256',$rid.':'.$tt.':'.$tid);
  if($method==='PUT'){ $allow=normalizePermissions($b['allow']??[]);$deny=array_values(array_diff(normalizePermissions($b['deny']??[]),$allow));foreach($allow as$perm)if(!groupCan($gid,$perm))fail(403,'Cannot grant a permission you do not possess.');putEntity('override',['id'=>$oid,'groupId'=>$gid,'channelId'=>$rid,'targetType'=>$tt,'targetId'=>$tid,'allow'=>$allow,'deny'=>$deny,'updatedBy'=>user()['id']],$rid,user()['id']);}
  elseif($method==='DELETE')removeEntity($oid);else return null;
  if($type==='channel'){$resource['permissionsSynced']=false;putEntity('channel',$resource,$gid,user()['id']);}emitGroup($gid,'group:updated',['group'=>groupView(mustEntity($gid,'group'))]);return ['overrides'=>listEntities('override',$rid)];
 }
 if($sub==='roles'){
  ensureEveryone($gid);if($method==='GET')return ['roles'=>listEntities('role',$gid)];if($method==='POST')return ['role'=>roleValue($gid,$b)];
 }
 if($sub==='roles/reorder'&&$method==='PUT'){
  $ctx=gp($gid,'manageRoles');$ids=$b['roleIds']??[];if(!is_array($ids)||count(array_unique($ids))!==count($ids))fail(400,'Invalid role order.');transaction(function()use($ids,$gid,$ctx){foreach($ids as$i=>$rid){$r=mustEntity($rid,'role');if($r['groupId']!==$gid)fail(404,'Role not found.');if($r['isDefault'])continue;if($r['position']>=$ctx['position']||$i+1>=$ctx['position'])fail(403,'Role exceeds hierarchy.');$r['position']=$i+1;putEntity('role',$r,$gid,$r['createdBy']);}});return ['roles'=>listEntities('role',$gid)];
 }
 if(preg_match('#^roles/([^/]+)$#',$sub,$p)){
  $r=mustEntity($p[1],'role');if($r['groupId']!==$gid)fail(404,'Role not found.');if($method==='PATCH')return ['role'=>roleValue($gid,$b,$r)];
  if($method==='DELETE'){$ctx=gp($gid,'manageRoles');if($r['isDefault']||$r['managed']||$r['position']>=$ctx['position'])fail(403,'Cannot delete this role.');removeEntity($r['id']);foreach(members($gid)as$m){$seat=member($gid,$m['id']);$seat['serverRoles']=array_values(array_filter($seat['serverRoles'],fn($v)=>$v['id']!==$r['id']));addMember($gid,$m['id'],$seat);}foreach(listEntities('override')as$o)if($o['targetType']==='role'&&$o['targetId']===$r['id'])removeEntity($o['id']);return ['ok'=>true];}
 }
 if(preg_match('#^members/([^/]+)/roles/([^/]+)$#',$sub,$p)&&$method==='PUT'){
  $ctx=gp($gid,'manageRoles');$uid=$p[1];$r=mustEntity($p[2],'role');if($r['groupId']!==$gid||$r['isDefault']||$r['managed']||$r['position']>=$ctx['position'])fail(403,'Cannot assign this role.');if(array_diff($r['permissions'],$ctx['effective']))fail(403,'Cannot grant permissions you do not possess.');if($uid!==user()['id'])requireOutrank($gid,$uid);elseif(!$ctx['platformAdmin']&&$ctx['role']!=='owner')fail(403,'Cannot change your own roles.');$seat=member($gid,$uid)??fail(404,'Member not found.');$seat['serverRoles']=array_values(array_filter($seat['serverRoles'],fn($v)=>$v['id']!==$r['id']));if($b['granted']??$b['assigned']??true)$seat['serverRoles'][]=$r;addMember($gid,$uid,$seat);emitGroup($gid,'group:member-updated',['groupId'=>$gid,'userId'=>$uid]);return ['ok'=>true];
 }
 if(preg_match('#^onboarding/roles/([^/]+)$#',$sub,$p)&&$method==='PUT'){
  $r=mustEntity($p[1],'role');if($r['groupId']!==$gid||!$r['inPrompt'])fail(403,'This role is not available for self assignment.');if(array_intersect($r['permissions'],['administrator','manageRoles','manageGroup','manageChannels','banMembers','kickMember','moderateMembers']))fail(403,'Privileged roles cannot be self assigned.');$seat=member($gid,user()['id']);$seat['serverRoles']=array_values(array_filter($seat['serverRoles'],fn($x)=>$x['id']!==$r['id']));if($b['selected']??$b['granted']??true)$seat['serverRoles'][]=$r;addMember($gid,user()['id'],$seat);return ['ok'=>true];
 }
 return null;
}
