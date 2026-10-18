<?php
declare(strict_types=1);
function forumRoutes(string $m,string $p,array $b): ?array {
 if(!preg_match('#^/api/forums/([^/]+)/posts(?:/([^/]+))?(?:/(members)(?:/([^/]+))?)?$#',$p,$a))return null;
 $cid=$a[1];$c=canTarget('channels',$cid);if($c['type']!=='forum')fail(400,'Not a forum channel.');$id=user()['id'];$postId=$a[2]??null;$manager=permissions($c['groupId'])['deleteAnyMessage'];
 if(!$postId){
  if($m==='GET')return ['posts'=>array_values(array_filter(listEntities('post',$cid),fn($post)=>!$post['private']||$manager||$post['authorId']===$id||member($post['id'],$id)))];
  if($m==='POST'){if($b['private']??false)fail(501,'Private forum threads have not been ported.','not_implemented');$title=required($b,'title',200);$root=sendMessage('channels',$cid,['content'=>required($b,'content',10000)]);$post=putEntity('post',['channelId'=>$cid,'rootMessageId'=>$root['id'],'authorId'=>$id,'title'=>$title,'tags'=>array_slice($b['tags']??[],0,10),'locked'=>false,'archived'=>false,'private'=>(bool)($b['private']??false),'memberCount'=>1,'canManageMembers'=>true,'replyCount'=>0,'author'=>publicUser(user())],$cid,$id);addMember($post['id'],$id);if($post['private']){$msg=entity($root['id'],'message');$msg['forumPostId']=$post['id'];putEntity('message',$msg,$cid,$id);}return ['post'=>$post];}
 }
 $post=mustEntity($postId,'post');if($post['channelId']!==$cid||($post['private']&&!$manager&&$post['authorId']!==$id&&!member($postId,$id)))fail(404,'Post not found.');
 if(!empty($a[3])){
  if(!$manager&&$post['authorId']!==$id)fail(403,'Only thread owner can manage members.');if($m==='GET')return ['members'=>members($postId)];if($m==='POST'){addMember($postId,required($b,'userId',64));$post['memberCount']=count(memberIds($postId));putEntity('post',$post,$cid,$post['authorId']);return ['ok'=>true];}if($m==='DELETE'){sql('DELETE FROM memberships WHERE scope_id=? AND user_id=?',[$postId,$a[4]??'']);return ['ok'=>true];}
 }
 if($m==='GET'){$post['canManageMembers']=$manager||$post['authorId']===$id;return ['post'=>$post];}
 if($m==='PATCH'){if(!$manager&&$post['authorId']!==$id)fail(403,'Cannot edit post.');$post=array_replace($post,array_intersect_key($b,array_flip(['title','locked','archived','tags'])));putEntity('post',$post,$cid,$post['authorId']);return ['post'=>$post];}
 return null;
}
function extraRoutes(string $m,string $p,array $b): ?array {
 if(preg_match('#^/api/voice/([^/]+)(?:/(status|consent))?$#',$p,$a)){
  $cid=$a[1];$c=canTarget('channels',$cid);$uid=user()['id'];$sub=$a[2]??'';
  if($sub==='status'&&$m==='PATCH'){if(!permissions($c['groupId'])['manageChannels'])fail(403,'Cannot change voice status.');$c['voiceStatus']=$b['status']??null;putEntity('channel',$c,$c['groupId'],mustEntity($c['groupId'],'group')['ownerId']);return ['status'=>$c['voiceStatus']];}
  if($sub==='consent'&&$m==='PUT')return ['consent'=>putEntity('consent',['id'=>'consent-'.$cid.'-'.$uid,'recording'=>(bool)($b['recording']??false),'transcript'=>(bool)($b['transcript']??false)],$cid,$uid)];
  if($sub===''&&$m==='GET')return ['channelStatus'=>$c['voiceStatus'],'permissions'=>['useSoundboard'=>false,'useEmbeddedActivities'=>false,'setVoiceChannelStatus'=>permissions($c['groupId'])['manageChannels'],'useVoiceActivity'=>true,'prioritySpeaker'=>false,'useExternalSounds'=>false],'room'=>['hostId'=>mustEntity($c['groupId'],'group')['ownerId'],'lobbyEnabled'=>false,'recordingConsentRequired'=>false,'status'=>'open'],'lobby'=>[],'recordings'=>[],'consent'=>entity('consent-'.$cid.'-'.$uid)??['recording'=>false,'transcript'=>false,'updatedAt'=>null],'stage'=>['enabled'=>false,'members'=>[]],'activity'=>null];
 }
 if(preg_match('#^/api/moderation/(reports|user-reports)(?:/([^/]+))?$#',$p,$a)){
  admin();if($m==='GET')return ['reports'=>array_reverse(listEntities('report'))];if($m==='PATCH'&&!empty($a[2])){$r=mustEntity($a[2],'report');$r['status']=enumValue($b['status']??'resolved',['open','resolved','dismissed','reviewing']);$r['resolution']=$b['resolution']??null;putEntity('report',$r);audit('moderation.report_updated',$r['id']);return ['report'=>$r];}
 }
 if(preg_match('#^/api/moderation/users/([^/]+)/actions$#',$p,$a)&&$m==='POST'){
  admin();$u=account($a[1])??fail(404,'User not found.');if($u['id']===user()['id'])fail(400,'Cannot moderate yourself.');$action=enumValue($b['type']??$b['action']??null,['ban','unban','suspend','unsuspend','warn','disable','enable']);$reason=required($b,'reason',2000);if($action==='ban')$u['bannedAt']=now();if($action==='unban')$u['bannedAt']=null;if($action==='suspend')$u['suspendedUntil']=now()+max(60,min(31536000,(int)($b['durationSeconds']??3600)))*1000;if($action==='unsuspend')$u['suspendedUntil']=null;if($action==='disable')$u['isActive']=false;if($action==='enable')$u['isActive']=true;$u['moderationReason']=$reason;saveUser($u);if(in_array($action,['ban','disable','suspend'],true))sql('DELETE FROM sessions WHERE user_id=?',[$u['id']]);$v=putEntity('moderation_action',['userId'=>$u['id'],'action'=>$action,'reason'=>$reason,'actorId'=>user()['id']],$u['id'],user()['id']);audit('moderation.'.$action,$u['id']);notify($u['id'],'moderation','Account action',$reason,['actionId'=>$v['id']]);return ['action'=>$v,'user'=>adminUser($u)];
 }
 if(preg_match('#^/api/groups/([^/]+)/external-expressions$#',$p,$a)&&$m==='GET'){
  groupAccess($a[1]);$es=[];foreach(listEntities('expression')as$e)if($e['groupId']!==$a[1]&&member($e['groupId'],user()['id']))$es[]=$e;return ['expressions'=>$es];
 }
 if(preg_match('#^/api/integrations/groups/([^/]+)/commands$#',$p,$a)&&$m==='GET'){groupAccess($a[1]);return ['commands'=>[]];}
 return null;
}
