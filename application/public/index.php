<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');
$appRoot=require __DIR__.'/paths.php';
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if($path==='/setup.php'){require __DIR__.'/setup.php';exit;}
if(!str_starts_with($path,'/api/')){
 if(in_array($path,['/','/index.php','/index.html'],true)&&!is_file(getenv('ZDIS_CONFIG')?:$appRoot.'/config.php')){header('Location: /setup.php',true,302);exit;}
 if($path==='/index.php')$path='/';$real=realpath(__DIR__.$path);if($real&&str_starts_with($real,__DIR__.'/')&&is_file($real)&&!str_ends_with($real,'.php')){
  $ext=pathinfo($real,PATHINFO_EXTENSION);$mime=['js'=>'text/javascript','css'=>'text/css','json'=>'application/json','webmanifest'=>'application/manifest+json','svg'=>'image/svg+xml','woff2'=>'font/woff2','png'=>'image/png','ico'=>'image/x-icon','html'=>'text/html'];header('Content-Type: '.($mime[$ext]??'application/octet-stream'));readfile($real);exit;
 }
 header('Content-Type: text/html; charset=utf-8');readfile(__DIR__.'/index.html');exit;
}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 if(!is_file(getenv('ZDIS_CONFIG')?:$appRoot.'/config.php')){http_response_code(503);echo '{"error":{"code":"setup_required","message":"Configure ZDIS before use. See INSTALL-fa.md."}}';exit;}
 require_once $appRoot.'/app/bootstrap.php';
 $method=$_SERVER['REQUEST_METHOD'];if($method==='OPTIONS'){http_response_code(204);exit;}
 authenticateBearer();csrf();
 $contentType=$_SERVER['CONTENT_TYPE']??'';$body=[];
 if(str_starts_with($contentType,'application/json')){if((int)($_SERVER['CONTENT_LENGTH']??0)>200000)fail(413,'Request too large.');$raw=file_get_contents('php://input',false,null,0,200001);if(strlen($raw)>200000)fail(413,'Request too large.');try{$body=$raw!==''?decode($raw):[];}catch(JsonException){fail(400,'Invalid JSON.');}if(!is_array($body)||array_is_list($body)&&$body!==[])fail(400,'JSON object required.');}
 if($path==='/api/integrations/oauth/token'&&str_starts_with($contentType,'application/x-www-form-urlencoded'))$body=$_POST;
 if($path==='/api/health'&&$method==='GET'){sql('SELECT 1');echo json(['ok'=>true,'runtime'=>'php','database'=>'mysql']);exit;}
 foreach(['integrationRoutes','botRoutes','permissionRoutes','communityRoutes','forumAdvancedRoutes','advancedChatRoutes','fileRoutes','authRoutes','realtimeRoutes','messageRoutes','conversationRoutes','groupRoutes','socialRoutes','adminRoutes','forumRoutes','extraRoutes']as$handler){$result=$handler($method,$path,$body);if($result!==null){echo json($result);exit;}}
 user();if(str_starts_with($path,'/api/admin/')||str_starts_with($path,'/api/moderation/'))admin();fail(501,'This endpoint has not been ported to the PHP edition. See PORTING-STATUS.md.','not_implemented');
}catch(Throwable $e){
 $status=$e instanceof HttpError?$e->status:500;if($e instanceof PDOException&&$e->getCode()==='23000')$status=409;
 if($status===500)error_log('ZDIS: '.$e->getMessage());http_response_code($status);echo json_encode(['error'=>['code'=>$e instanceof HttpError?$e->errorCode:($status===409?'conflict':'internal_error'),'message'=>$e instanceof HttpError?$e->getMessage():($status===409?'A record already exists.':'Server error. Check the PHP error log.')]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
