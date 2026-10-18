"""Integration tests against a DISPOSABLE PHP/MySQL instance; never production."""
import sys,os
if os.environ.get("ZDIS_TEST_DISPOSABLE") != "1": raise SystemExit("Set ZDIS_TEST_DISPOSABLE=1 only for an isolated test database.")
import json,urllib.request,urllib.error,http.cookiejar,base64,os,time
BASE=os.environ.get('ZDIS_TEST_URL','http://127.0.0.1:8087');count=0
class Client:
 def __init__(self):self.jar=http.cookiejar.CookieJar();self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
 def req(self,m,p,b=None,status=200,csrf=True,headers=None,raw=False):
  global count
  h={'Origin':BASE};h.update(headers or {})
  if csrf:
   for c in self.jar:
    if c.name=='ytbl_csrf':h['X-CSRF-Token']=c.value
  if b is not None and not isinstance(b,bytes):b=json.dumps(b).encode();h['Content-Type']='application/json'
  try:r=self.http.open(urllib.request.Request(BASE+p,data=b,headers=h,method=m))
  except urllib.error.HTTPError as e:r=e
  data=r.read();assert r.code==status,(m,p,r.code,data[:700],status);count+=1
  return (data,r.headers) if raw else json.loads(data)
 def login(self,name):return self.req('POST','/api/auth/login',{'identifier':name,'password':'TestAdmin12345!'})
admin=Client();anon=Client();alice=Client();bob=Client();eve=Client()
assert anon.req('GET','/api/health')['database']=='mysql'
assert anon.req('GET','/api/auth/me')['user'] is None
anon.req('GET','/api/groups',status=401)
anon.req('POST','/api/auth/login',{'identifier':'admin','password':'wrong'},401)
admin.login('admin');admin.req('PATCH','/api/admin/settings',{'registration_enabled':True,'spam_messages_per_30s':100})
for name,cl in [('alice',alice),('bob',bob),('eve',eve)]:
 admin.req('POST','/api/admin/users',{'username':name,'email':name+'@example.test','password':'TestAdmin12345!','displayName':name,'role':'member'});cl.login(name)
aid=alice.req('GET','/api/auth/me')['user']['id'];bid=bob.req('GET','/api/auth/me')['user']['id']
alice.req('GET','/api/admin/users',status=403)
alice.req('PATCH','/api/auth/profile',{'displayName':'آلیس آزمایشی'})
alice.req('PATCH','/api/auth/profile',{'customStatus':{'bad':'object'}},400)
alice.req('PATCH','/api/auth/profile',{'bannerColor':{'bad':'object'}},400)
alice.req('PATCH','/api/auth/profile',{'role':'admin'});assert alice.req('GET','/api/auth/me')['user']['role']=='member'
alice.req('PATCH','/api/auth/profile',{'displayName':'bad'},403,csrf=False)
g=alice.req('POST','/api/groups',{'name':'آزمایش ZDIS'},201)['group'];gid=g['id'];gd=alice.req('GET','/api/groups/'+gid)
cid=gd['channels'][0]['id'];voice=next(c['id'] for c in gd['channels'] if c['type']=='voice');cp=f'/api/channels/{cid}'
eve.req('GET','/api/groups/'+gid,status=404);alice.req('POST',f'/api/groups/{gid}/members',{'userId':bid});bob.req('PATCH','/api/groups/'+gid,{'name':'hijack'},403)
msg=alice.req('POST',cp+'/messages',{'content':'سلام 👋','clientId':'one'},201)['message'];mid=msg['id'];mp=cp+'/messages/'+mid
assert msg['content']=='سلام 👋'
assert alice.req('POST',cp+'/messages',{'content':'duplicate','clientId':'one'},201)['message']['id']==mid
assert len(bob.req('GET',cp+'/messages')['messages'])==1
bob.req('PATCH',mp,{'content':'hijack'},403);eve.req('GET',cp+'/messages',status=404)
alice.req('PATCH',mp,{'content':'edited'})
bob.req('PUT',mp+'/reactions/%F0%9F%91%8D');assert bob.req('GET',cp+'/messages')['messages'][0]['reactions'][0]['count']==1
bob.req('PUT',mp+'/reactions/%F0%9F%91%8D');alice.req('PUT',mp+'/pin',{'pinned':True});assert len(bob.req('GET',cp+'/pins')['messages'])==1
alice.req('PUT',mp+'/saved',{'saved':True});assert len(alice.req('GET','/api/saved')['messages'])==1
alice.req('POST',cp+'/read',{});assert alice.req('GET','/api/unread')['channels'][0]['unread']==0
private=alice.req('POST',f'/api/groups/{gid}/channels',{'name':'private','isPrivate':True})['channel'];privateId=private['id']
bob.req('GET',f'/api/channels/{privateId}/messages',status=404)
alice.req('POST',f'/api/groups/{gid}/channels/{privateId}/members',{'userId':bid});bob.req('GET',f'/api/channels/{privateId}/messages');alice.req('POST',f'/api/channels/{privateId}/messages',{'content':'secret'},201)
rs=eve.req('GET','/api/realtime?connection='+'e'*32+'&cursor=0')['events'];assert not any(x['name']=='message:created' for x in rs)
inv=alice.req('POST',f'/api/groups/{gid}/invites',{'maxUses':1})['invite'];eve.req('POST','/api/groups/join/'+inv['code'],{});admin.req('POST','/api/groups/join/'+inv['code'],{},400)
eve.req('GET',f'/api/channels/{privateId}/messages',status=404)
dm=alice.req('POST','/api/conversations/dm',{'userId':bid})['conversation'];did=dm['id'];dp='/api/conversations/'+did
assert alice.req('POST','/api/conversations/dm',{'userId':bid})['conversation']['id']==did
eve.req('GET',dp,status=404);alice.req('POST',dp+'/messages',{'content':'plain'},400)
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric.utils import decode_dss_signature
b64=lambda b:base64.urlsafe_b64encode(b).decode().rstrip('=');unb64=lambda s:base64.urlsafe_b64decode(s+'='*((-len(s))%4))
i=alice.req('GET','/api/e2ee/identity/bootstrap')['identity'];key=ec.derive_private_key(int.from_bytes(unb64(i['privateKey']['signing']['d']),'big'),ec.SECP256R1())
e={'v':1,'alg':'ECDH-P256+A256GCM+ES256','sender':aid,'senderPublic':i['publicKey'],'iv':b64(os.urandom(12)),'ciphertext':b64(os.urandom(30)),'keys':{u:{'iv':b64(os.urandom(12)),'ciphertext':b64(os.urandom(48))} for u in [aid,bid]}}
r,s=decode_dss_signature(key.sign(json.dumps(e,ensure_ascii=False,separators=(',',':')).encode(),ec.ECDSA(hashes.SHA256())));e['signature']=b64(r.to_bytes(32,'big')+s.to_bytes(32,'big'));content='e2ee:v1:'+b64(json.dumps(e,ensure_ascii=False,separators=(',',':')).encode())
assert alice.req('POST',dp+'/messages',{'content':content,'encrypted':True},201)['message']['type']=='encrypted'
assert bob.req('GET',dp+'/messages')['messages'][0]['content']==content
e['signature']=b64(bytes(64));bad='e2ee:v1:'+b64(json.dumps(e,separators=(',',':')).encode());alice.req('POST',dp+'/messages',{'content':bad,'encrypted':True},400)
boundary='ZDIS_TEST_BOUNDARY';body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="test.txt"\r\nContent-Type: text/plain\r\n\r\nhello from ZDIS\r\n--{boundary}--\r\n').encode()
att=alice.req('POST','/api/files',body,201,headers={'Content-Type':'multipart/form-data; boundary='+boundary})['attachment'];assert 'path' not in att
bob.req('GET',att['url'],status=404);alice.req('POST',cp+'/messages',{'content':'file','attachmentIds':[att['id']]},201)
assert bob.req('GET',att['url'],raw=True)[0]==b'hello from ZDIS';assert bob.req('GET',att['url'],status=206,headers={'Range':'bytes=0-4'},raw=True)[0]==b'hello'
alice.req('POST',cp+'/messages',{'content':'reuse','attachmentIds':[att['id']]},403)
up=alice.req('POST','/api/files/resumable',{'filename':'resume.txt','mime':'text/plain','size':8})['upload'];upath='/api/files/resumable/'+up['id']
alice.req('PUT',upath+'/parts/1',b'resumed!',headers={'Content-Type':'application/octet-stream'});ratt=alice.req('POST',upath+'/complete',{})['attachment'];assert ratt['size']==8;assert alice.req('POST',upath+'/complete',{})['attachment']['id']==ratt['id']
f=alice.req('POST','/api/network/friends',{'username':'bob'})['friendship'];alice.req('PATCH','/api/network/friends/'+f['id'],{'status':'accepted'},403);bob.req('PATCH','/api/network/friends/'+f['id'],{'status':'accepted'});assert bob.req('GET','/api/network/friends')['friendships'][0]['status']=='accepted'
assert bob.req('GET','/api/notifications')['unread']>0;bob.req('POST','/api/notifications/read',{});assert bob.req('GET','/api/notifications')['unread']==0
poll=alice.req('POST',cp+'/polls',{'question':'Q?','options':['A','B'],'anonymous':True})['message'];oid=poll['poll']['options'][0]['id'];pp=cp+'/messages/'+poll['id'];bob.req('PUT',pp+'/poll-vote',{'optionIds':[oid]});pv=alice.req('GET',pp)['message']['poll'];assert pv['options'][0]['votes']==1 and pv['options'][0]['userIds']==[] and pv['viewerOptionIds']==[]
def event(cl,conn,name,payload={},status=200):return cl.req('POST','/api/realtime',{'connection':conn,'event':name,'payload':payload},status)
ac='a'*32;bc='b'*32;ecx='e'*32
for cl,conn in [(alice,ac),(bob,bc),(eve,ecx)]:cl.req('GET','/api/realtime?connection='+conn)
event(eve,ecx,'voice:offer',{'to':aid,'payload':{}},403);assert event(alice,ac,'voice:join',{'channelId':voice})['ok'];assert len(event(bob,bc,'voice:join',{'channelId':voice})['peers'])==1
event(bob,bc,'voice:offer',{'to':aid,'payload':{'type':'offer','sdp':'test'}});rs=alice.req('GET','/api/realtime?connection='+ac+'&cursor=0')['events'];assert any(x['name']=='voice:offer' and x['payload']['from']==bid for x in rs);event(bob,bc,'voice:leave')
alice.req('DELETE',f'/api/groups/{gid}/members/{bid}');rs=bob.req('GET','/api/realtime?connection='+bc+'&cursor=0')['events'];assert not any(x['name']=='message:created' and x['payload']['message']['channelId'] for x in rs)
bob.req('GET',att['url'],status=404);alice.req('DELETE',mp);alice.req('GET',mp,status=404)
assert admin.req('GET','/api/admin/audit')['logs'];admin.req('PATCH','/api/admin/settings',{'max_upload_mb':0},400);admin.req('PATCH','/api/admin/settings',{'feature_api_keys':True});alice.req('GET','/api/builtin-bots/unknown/settings',status=501)
# TOTP enrolment, recovery-code consumption, session revocation and cron publication.
import hmac,hashlib,struct,subprocess
secret=alice.req('POST','/api/auth/totp/setup',{})['secret']
def totp(secret):
 key=base64.b32decode(secret+'='*((-len(secret))%8));digest=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();off=digest[-1]&15;return str((struct.unpack('>I',digest[off:off+4])[0]&0x7fffffff)%1000000).zfill(6)
codes=alice.req('POST','/api/auth/totp/enable',{'token':totp(secret)})['recoveryCodes'];assert len(codes)==8
other=Client();assert other.login('alice')['mfaRequired']
other.req('POST','/api/auth/login',{'identifier':'alice','password':'TestAdmin12345!','totp':codes[0]})
assert other.req('GET','/api/auth/totp/recovery-codes')['remaining']==7
alice.req('POST','/api/auth/sessions/revoke-others',{});other.req('GET','/api/groups',status=401)
alice.req('POST','/api/auth/totp/disable',{'password':'TestAdmin12345!','token':totp(secret)})
scheduled=alice.req('POST',cp+'/scheduled',{'content':'Cron published message','sendAt':int(time.time()*1000)+1200})['scheduled']
time.sleep(1.3)
cron=subprocess.run([os.environ.get('ZDIS_PHP_BIN','php'),str(__import__('pathlib').Path(__file__).resolve().parents[1]/'bin/cron.php')],capture_output=True);assert cron.returncode==0,cron.stdout+cron.stderr
assert any(m['content']=='Cron published message' for m in alice.req('GET',cp+'/messages')['messages'])
print(json.dumps({'passed':count,'status':'PASS','tested':['auth','csrf','groups','invites','private channels','message idempotency','reactions','pins','saved','unread','E2EE signatures','multipart files','range requests','resumable uploads','friends','notifications','polls','voice signalling','revocation','audit']},ensure_ascii=False))
