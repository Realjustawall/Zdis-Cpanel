"""Real first-install and administrator password tests. Disposable databases only."""
from pathlib import Path
import os,subprocess,tempfile,shutil,urllib.request,urllib.error,http.cookiejar,re,json,time,secrets
if os.environ.get('ZDIS_TEST_DISPOSABLE')!='1':raise SystemExit('Disposable databases only')
APP=Path(__file__).resolve().parents[1];PHP=os.environ.get('ZDIS_PHP_BIN','php');count=0
config=json.loads(subprocess.check_output([PHP,'-r',"$c=require getenv('ZDIS_CONFIG');echo json_encode(array_intersect_key($c,array_flip(['dsn','db_user','db_password'])));"],text=True))
name='zdis_install_'+secrets.token_hex(5)
def database(sql):
 code="$c=require getenv('ZDIS_CONFIG');$d=new PDO($c['dsn'],$c['db_user'],$c['db_password']);$d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$d->exec("+json.dumps(sql)+");"
 subprocess.run([PHP,'-r',code],check=True,capture_output=True)
database('CREATE DATABASE `'+name+'` CHARACTER SET utf8mb4')
BASE='http://127.0.0.1:8089';jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def req(method,path,body=None,status=200,kind='form',client=None):
 global count
 from urllib.parse import urlencode
 headers={'Origin':BASE}
 if body is not None:
  if kind=='json':body=json.dumps(body).encode();headers['Content-Type']='application/json'
  else:body=urlencode(body).encode();headers['Content-Type']='application/x-www-form-urlencoded'
 for c in (jar if client is None else client[1]):
  if c.name=='ytbl_csrf':headers['X-CSRF-Token']=c.value
 try:r=(opener if client is None else client[0]).open(urllib.request.Request(BASE+path,data=body,headers=headers,method=method))
 except urllib.error.HTTPError as e:r=e
 data=r.read().decode();assert r.code==status,(method,path,r.code,data[:400],status);count+=1;return data
server=None
try:
 with tempfile.TemporaryDirectory(prefix='zdis-installer-')as folder:
  app=Path(folder)/'application';shutil.copytree(APP,app,ignore=shutil.ignore_patterns('config.php','storage','__pycache__'));(app/'storage').mkdir(mode=0o700)
  env=os.environ.copy();env.pop('ZDIS_CONFIG',None)
  with tempfile.TemporaryFile(mode='w+')as log:
   server=subprocess.Popen([PHP,'-S','127.0.0.1:8089','-t',str(app/'public'),str(app/'public/index.php')],env=env,stdout=log,stderr=log)
   try:
    for _ in range(100):
     try:page=req('GET','/');break
     except urllib.error.URLError:time.sleep(.1)
    else:raise RuntimeError('installer server failed')
    assert 'راه‌اندازی ZDIS' in page
    csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page)[1]
    dsn=config['dsn'];host=re.search(r'host=([^;]+)',dsn)[1];port=(re.search(r'port=([^;]+)',dsn)or[None,'3306'])[1]
    form={'csrf':csrf,'action':'check','db_host':host,'db_port':port,'db_name':name,'db_user':config['db_user'],'db_password':config['db_password'],'origin':BASE}
    req('POST','/setup.php',dict(form,csrf='invalid'),403)
    result=json.loads(req('POST','/setup.php',form));assert result['ok'];assert not(app/'config.php').exists()
    database('CREATE TABLE `'+name+'`.`keep_existing` (id INT)');req('POST','/setup.php',form,409);database('DROP TABLE `'+name+'`.`keep_existing`')
    req('POST','/setup.php',dict(form,db_name='missing_'+name),400)
    req('POST','/setup.php',dict(form,origin='http://example.test'),400)
    req('POST','/setup.php',dict(form,action='install',username='siteadmin',email='owner@example.test',password='short',password_confirmation='short'),400)
    req('POST','/setup.php',dict(form,action='install',username='siteadmin',email='owner@example.test',password='InstallerAdmin123!',password_confirmation='mismatch'),400)
    req('POST','/setup.php',dict(form,action='install',username='siteadmin',email='owner@example.test',displayName='x'*100,password='InstallerAdmin123!',password_confirmation='InstallerAdmin123!'),400)
    assert json.loads(req('POST','/setup.php',form))['ok']
    assert not(app/'config.php').exists()
    if os.environ.get('ZDIS_INSTALLER_BROWSER')=='1':
     env['ZDIS_INSTALLER_INPUT']=json.dumps({k:v for k,v in form.items()if k not in ['csrf','action']});subprocess.run(['node',str(APP/'tests/installer-browser.mjs')],env=env,check=True)
    else:req('POST','/setup.php',dict(form,action='install',username='siteadmin',email='owner@example.test',password='InstallerAdmin123!',password_confirmation='InstallerAdmin123!'))
    assert app.joinpath('config.php').is_file() and (app.joinpath('config.php').stat().st_mode&0o777)==0o600
    req('GET','/setup.php',status=403);before=app.joinpath('config.php').read_bytes();req('POST','/setup.php',form,403);assert before==app.joinpath('config.php').read_bytes()
    login=json.loads(req('POST','/api/auth/login',{'identifier':'siteadmin','password':'InstallerAdmin123!'},kind='json'));assert login['user']['role']=='admin';uid=login['user']['id']
    req('GET','/api/admin/settings');req('PATCH','/api/admin/settings',{'motd':'Installed via wizard'},kind='json')
    otherjar=http.cookiejar.CookieJar();other=(urllib.request.build_opener(urllib.request.HTTPCookieProcessor(otherjar)),otherjar)
    req('POST','/api/auth/login',{'identifier':'siteadmin','password':'InstallerAdmin123!'},kind='json',client=other)
    req('POST','/api/auth/password',{'currentPassword':'wrong','newPassword':'ChangedAdmin123!'},401,'json')
    req('POST','/api/auth/password',{'currentPassword':'InstallerAdmin123!','newPassword':'ChangedAdmin123!'},kind='json')
    req('GET','/api/admin/settings',status=401,client=other)
    req('POST','/api/auth/login',{'identifier':'siteadmin','password':'InstallerAdmin123!'},401,'json',other)
    req('POST','/api/auth/login',{'identifier':'siteadmin','password':'ChangedAdmin123!'},kind='json',client=other)
    req('GET','/api/admin/overview',client=other)
    suggested=json.loads(req('GET','/api/admin/users/suggest-password',client=other))['password'];assert re.search('[A-Z]',suggested) and re.search('[a-z]',suggested) and re.search('[0-9]',suggested) and re.search('[^A-Za-z0-9]',suggested)
    target=json.loads(req('POST','/api/admin/users',{'username':'resetqa','email':'reset@example.test','password':'TemporaryUser123!'},kind='json',client=other))['user']
    req('POST','/api/admin/users/'+target['id']+'/password',{'password':suggested,'mustChangePassword':False},kind='json',client=other)
    targetjar=http.cookiejar.CookieJar();targetclient=(urllib.request.build_opener(urllib.request.HTTPCookieProcessor(targetjar)),targetjar)
    req('POST','/api/auth/login',{'identifier':'resetqa','password':suggested},kind='json',client=targetclient);req('GET','/api/admin/settings',status=403,client=targetclient)
    print(json.dumps({'status':'PASS','passed':count,'tested':['first-visit redirect','CSRF','real MySQL connection check','invalid database','HTTPS origin validation','password validation','wizard browser install' if os.environ.get('ZDIS_INSTALLER_BROWSER')=='1' else 'HTTP install','private config generation','installation lock','admin settings','password change and old-session revocation']}))
   except BaseException:
    log.seek(0);print(log.read()[-10000:]);raise
   finally:server.terminate();server.wait(timeout=15);server=None
finally:
 if server:server.terminate();server.wait(timeout=15)
 database('DROP DATABASE `'+name+'`')
