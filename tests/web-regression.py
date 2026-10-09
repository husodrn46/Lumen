#!/usr/bin/env python3
"""Actual web endpoint POST/redirect/HTML over loopback; synthetic PDO/auth, no production bootstrap."""
import http.client, json, pathlib, re, shutil, socket, subprocess, tempfile, time
from urllib.parse import urlencode
root=pathlib.Path(__file__).resolve().parent
checks=0

def check(ok, label):
    global checks
    checks+=1
    if not ok: raise AssertionError(label)

with tempfile.TemporaryDirectory(prefix='lumen-web-') as private:
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0)); port=probe.getsockname()[1]
    with open(pathlib.Path(private)/'server.log','w+') as log:
        server=subprocess.Popen([shutil.which('php'),'-d','display_errors=0','-d','session.save_path='+private,'-S',f'127.0.0.1:{port}','-t',private,str(root/'support/web-router.php')],stdout=log,stderr=log)
        try:
            for _ in range(50):
                if server.poll() is not None: raise RuntimeError('Server unavailable')
                try:
                    with socket.create_connection(('127.0.0.1',port),.1): break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('Server timeout')
            cookie=''
            def request(path,fields=None):
                global cookie
                conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
                try:
                    conn.request('POST' if fields is not None else 'GET',path,body=urlencode(fields or {}),headers={'Cookie':cookie,'Content-Type':'application/x-www-form-urlencoded'})
                    res=conn.getresponse(); text=res.read().decode()
                    if res.getheader('Set-Cookie'): cookie=res.getheader('Set-Cookie').split(';')[0]
                    return res.status,dict(res.getheaders()),text
                finally: conn.close()
            def form(text):
                return dict(re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)">',text))
            def stats(): return json.loads(request('/stats')[2])
            tl='/siparis/fisekle.php?cariid=60&stokhareket=0'
            status,headers,text=request(tl); fields=form(text)
            check(status==200 and len(fields['web_intent_key'])==64,'GET displays keyed form')
            check(len(stats()['headers'])==0,'GET cannot write an order')
            check(headers['Cache-Control']=='no-store','Intent form not cached')
            check(form(request(tl)[2])['web_intent_key']==fields['web_intent_key'],'GET refresh same open intent')
            bad=fields|{'csrf_token':'bad'}
            check(request(tl,bad)[0]==403 and len(stats()['headers'])==0,'CSRF rejects before write')
            check(request(tl,fields|{'web_intent_key':'a'*64})[0]==409,'Unknown key fails closed')
            check(request(tl+'&deny=1',fields)[1].get('Location')=='/403.html','Revoked module permission blocks retry')
            status,headers,_=request(tl,fields)
            check(status==303 and 'stokhareket=41' in headers['Location'],'POST success redirects 303')
            check(len(stats()['headers'])==1 and len(stats()['receipts'])==1,'Atomic header/receipt fixture')
            check(request(tl,fields)[1]['Location']==headers['Location'] and len(stats()['headers'])==1,'Double POST/browser refresh same order')
            changed=fields|{'web_fiyat':'3'}
            check(request(tl,changed)[0]==409 and len(stats()['headers'])==1,'Changed content does not create new order')
            new=form(request(tl)[2])
            check(new['web_intent_key']!=fields['web_intent_key'],'Success then new form rotates key')
            check(request(tl+'&mode=receipt_fail',new)[0]==500 and len(stats()['headers'])==1,'Receipt failure rolls back header')
            check(form(request(tl)[2])['web_intent_key']==new['web_intent_key'],'Failure and GET refresh retain intent')
            check(request(tl,new)[0]==303 and len(stats()['headers'])==2,'Rollback retry same key succeeds')
            new=form(request(tl)[2])
            check(request(tl+'&mode=acklost',new)[0]==500 and len(stats()['headers'])==3,'Commit ACK lost returns uncertain error')
            check(request(tl,new)[0]==303 and len(stats()['headers'])==3,'ACK lost replay does not duplicate')
            check(request(tl+'&person=8',new)[0]==409,'Owner change rejects old form')
            check(request(tl+'&deny_cari=1',new)[1].get('Location')=='/403.html','Cari permission rechecked')
            dv='/doviz/fisekle.php?cariid=60&doviz=20&kur=32.123456'
            status,_,text=request(dv); df=form(text)|{'kur':'32.123456','genexp1':'Türkçe not'}
            check(status==200 and 'web_intent_key' in df,'FX GET keyed form')
            check(request(dv,df|{'csrf_token':'bad'})[0]==403,'FX CSRF gate')
            status,_,text=request(dv+'&mode=acklost',df)
            check(status==500 and '32.123456' in text and 'Türkçe not' in text,'FX uncertain response preserves exact rate/note')
            check('readonly' in text,'Attempted FX content frozen')
            check(request(dv,df|{'kur':'33'})[0]==409,'FX change after attempt rejected')
            status,headers,_=request(dv,df)
            check(status==303 and 'fis.php?id=44'==headers['Location'],'FX original key replays')
            check(len(stats()['headers'])==4,'FX ACK lost no duplicate')
            df2=form(request(dv)[2])|{'kur':'34','genexp1':''}
            check(df2['web_intent_key']!=df['web_intent_key'],'FX new order new key')
            check(request(dv+'&audit_fail=1',df2)[0]==303,'Audit failure cannot obscure committed success')
            missing=form(request(tl)[2]); before=len(stats()['headers'])
            check(request(tl+'&mode=missing',missing)[0]==503 and len(stats()['headers'])==before,'Missing migration no fallback write')
            check(request(tl+'&mode=busy',missing)[0]==503 and len(stats()['headers'])==before,'Lock busy retry safe')
            check(request('/doviz/fisekle.php?cariid=60&doviz=99')[0]==400,'Invalid currency code rejected')
            before=len(stats()['headers'])
            check(request(tl,{k:v for k,v in missing.items() if k!='csrf_token'}|{'csrf_token[]':'bad'})[0]==403,'Malformed CSRF type rejects')
            check(request(tl,missing|{'web_cariid':'1e2'})[0]==400,'Noninteger customer rejects')
            check(request(dv,df2|{'kur':'32oops'})[0]==400,'Partially numeric rate rejects')
            check(request(dv,df2|{'kur':'1e309'})[0]==400,'Infinite rate rejects')
            check(request(dv,{k:v for k,v in df2.items() if k!='genexp1'}|{'genexp1[]':'bad'})[0]==400,'Array note rejects')
            check(len(stats()['headers'])==before,'Malformed inputs no write')
            check(request('/siparis/web_intent.js')[0]==200,'Submit guard asset served')
            log.flush(); log.seek(0); errors=log.read()
            check('PHP Warning' not in errors and 'PHP Fatal' not in errors,'No PHP warnings/fatals')
        finally:
            server.terminate()
            try: server.wait(timeout=5)
            except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
print(f'TAMAM: {checks} web HTTP assertion (actual endpoints, synthetic PDO/auth; real SQL/LOGO değil).')
