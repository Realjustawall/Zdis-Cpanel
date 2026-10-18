"""Run only against a fresh, disposable MySQL database configured by ZDIS_CONFIG."""
import os,sys,subprocess,pathlib,time,urllib.request,tempfile,shutil,json
if os.environ.get('ZDIS_TEST_DISPOSABLE')!='1':raise SystemExit('Set ZDIS_TEST_DISPOSABLE=1 on a disposable database only.')
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('ZDIS_PHP_BIN','php');app=root/'application';env=os.environ.copy();env['ZDIS_PHP_BIN']=php
out=root/'build/validation';out.mkdir(parents=True,exist_ok=True)
env['ZDIS_INSTALLER_BROWSER']='1' if '--browser' in sys.argv else '0'
installer=subprocess.run([sys.executable,str(app/'tests/installer.py')],env=env,text=True,capture_output=True)
print(installer.stdout,flush=True);print(installer.stderr,flush=True)
if installer.returncode:raise RuntimeError('Installer regression failed')
(out/'installer-result.txt').write_text(installer.stdout)
subprocess.run([php,str(app/'bin/install.php')],input='admin\nadmin@example.test\n',text=True,env=env,check=True)
with tempfile.TemporaryFile(mode='w+') as log:
 server=subprocess.Popen([php,'-d','upload_max_filesize=32M','-d','post_max_size=34M','-S','127.0.0.1:8087','-t',str(app/'public'),str(app/'public/index.php')],env=env,stdout=log,stderr=log)
 try:
  for i in range(100):
   try:
    with urllib.request.urlopen('http://127.0.0.1:8087/api/health',timeout=1) as r:assert r.status==200
    break
   except Exception:
    if server.poll() is not None:raise RuntimeError('PHP server exited')
    time.sleep(.1)
  else:raise RuntimeError('PHP server did not start')
  for suite in ['integration','extended']:
   subprocess.run([php,'-r',"require 'application/app/core.php'; sql('DELETE FROM rate_limits');"],cwd=root,env=env,check=True)
   result=subprocess.run([sys.executable,str(app/'tests'/f'{suite}.py')],env=env,text=True,capture_output=True)
   print(result.stdout,flush=True);print(result.stderr,flush=True)
   if result.returncode:raise RuntimeError(suite+' failed')
   json.loads(result.stdout);(out/f'{suite}-result.json').write_text(result.stdout)
  if '--browser' in sys.argv:subprocess.run(['node',str(app/'tests/browser-smoke.mjs')],cwd=root,env=env,check=True)
 except BaseException:
  log.seek(0);print(log.read()[-16000:],file=sys.stderr);raise
 finally:
  server.terminate();server.wait(timeout=15)
