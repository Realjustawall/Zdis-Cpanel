"""Behavioral tests of the PHP port on a disposable database only."""
from pathlib import Path
exec((Path(__file__).with_name('integration.py')).read_text().split('admin=Client();')[0])
import subprocess,threading,http.server,hmac,hashlib,copy
PHP=os.environ['ZDIS_PHP_BIN'];APP=Path(__file__).resolve().parents[1]
def cli(code):
 r=subprocess.run([PHP,'-r',"require "+repr(str(APP/'app/bootstrap.php'))+";"+code],capture_output=True,text=True)
 assert r.returncode==0,(r.stdout,r.stderr)
 return r.stdout
admin=Client();admin.login('admin')
users=[]
for name in ['qaowner','qauser','qastranger']:
 uid=admin.req('POST','/api/admin/users',{'username':name,'email':name+'@example.test','password':'TestAdmin12345!'})['user']['id']
 c=Client();c.login(name);users.append((c,uid))
owner,oid=users[0];member,mid=users[1];stranger,sid=users[2]
gid=owner.req('POST','/api/groups',{'name':'Extended QA'},201)['group']['id'];gp=f'/api/groups/{gid}'
owner.req('POST',gp+'/members',{'userId':mid});owner.req('POST',gp+'/members',{'userId':sid})
chs=owner.req('GET',gp)['channels'];cid=next(x['id'] for x in chs if x['type']=='text');mp=f'/api/channels/{cid}/messages'
# Custom roles and channel overrides are enforced, including role allow precedence.
r=owner.req('POST',gp+'/roles',{'name':'QA Writer','permissions':['sendMessages']})['role'];rid=r['id']
owner.req('PUT',gp+f'/channels/{cid}/overrides',{'targetType':'everyone','targetId':gid,'deny':['sendMessages']})
member.req('POST',mp,{'content':'denied'},403)
owner.req('PUT',gp+f'/channels/{cid}/overrides',{'targetType':'role','targetId':rid,'allow':['sendMessages']})
owner.req('PUT',gp+f'/members/{mid}/roles/{rid}',{'granted':True});member.req('POST',mp,{'content':'allowed by role'},201)
owner.req('PUT',gp+f'/channels/{cid}/overrides',{'targetType':'member','targetId':mid,'deny':['sendMessages']})
member.req('POST',mp,{'content':'member deny'},403)
owner.req('DELETE',gp+f'/channels/{cid}/overrides/member/{mid}')
owner.req('DELETE',gp+f'/channels/{cid}/overrides/everyone/{gid}')
# Group moderation cannot promote itself into owner-equivalent legacy roles.
mod=owner.req('POST',gp+'/roles',{'name':'QA helper','permissions':['manageMembers','kickMember','moderateMembers']})['role']
owner.req('PUT',gp+f'/members/{mid}/roles/{mod["id"]}',{})
member.req('PATCH',gp+f'/members/{sid}',{'memberRole':'admin'},403)
# Private forum roots, replies, discovery and locked threads.
fid=owner.req('POST',gp+'/channels',{'name':'Forum QA','type':'forum'})['channel']['id'];fp=f'/api/forums/{fid}/posts'
p=owner.req('POST',fp,{'title':'Hidden','content':'private root','private':True},201)['post'];pid=p['id'];root=p['rootMessageId']
assert member.req('GET',fp)['posts']==[]
member.req('GET',fp+'/'+pid+'/root',status=404)
owner.req('POST',fp+'/'+pid+'/members',{'userId':mid})
assert member.req('GET',fp+'/'+pid+'/root')['message']['content']=='private root'
member.req('POST',f'/api/channels/{fid}/messages',{'content':'reply','replyToId':root},201)
assert stranger.req('GET',f'/api/channels/{fid}/messages')['messages']==[]
owner.req('PATCH',fp+'/'+pid,{'locked':True})
member.req('POST',f'/api/channels/{fid}/messages',{'content':'blocked reply','replyToId':root},403)
# Anonymous poll analytics never disclose individual votes.
poll=owner.req('POST',f'/api/channels/{cid}/polls',{'question':'Anonymous?','options':['Yes','No'],'anonymous':True})['message'];pp=mp+'/'+poll['id']
option=poll['poll']['options'][0]['id'];member.req('PUT',pp+'/poll-vote',{'optionIds':[option]})
analytics=owner.req('GET',pp+'/poll/analytics')['analytics'];assert analytics['uniqueVoters']==1 and analytics['options'][0]['voters']==[]
member.req('GET',pp+'/poll/analytics',status=404)
# API key revocation, scope boundaries and browser cookie/CSRF separation.
admin.req('PATCH','/api/admin/settings',{'feature_api_keys':True,'feature_webhooks':True})
key=owner.req('POST','/api/integrations/api-keys',{'name':'Read only','scopes':['read']});token=key['token'];kid=key['apiKey']['id']
assert 'tokenHash' not in key['apiKey']
api=Client();auth={'Authorization':'Bearer '+token}
assert api.req('GET','/api/groups',headers=auth)['groups']
api.req('POST',mp,{'content':'scope violation'},403,headers=auth)
api.req('GET','/api/admin/users',status=403,headers=auth)
owner.req('DELETE','/api/integrations/api-keys/'+kid)
api.req('GET','/api/groups',status=401,headers=auth)
owner.req('POST','/api/integrations/api-keys',{'name':'bad expiry','expiresAt':'tomorrow'},400)
write=owner.req('POST','/api/integrations/api-keys',{'name':'writer','scopes':['read','write']})['token'];wh={'Authorization':'Bearer '+write}
api.req('POST',mp,{'content':'API send'},201,headers=wh)
# OAuth exact redirect URI, one-time code and form token exchange.
app=owner.req('POST','/api/integrations/apps',{'name':'QA OAuth','redirectUris':['https://client.example.test/callback'],'scopes':['read']});a=app['app']
owner.req('POST','/api/integrations/oauth/authorize',{'clientId':a['clientId'],'redirectUri':'https://evil.example/callback','scopes':['read']},400)
x=owner.req('POST','/api/integrations/oauth/authorize',{'clientId':a['clientId'],'redirectUri':a['redirectUris'][0],'scopes':['read'],'state':'qa'})
from urllib.parse import urlparse,parse_qs,urlencode
code=parse_qs(urlparse(x['redirectUrl']).query)['code'][0]
form={'grant_type':'authorization_code','client_id':a['clientId'],'client_secret':app['clientSecret'],'redirect_uri':a['redirectUris'][0],'code':code}
bearer=api.req('POST','/api/integrations/oauth/token',urlencode(form).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})['access_token']
api.req('POST','/api/integrations/oauth/token',urlencode(form).encode(),401,headers={'Content-Type':'application/x-www-form-urlencoded'})
api.req('GET','/api/groups',headers={'Authorization':'Bearer '+bearer})
# Registered bot installation, commands, incoming webhook and uninstall invalidation.
bot=owner.req('POST','/api/integrations/bots',{'name':'QA integration'},201);bid=bot['bot']['id'];bu=bot['bot']['userId']
owner.req('POST',f'/api/integrations/groups/{gid}/bots/{bid}/install',{})
command=owner.req('POST',f'/api/integrations/groups/{gid}/commands',{'botId':bid,'name':'hello','responseTemplate':'Hello {username}: {args}'})['command']
answer=member.req('POST','/api/integrations/commands/'+command['id']+'/execute',{'channelId':cid,'args':'QA'})['message'];assert answer['authorId']==bu and answer['content']=='Hello qauser: QA'
incoming=owner.req('POST','/api/integrations/incoming',{'botId':bid,'channelId':cid})
api.req('POST',urlparse(incoming['url']).path,{'content':'incoming works'},201)
owner.req('DELETE',f'/api/integrations/groups/{gid}/bots/{bid}/install')
api.req('POST',urlparse(incoming['url']).path,{'content':'uninstalled'},403)
# Webhook worker makes a real HTTP request with a verifiable signature.
received=[]
class Receiver(http.server.BaseHTTPRequestHandler):
 def do_POST(self):
  payload=self.rfile.read(int(self.headers['Content-Length']));received.append((dict(self.headers),payload));self.send_response(204);self.end_headers()
 def log_message(self,*args):pass
server=http.server.ThreadingHTTPServer(('127.0.0.1',8091),Receiver);threading.Thread(target=server.serve_forever,daemon=True).start()
try:
 hook=owner.req('POST','/api/integrations/webhooks',{'name':'QA hook','targetType':'channel','targetId':cid,'endpoint':'http://127.0.0.1:8091/hook','events':['message.created']})
 sent=member.req('POST',mp,{'content':'Deliver this'},201)['message']
 stats=json.loads(cli('echo json(runJobs(50));'));assert stats['completed']>=1 and stats['failed']==0,stats
 assert len(received)==1,received
 headers,data=received[0];headers={k.lower():v for k,v in headers.items()};expected=hmac.new(hook['secret'].encode(),headers['x-zdis-timestamp'].encode()+b'.'+data,hashlib.sha256).hexdigest()
 assert hmac.compare_digest(headers['x-zdis-signature'],'v1='+expected)
 assert json.loads(data)['data']['message']['id']==sent['id']
 deliveries=owner.req('GET','/api/integrations/webhooks/'+hook['webhook']['id']+'/deliveries')['deliveries'];assert deliveries[0]['status']=='delivered'
finally:server.shutdown();server.server_close()
# Built-in moderator settings, economy, self role and ticket privacy.
bp=f'/api/builtin-bots/groups/{gid}'
config=owner.req('GET',bp);s=copy.deepcopy(config['settings']);s['moderator'].update({'enabled':True,'ticketsEnabled':True,'selfRoleIds':[rid],'levelingEnabled':True,'blockedWords':['qa-forbidden']})
owner.req('PUT',bp,{'settings':s,'revision':config['revision']})
owner.req('PUT',bp,{'settings':s,'revision':config['revision']},409)
member.req('POST',mp,{'content':'qa-forbidden'},403)
member.req('POST',bp+'/profile',{'action':'daily'})
member.req('POST',bp+'/profile',{'action':'daily'},400)
member.req('POST',bp+'/profile',{'action':'transfer','userId':sid,'amount':30})
assert member.req('GET',bp+'/profile/'+mid)['profile']['credits']==70
assert stranger.req('GET',bp+'/profile/'+sid)['profile']['credits']==30
roles=member.req('POST',bp+'/self-roles',{'roleId':rid})['roles'];assert next(r for r in roles if r['id']==rid)['assigned']
ticket=member.req('POST',bp+'/tickets',{'topic':'Support needed'},201)['ticket'];tc=ticket['channelId']
stranger.req('GET',f'/api/channels/{tc}/messages',status=404)
member.req('POST',bp+'/tickets',{'topic':'Duplicate'},409)
member.req('PATCH',bp+'/tickets/'+ticket['id'],{'action':'close'})
member.req('POST',f'/api/channels/{tc}/messages',{'content':'Closed ticket'},403)
# Community event RSVP and active boost uniqueness.
event=owner.req('POST',gp+'/events',{'name':'QA event','startsAt':int(time.time()*1000)+3600000},201)['event']
member.req('PUT',gp+'/events/'+event['id']+'/rsvp',{'status':'going'})
assert next(e for e in owner.req('GET',gp+'/community')['events'] if e['id']==event['id'])['goingCount']==1
member.req('POST',gp+'/boosts',{},201);member.req('POST',gp+'/boosts',{},409);member.req('DELETE',gp+'/boosts/mine')
print(json.dumps({'status':'PASS','passed':count,'extraAssertions':['role precedence','private forum confidentiality','anonymous poll privacy','API scopes and revocation','OAuth code replay','bot uninstall','real HTTP webhook HMAC','bot settings conflict','daily/transfer','self roles','ticket privacy and close','event RSVP and boosts']}))
