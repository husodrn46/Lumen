#!/usr/bin/env python3
"""Actual favorite endpoint body over loopback, with synthetic PDO/auth/connection factory."""
import http.client,json,pathlib,socket,subprocess,tempfile,time
from urllib.parse import urlencode
root=pathlib.Path(__file__).resolve().parent
checks=0
def check(ok,label):
 global checks
 checks+=1
 if not ok:raise AssertionError(label)
with tempfile.TemporaryDirectory(prefix='lumen-favorite-') as private:
 with socket.socket() as p:p.bind(('127.0.0.1',0));port=p.getsockname()[1]
 with open(pathlib.Path(private)/'server.log','w+') as log:
  proc=subprocess.Popen(['php','-d','display_errors=0','-d','session.save_path='+private,'-S',f'127.0.0.1:{port}','-t',private,str(root/'support/favorites-router.php')],stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:
     with socket.create_connection(('127.0.0.1',port),.1):break
    except OSError:time.sleep(.05)
   else:raise RuntimeError('Loopback server unavailable')
   cookie=''
   def req(path,body=None):
    global cookie
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
    try:
     c.request('GET' if body is None else 'POST',path,body=urlencode(body or {}),headers={'Cookie':cookie,'Content-Type':'application/x-www-form-urlencoded'})
     r=c.getresponse();text=r.read().decode()
     if r.getheader('Set-Cookie'):cookie=r.getheader('Set-Cookie').split(';')[0]
     return r.status,text
    finally:c.close()
   csrf=json.loads(req('/setup')[1])['csrf'];endpoint='/ayar/kart_favori_kaydet.php'
   def save(query='',value='a.php',extra=None):return req(endpoint+query,{'csrf_token':csrf,'favoriler':value}|(extra or {}))
   def stats():return json.loads(req('/stats')[1])
   def read(query=''):return json.loads(req('/read'+query)[1])['value']
   check(req(endpoint)[0]==405,'GET cannot mutate')
   check(req(endpoint,{'csrf_token':'bad','favoriler':'a.php'})[0]==403,'Bad CSRF before write')
   check(req(endpoint,{'csrf_token[]':'bad','favoriler':'a.php'})[0]==403,'Array CSRF rejected')
   check(req(endpoint,{'csrf_token':csrf,'favoriler[]':'a.php'})[0]==400,'Array preference rejected')
   check(save('?unauth=1')[0]==401,'Auth fixture denies unauthenticated')
   check(save('?role=3')[0]==403,'Auth fixture denies revoked role')
   check(stats()['writes']==0,'Guard failures no new DB writes')
   check(save()[0]==200 and read()=='a.php','Pilot endpoint save/read')
   check(save(value='b.php',extra={'personel':'8','firma':'3','actor':'other'})[0]==200 and read()=='b.php','POST identity ignored; server scope only')
   check(read('?person=8')=='','Other user empty')
   check(read('?firma=3')=='','Other company empty')
   check(read('?connection=other-logo')=='','Other ERP connection empty')
   check(save('?person=8',value='other.php')[0]==200 and read()=='b.php','Other user isolated')
   before=stats()
   for query in ['?connectfail=1','?unmapped=1','?writefail=1','?flag=bad']:
    status,text=save(query,value='lost.php')
    check(status==503 and json.loads(text)['ok'] is False,'Fail closed '+query)
    check('private' not in text and stats()==before,'No details/fallback/partial write '+query)
   check(save('?flag=0',value='legacy-new.php')[0]==200 and stats()==before,'Disabled pilot legacy only')
   check(read()=='b.php','Disabling pilot did not overwrite independent preference')
   log.flush();log.seek(0);text=log.read()
   check('PHP Warning' not in text and 'PHP Fatal' not in text,'No runtime warnings/fatal')
  finally:
   proc.terminate()
   try:proc.wait(timeout=5)
   except subprocess.TimeoutExpired:proc.kill();proc.wait(timeout=5)
print(f'TAMAM: {checks} favorites HTTP assertion (real loopback; synthetic auth/PDO; no real SQL).')
