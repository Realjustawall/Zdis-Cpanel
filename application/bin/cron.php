<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$lock=fopen(storageDir().'/cron.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
foreach(listEntities('scheduled')as$scheduled){if($scheduled['status']!=='pending'||$scheduled['sendAt']>now())continue;
 try{transaction(function()use($scheduled){$s=entity($scheduled['id'],'scheduled',true);if($s['status']!=='pending')return;$GLOBALS['service_user']=$s['userId'];$u=account($s['userId']);if(!$u||!$u['isActive']||($u['bannedAt']??null)||($u['suspendedUntil']??0)>now())fail(403,'Account is inactive.');$message=sendMessage($s['targetType'],$s['targetId'],['content'=>$s['content'],'encrypted'=>$s['encrypted'],'clientId'=>'scheduled-'.$s['id']]);$s['status']='sent';$s['messageId']=$message['id'];putEntity('scheduled',$s,$s['targetId'],$s['userId']);});}
 catch(Throwable $e){$scheduled['status']='failed';$scheduled['error']=$e instanceof HttpError?$e->getMessage():'Publication failed. Check PHP logs.';putEntity('scheduled',$scheduled,$scheduled['targetId'],$scheduled['userId']);error_log('ZDIS cron: '.$e->getMessage());}
}
unset($GLOBALS['service_user']);
$GLOBALS['service_user']=row("SELECT id FROM accounts LIMIT 1")['id'];botMaintenance();unset($GLOBALS['service_user']);
runJobs();
sql('DELETE FROM sessions WHERE expires_at<?',[now()]);sql('DELETE FROM rate_limits WHERE expires_at<?',[now()]);sql('DELETE FROM events WHERE created_at<?',[now()-86400000]);
foreach(listEntities('message')as$m)if(!$m['deleted']&&$m['expiresAt']&&$m['expiresAt']<now()){$m['content']='';$m['deleted']=true;putEntity('message',$m,$m['channelId']??$m['conversationId'],$m['authorId']);}
echo "ZDIS cron completed.\n";
