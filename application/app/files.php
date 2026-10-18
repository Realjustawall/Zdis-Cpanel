<?php
declare(strict_types=1);
function storageDir(): string {$dir=cfg()['storage'];if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Storage directory is not writable.');return $dir;}
function maxUpload(): int {return (int)min(256*1048576,(settings()['upload_limit_enabled']?settings()['max_upload_mb']:256)*1048576);}
function storeFile(string $path,string $name,string $claimed,string $kind='file'): array {
 $u=user();$s=settings();if(!$s['uploads_enabled'])fail(403,'Uploads disabled.');$size=filesize($path);if(!$size||$size>maxUpload())fail(413,'File exceeds upload limit.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';
 if($claimed==='application/vnd.zdis.encrypted'){if($size<=8||!in_array(file_get_contents($path,false,null,0,8),['ZDISENC1','ZDISENC2'],true))fail(400,'Invalid encrypted file header.');$mime=$claimed;}
 $allowed=['image/png','image/jpeg','image/gif','image/webp','image/bmp','video/mp4','video/webm','video/quicktime','audio/webm','audio/ogg','audio/mpeg','audio/mp4','audio/wav','application/pdf','text/plain','text/csv','application/json','application/zip','application/vnd.zdis.encrypted'];if(!in_array($mime,$allowed,true))fail(400,'File type not allowed.');
 if($kind==='voice'&&!str_starts_with($mime,'audio/'))fail(400,'Voice upload requires audio.');
 $access=accountAccess($u['id']);if(!$access['sendFiles'])fail(403,'File access is disabled for your account.');
 $category=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':(str_starts_with($mime,'audio/')?'audio':'document'));if(!$s['allow_'.$category.'_uploads'])fail(403,'File category disabled.');
 $feature=['image'=>'sendImages','video'=>'sendVideos','audio'=>'sendAudio'];if(isset($feature[$category])&&!$access[$feature[$category]])fail(403,'Media access is disabled for your account.');
 $id=uid();$dest=storageDir().'/'.$id.'.bin';if(!copy($path,$dest))throw new RuntimeException('Cannot save file.');chmod($dest,0600);$dim=$category==='image'?@getimagesize($dest):null;
 return putEntity('attachment',['id'=>$id,'filename'=>mb_substr(str_replace(["\r","\n","\0"], '',basename($name)),0,120),'mime'=>$mime,'size'=>$size,'width'=>$dim?$dim[0]:null,'height'=>$dim?$dim[1]:null,'kind'=>$kind,'url'=>'/api/files/'.$id,'previewUrl'=>null,'optimizedUrl'=>null,'processingStatus'=>'complete','scanStatus'=>'not_configured','uploaderId'=>$u['id'],'messageId'=>null,'path'=>$id.'.bin'],'',$u['id']);
}
function attachmentView(array $a): array {unset($a['path'],$a['uploaderId'],$a['messageId']);return $a;}
function fileReadable(array $a): bool {
 $id=user()['id'];if($a['uploaderId']===$id)return true;
 if($a['messageId']){$m=entity($a['messageId'],'message');if(!$m||!messageAccessible($m)||$m['deleted']||($m['expiresAt']&&$m['expiresAt']<now()))return false;try{canTarget($m['channelId']?'channels':'conversations',$m['channelId']??$m['conversationId']);return true;}catch(HttpError){return false;}}
 // Avatars and group art have intentionally public visibility, but still require a login.
 if(row('SELECT id FROM accounts WHERE data LIKE ? LIMIT 1',['%/api/files/'.$a['id'].'%']))return true;
 foreach(listEntities('group')as$g)if(($g['iconUrl']??'')===$a['url'])return true;
 foreach(listEntities('role')as$r)if(($r['iconAttachmentId']??null)===$a['id']&&member($r['groupId'],$id))return true;
 foreach(listEntities('expression')as$e)if($e['attachmentId']===$a['id']&&member($e['groupId'],$id))return true;return false;
}
function outputFile(array $a): never {
 if(!fileReadable($a))fail(404,'File not found.');$path=storageDir().'/'.$a['path'];if(!is_file($path))fail(404,'File not found.');$size=filesize($path);$start=0;$end=$size-1;
 if(isset($_SERVER['HTTP_RANGE'])){
  if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$r)||($r[1]===''&&$r[2]==='')){header("Content-Range: bytes */$size");fail(416,'Invalid range.');}
  if($r[1]==='')$start=max(0,$size-(int)$r[2]);else{$start=(int)$r[1];if($r[2]!=='')$end=min($end,(int)$r[2]);}
  if($start>$end||$start>=$size){header("Content-Range: bytes */$size");fail(416,'Invalid range.');}http_response_code(206);header("Content-Range: bytes $start-$end/$size");
 }
 $safe=preg_match('#^(image/(png|jpeg|gif|webp|bmp)|video/(mp4|webm|quicktime)|audio/(webm|ogg|mpeg|mp4|wav))$#',$a['mime']);header('Content-Type: '.$a['mime']);header('Content-Disposition: '.($safe?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($a['filename']));header('Content-Length: '.($end-$start+1));header('Accept-Ranges: bytes');header("Content-Security-Policy: default-src 'none'; sandbox");if($_SERVER['REQUEST_METHOD']==='HEAD')exit;$f=fopen($path,'rb');fseek($f,$start);$left=$end-$start+1;while($left>0&&!feof($f)){$chunk=fread($f,min(65536,$left));echo $chunk;$left-=strlen($chunk);}fclose($f);exit;
}
function fileRoutes(string $m,string $p,array $b): ?array {
 if($p==='/api/auth/avatar'&&$m==='POST'){
  $f=$_FILES['avatar']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)fail(400,'Avatar upload failed.');$att=storeFile($f['tmp_name'],$f['name'],$f['type']);if(!str_starts_with($att['mime'],'image/')){unlink(storageDir().'/'.$att['path']);removeEntity($att['id']);fail(400,'Avatar must be an image.');}$u=user();$u['avatarUrl']=$att['url'];saveUser($u);return ['user'=>$u,'avatarUrl'=>$att['url']];
 }
 if(!str_starts_with($p,'/api/files'))return null;$u=user();$id=$u['id'];
 if($p==='/api/files/limits'&&$m==='GET')return ['enabled'=>settings()['uploads_enabled'],'limitEnabled'=>true,'maxBytes'=>maxUpload(),'maxMb'=>maxUpload()/1048576,'accept'=>[],'resumable'=>true,'chunkBytes'=>1048576,'quota'=>['used'=>array_sum(array_column(listEntities('attachment',null,$id),'size')),'capacity'=>null]];
 if($p==='/api/files/quota'&&$m==='GET')return ['quota'=>['used'=>array_sum(array_column(listEntities('attachment',null,$id),'size')),'capacity'=>null]];
 if(in_array($p,['/api/files','/api/files/voice'],true)&&$m==='POST'){
  throttle('upload:'.$id,30,60);$f=$_FILES['file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)fail(400,'File upload failed; check PHP upload_max_filesize and post_max_size.');$att=storeFile($f['tmp_name'],$f['name'],$f['type'],str_ends_with($p,'/voice')?'voice':'file');http_response_code(201);return ['attachment'=>attachmentView($att)];
 }
 if($p==='/api/files/resumable'&&$m==='POST'){
  if(!settings()['uploads_enabled'])fail(403,'Uploads disabled.');throttle('upload:'.$id,30,60);$size=(int)($b['size']??0);if($size<1||$size>maxUpload())fail(413,'Invalid file size.');$upload=putEntity('upload',['userId'=>$id,'filename'=>required($b,'filename',120),'mime'=>required($b,'mime',120),'size'=>$size,'chunkSize'=>1048576,'totalChunks'=>(int)ceil($size/1048576),'receivedParts'=>[],'expiresAt'=>now()+86400000],'',$id);return ['upload'=>$upload];
 }
 if(preg_match('#^/api/files/resumable/([^/]+)(?:/(complete|parts/([0-9]+)))?$#',$p,$a)){
  $upload=mustEntity($a[1],'upload');if($upload['userId']!==$id||$upload['expiresAt']<now())fail(404,'Upload not found.');$action=$a[2]??'';
  if($m==='GET')return ['upload'=>$upload];
  if($action===''&&$m==='DELETE'){for($i=1;$i<=$upload['totalChunks'];$i++){$part=storageDir().'/'.$upload['id'].'.part'.$i;if(is_file($part))unlink($part);}removeEntity($upload['id']);return ['ok'=>true];}
  if(str_starts_with($action,'parts/')&&$m==='PUT'){
   $n=(int)$a[3];if($n<1||$n>$upload['totalChunks'])fail(400,'Invalid part.');$expected=$n===$upload['totalChunks']?$upload['size']-($n-1)*$upload['chunkSize']:$upload['chunkSize'];$data=file_get_contents('php://input',false,null,0,$expected+1);if(strlen($data)!==$expected)fail(400,'Incorrect part size.');
   return transaction(function()use($upload,$n,$data,$id){$upload=entity($upload['id'],'upload',true);$path=storageDir().'/'.$upload['id'].'.part'.$n;if(file_put_contents($path,$data,LOCK_EX)===false)throw new RuntimeException('Cannot save chunk.');chmod($path,0600);$upload['receivedParts']=array_values(array_filter($upload['receivedParts'],fn($p)=>$p['number']!==$n));$upload['receivedParts'][]=['number'=>$n];putEntity('upload',$upload,'',$id);return ['ok'=>true];});
  }
  if($action==='complete'&&$m==='POST')return transaction(function()use($upload,$id){$upload=entity($upload['id'],'upload',true);if(isset($upload['attachmentId']))return ['attachment'=>attachmentView(mustEntity($upload['attachmentId'],'attachment'))];$tmp=tempnam(storageDir(),'assemble-');$f=fopen($tmp,'wb');try{for($n=1;$n<=$upload['totalChunks'];$n++){$path=storageDir().'/'.$upload['id'].'.part'.$n;if(!is_file($path))fail(400,'Missing file part.');$part=fopen($path,'rb');stream_copy_to_stream($part,$f);fclose($part);}fclose($f);$f=null;if(filesize($tmp)!==$upload['size'])fail(400,'File size mismatch.');$att=storeFile($tmp,$upload['filename'],$upload['mime']);$upload['attachmentId']=$att['id'];putEntity('upload',$upload,'',$id);for($n=1;$n<=$upload['totalChunks'];$n++)unlink(storageDir().'/'.$upload['id'].'.part'.$n);return ['attachment'=>attachmentView($att)];}finally{if(is_resource($f))fclose($f);unlink($tmp);}});
 }
 if(preg_match('#^/api/files/([a-z0-9]+)$#',$p,$a)&&in_array($m,['GET','HEAD'],true))outputFile(mustEntity($a[1],'attachment'));
 return null;
}
