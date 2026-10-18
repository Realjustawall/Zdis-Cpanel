<?php
declare(strict_types=1);
final class HttpError extends RuntimeException {
 public function __construct(public int $status, string $message, public string $errorCode='error'){parent::__construct($message);}
}
function fail(int $status,string $message,string $code='error'): never {throw new HttpError($status,$message,$code);}
function now(): int {return (int)floor(microtime(true)*1000);}
function uid(): string {return bin2hex(random_bytes(16));}
function json($v): string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function decode(?string $v,$default=[]){return $v===null?$default:json_decode($v,true,512,JSON_THROW_ON_ERROR);}
function cfg(): array {static $c; return $c??=require (getenv('ZDIS_CONFIG')?:dirname(__DIR__).'/config.php');}
function db(): PDO {static $d; if(!$d){$c=cfg();$d=new PDO($c['dsn'],$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$d->exec("SET time_zone = '+00:00'");}return $d;}
function sql(string $q,array $a=[]): PDOStatement {$s=db()->prepare($q);$s->execute($a);return $s;}
function row(string $q,array $a=[]){return sql($q,$a)->fetch()?:null;}
function rows(string $q,array $a=[]): array {return sql($q,$a)->fetchAll();}
function transaction(callable $f){if(db()->inTransaction())return $f();db()->beginTransaction();try{$v=$f();db()->commit();return $v;}catch(Throwable $e){db()->rollBack();throw $e;}}
function entity(string $id,?string $kind=null,bool $lock=false): ?array {$r=row('SELECT * FROM entities WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);if(!$r||($kind!==null&&$r['kind']!==$kind))return null;return decode($r['data']);}
function mustEntity(string $id,string $kind): array {return entity($id,$kind)??fail(404,'Not found.','not_found');}
function putEntity(string $kind,array $v,string $parent='',string $owner=''): array {
 foreach(['name','title','description','reason','note','message','content','question','color','topic','voiceStatus','handle','platform'] as $field)if(isset($v[$field])&&!is_string($v[$field]))fail(400,'Invalid text field: '.$field,'validation_error');
 if(isset($v['tags'])){if(!is_array($v['tags'])||count($v['tags'])>10)fail(400,'Invalid tags.');foreach($v['tags']as$tag)if(!is_string($tag)||mb_strlen($tag)>48)fail(400,'Invalid tag.');}

 $v['id']??=uid();$v['createdAt']??=now();$v['updatedAt']=now();
 sql('INSERT INTO entities(id,kind,parent_id,owner_id,data,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_at=VALUES(updated_at),parent_id=VALUES(parent_id),owner_id=VALUES(owner_id)',[$v['id'],$kind,$parent,$owner,json($v),$v['createdAt'],$v['updatedAt']]);return $v;
}
function listEntities(string $kind,?string $parent=null,?string $owner=null): array {
 $q='SELECT data FROM entities WHERE kind=?';$a=[$kind];if($parent!==null){$q.=' AND parent_id=?';$a[]=$parent;}if($owner!==null){$q.=' AND owner_id=?';$a[]=$owner;}$q.=' ORDER BY created_at,id';return array_map(fn($r)=>decode($r['data']),rows($q,$a));
}
function removeEntity(string $id): void {sql('DELETE FROM entities WHERE id=?',[$id]);}
function required(array $b,string $key,int $max=255,int $min=1): string {$v=$b[$key]??null;if(!is_string($v)||mb_strlen(trim($v))<$min||mb_strlen($v)>$max)fail(400,"Invalid $key.",'validation_error');return trim($v);}
function enumValue($v,array $allowed){if(!in_array($v,$allowed,true))fail(400,'Invalid option.','validation_error');return $v;}
function limit(): int {return max(1,min(500,(int)($_GET['limit']??50)));}
function throttle(string $key,int $max,int $seconds): void {
 $bucket=hash('sha256',$key.':'.(string)intdiv(time(),$seconds));
 sql('INSERT INTO rate_limits(bucket,hits,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1',[$bucket,now()+$seconds*2000]);
 if((int)row('SELECT hits FROM rate_limits WHERE bucket=?',[$bucket])['hits']>$max)fail(429,'Too many requests. Try later.','rate_limited');
}
function account(string $id): ?array {$r=row('SELECT * FROM accounts WHERE id=?',[$id]);if(!$r)return null;$u=decode($r['data']);return $u+['id'=>$r['id'],'username'=>$r['username'],'email'=>$r['email'],'phone'=>$r['phone'],'createdAt'=>(int)$r['created_at']];}
function publicUser(?array $u): ?array {if(!$u)return null;return array_intersect_key($u,array_flip(['id','username','displayName','avatarUrl','bannerColor','bio','presence','customStatus','role','isActive','lastSeenAt','createdAt','badges','ownerStreamerId']));}
function validateUser(array $u): void {
 required($u,'displayName',48);if(!is_string($u['username'])||!preg_match('/^[a-z0-9._-]{3,32}$/D',$u['username']))fail(400,'Invalid username.');
 if(!is_string($u['email'])||strlen($u['email'])>254||!filter_var($u['email'],FILTER_VALIDATE_EMAIL))fail(400,'Invalid email.');
 foreach(['firstName'=>48,'lastName'=>48,'nickname'=>48,'bio'=>300,'customStatus'=>80] as $field=>$max)if(isset($u[$field]))required($u,$field,$max,0);
 if(isset($u['phone'])&&(!is_string($u['phone'])||!preg_match('/^\+?[0-9]{7,20}$/D',$u['phone'])))fail(400,'Invalid phone.');
 if(isset($u['bannerColor'])&&(!is_string($u['bannerColor'])||!preg_match('/^#[a-fA-F0-9]{6}$/D',$u['bannerColor'])))fail(400,'Invalid banner color.');
 foreach(['isActive','totpEnabled','ttsButtonEnabled','mustChangePassword']as$field)if(!is_bool($u[$field]))fail(400,'Invalid boolean: '.$field);
 enumValue($u['presence'],['online','idle','dnd','offline']);

}
function saveUser(array $u): array {
 validateUser($u);
 sql('UPDATE accounts SET username=?,email=?,phone=?,data=? WHERE id=?',[$u['username'],$u['email'],$u['phone'],json($u),$u['id']]);return $u;
}
function checkedPassword(array $b,string $key): string {
 $p=$b[$key]??null;if(!is_string($p)||mb_strlen($p)<10||strlen($p)>200||!preg_match('/[a-z]/',$p)||!preg_match('/[A-Z]/',$p)||!preg_match('/[0-9]/',$p)||!preg_match('/[^A-Za-z0-9]/',$p))fail(400,'Use 10 to 200 characters with uppercase, lowercase, a number and a symbol.');return $p;
}
function hashAccountPassword(string $p): string {return 'sha384:'.password_hash(base64_encode(hash('sha384',$p,true)),PASSWORD_DEFAULT);}
function verifyAccountPassword(string $p,string $hash): bool {return str_starts_with($hash,'sha384:')?password_verify(base64_encode(hash('sha384',$p,true)),substr($hash,7)):password_verify($p,$hash);}
function createUser(array $b,string $role='member'): array {
 $username=strtolower(required($b,'username',32,3));if(!preg_match('/^[a-z0-9._-]+$/D',$username))fail(400,'Invalid username.');
 $email=strtolower(required($b,'email',254));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail(400,'Invalid email.');
 $password=checkedPassword($b,'password');
 $u=['id'=>uid(),'username'=>$username,'email'=>$email,'phone'=>$b['phone']??null,'firstName'=>$b['firstName']??null,'lastName'=>$b['lastName']??null,'nickname'=>$b['nickname']??$b['displayName']??$username,'displayName'=>$b['displayName']??$b['nickname']??$username,'avatarUrl'=>null,'bannerColor'=>'#5865f2','bio'=>null,'presence'=>'offline','customStatus'=>null,'role'=>$role,'isActive'=>true,'lastSeenAt'=>null,'createdAt'=>now(),'badges'=>[],'ownerStreamerId'=>null,'totpEnabled'=>false,'ttsButtonEnabled'=>false,'mustChangePassword'=>false,'passwordChangedAt'=>now(),'lastLoginAt'=>null];
 validateUser($u);
 try{sql('INSERT INTO accounts(id,username,email,phone,password_hash,data,created_at) VALUES(?,?,?,?,?,?,?)',[$u['id'],$username,$email,$u['phone'],hashAccountPassword($password),json($u),$u['createdAt']]);}catch(PDOException $e){if($e->getCode()==='23000')fail(409,'Username, email or phone is already registered.');throw $e;}return $u;
}
function session(): ?array {static $loaded=false,$s=null;if(!$loaded){$loaded=true;$token=$_COOKIE['zdis_session']??'';if($token)$s=row('SELECT * FROM sessions WHERE token_hash=? AND expires_at>?',[hash('sha256',$token),now()]);}return $s;}
function user(): array {if(isset($GLOBALS['service_user'])){$actor=account($GLOBALS['service_user'])??fail(401,'User not found.');if(!$actor['isActive']||($actor['bannedAt']??null)||($actor['suspendedUntil']??0)>now())fail(403,'Account is not active.');return $actor;}static $u; if(!$u){$s=session();if(!$s)fail(401,'Please sign in.','unauthorized');$u=account($s['user_id']);if(!$u||!$u['isActive']||($u['bannedAt']??null)||($u['suspendedUntil']??0)>now())fail(403,'Account is not active.');}return $u;}
function admin(): array {$u=requireCapability('admin');if(settings()['require_2fa_for_admins']&&!$u['totpEnabled'])fail(403,'Enrol two-factor authentication before administration.');return $u;}

function csrf(): void {
 if(isset($GLOBALS['bearer']))return;
 if(in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD','OPTIONS'],true))return;
 $origin=$_SERVER['HTTP_ORIGIN']??null;if($origin!==null&&!hash_equals(rtrim(cfg()['origin'],'/'),$origin))fail(403,'Origin rejected.','csrf');
 $s=session();if($s&&!hash_equals($s['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail(403,'Invalid CSRF token.','csrf');
}
function cookie(string $name,string $value,int $expiry,bool $http=true): void {setcookie($name,$value,['expires'=>$expiry,'path'=>'/','secure'=>cfg()['secure_cookies']??true,'httponly'=>$http,'samesite'=>'Strict']);}
function loginSession(array $u): array {
 $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));$expiry=now()+86400000*(cfg()['session_days']??14);
 sql('INSERT INTO sessions(token_hash,id,user_id,csrf,data,expires_at) VALUES(?,?,?,?,?,?)',[hash('sha256',$token),uid(),$u['id'],$csrf,json(['userAgent'=>substr($_SERVER['HTTP_USER_AGENT']??'',0,512),'ip'=>$_SERVER['REMOTE_ADDR']??'','createdAt'=>now(),'lastUsedAt'=>now()]),$expiry]);
 cookie('zdis_session',$token,(int)($expiry/1000));cookie('ytbl_csrf',$csrf,(int)($expiry/1000),false);
 $u['lastLoginAt']=now();$u['presence']='online';saveUser($u);return ['user'=>$u,'csrfToken'=>$csrf,'settings'=>settings(),'needsTotpEnrolment'=>false];
}
function settings(): array {$defaults=decode(file_get_contents(__DIR__.'/defaults.json'));$custom=entity('settings','settings')??[];return array_replace($defaults,$custom);}
function member(string $scope,string $user): ?array {$r=row('SELECT data FROM memberships WHERE scope_id=? AND user_id=?',[$scope,$user]);return $r?decode($r['data']):null;}
function addMember(string $scope,string $user,array $data=[]): void {if(!account($user))fail(404,'User not found.');$v=$data+['memberRole'=>'member','nickname'=>null,'muted'=>false,'joinedAt'=>now(),'invitedBy'=>null,'serverRoles'=>[]];sql('INSERT INTO memberships(scope_id,user_id,data) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)',[$scope,$user,json($v)]);}
function memberIds(string $scope): array {return array_column(rows('SELECT user_id FROM memberships WHERE scope_id=?',[$scope]),'user_id');}
function members(string $scope): array {return array_map(fn($r)=>publicUser(account($r['user_id']))+array_replace(decode($r['data']),entity($scope,'group')?['serverRoles'=>array_values(array_filter(memberServerRoles($scope,$r['user_id']),fn($role)=>!$role['isDefault']))]:[]) ,rows('SELECT * FROM memberships WHERE scope_id=?',[$scope]));}
function groupAccess(string $id,bool $manage=false): array {
 $context=groupContext($id);if($manage)gp($id,groupActionPermission($_SERVER['REQUEST_METHOD']??'PATCH',parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:''));return $context['group'];
}
function canTarget(string $type,string $id,?string $who=null): array {
 $uid=$who??user()['id'];if($type==='conversations'){$c=mustEntity($id,'conversation');if(!member($id,$uid))fail(404,'Conversation not found.');return $c;}
 $c=mustEntity($id,'channel');if(!channelVisible($c,$uid))fail(404,'Channel not found.');return $c;
}

function audience(string $type,string $id): array {
 $c=entity($id);if(!$c)return [];
 if($type==='conversations')return memberIds($id);
 return array_values(array_filter(memberIds($c['groupId']),function($u)use($id){try{canTarget('channels',$id,$u);return true;}catch(HttpError){return false;}}));
}
function emitUsers(array $ids,string $event,array $payload): void {foreach(array_unique($ids) as $id)sql('INSERT INTO events(user_id,name,payload,created_at) VALUES(?,?,?,?)',[$id,$event,json($payload),now()]);}
function emitGroup(string $id,string $event,array $payload): void {emitUsers(memberIds($id),$event,$payload);}
function audit(string $action,?string $target=null,array $meta=[]): void {putEntity('audit',['actorId'=>session()['user_id']??null,'actorUsername'=>session()?account(session()['user_id'])['username']:null,'actorDisplayName'=>session()?account(session()['user_id'])['displayName']:null,'action'=>$action,'targetId'=>$target,'targetType'=>null,'meta'=>$meta,'ip'=>$_SERVER['REMOTE_ADDR']??null]);}
function notify(string $u,string $type,string $title,string $body,array $data=[]): void {$n=putEntity('notification',['type'=>$type,'title'=>$title,'body'=>$body,'data'=>$data,'readAt'=>null],$u,$u);emitUsers([$u],'notification:created',['notification'=>$n]);}
function permissions(string $gid): array {
 $ctx=groupContext($gid);$out=[];foreach(permissionKeys() as$key)$out[$key]=in_array($key,$ctx['effective'],true);$out['deleteGroup']=$ctx['platformAdmin']||$ctx['role']==='owner';$out['createChannels']=$out['manageChannels'];$out['highestRolePosition']=$ctx['position'];$out['effective']=$ctx['effective'];return $out;
}

function groupView(array $g): array {$g['memberCount']=count(memberIds($g['id']));$g['channelCount']=count(listEntities('channel',$g['id']));$g['memberRole']=member($g['id'],user()['id'])['memberRole']??null;return $g;}
function conversationView(array $c): array {$c['members']=array_map(fn($id)=>publicUser(account($id)),memberIds($c['id']));$c['otherMembers']=array_values(array_filter($c['members'],fn($u)=>$u['id']!==user()['id']));$last=sql('SELECT data FROM entities WHERE kind=? AND parent_id=? ORDER BY created_at DESC,id DESC LIMIT 1',['message',$c['id']])->fetch();$m=$last?decode($last['data']):null;$c['lastMessage']=$m?['id'=>$m['id'],'preview'=>$m['type']==='encrypted'?'Encrypted message':mb_substr($m['content'],0,160),'encrypted'=>$m['type']==='encrypted','encryptedContent'=>$m['type']==='encrypted'?$m['content']:null,'authorId'=>$m['authorId'],'authorName'=>account($m['authorId'])['displayName']??'','createdAt'=>$m['createdAt']]:null;return $c;}
