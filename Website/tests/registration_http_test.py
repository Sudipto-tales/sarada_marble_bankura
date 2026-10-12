"""Real registration/verification UI against private disposable SQLite/test-mail storage."""
import http.client
from http.cookies import SimpleCookie
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode, urlparse, parse_qs

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
checks = 0

def check(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1

with tempfile.TemporaryDirectory(prefix='sarada-signup-ui-') as directory:
    temp = Path(directory)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, DB_TYPE='sqlite', DB_DATABASE=str(temp/'db.sqlite'),
               APP_ENV='development', APP_URL=f'http://127.0.0.1:{port}',
               PRIVATE_STORAGE_PATH=str(temp/'private'), AUTH_REGISTRATION_ENABLED='true',
               AUTH_VERIFICATION_TRANSPORT='test', MAIL_TRANSPORT='disabled')
    subprocess.run([PHP,'vayu','migrate'],cwd=ROOT,env=env,check=True,capture_output=True)
    cookies = {}
    def request(path, data=None):
        connection = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
        headers = {'Cookie':'; '.join(f'{key}={value}' for key,value in cookies.items())}
        if data is not None:
            headers['Content-Type']='application/x-www-form-urlencoded'
        try:
            connection.request('POST' if data is not None else 'GET', path, urlencode(data) if data is not None else None, headers)
            response = connection.getresponse()
            for key,value in response.getheaders():
                if key.lower()=='set-cookie':
                    jar=SimpleCookie(value)
                    for name,entry in jar.items(): cookies[name]=entry.value
            return response.status,dict(response.getheaders()),response.read().decode()
        finally:
            connection.close()
    with (temp/'server.log').open('w+') as log:
        process=subprocess.Popen([PHP,'vayu','run',f'--port={port}'],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),.1):break
                except OSError:time.sleep(.05)
            status,_,body=request('/account/register')
            check(status==200 and 'Create account' in body,'Enabled registration form renders.')
            csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
            fields={'csrf':csrf,'firstname':'UI','lastname':'Customer','email':'signup-ui@example.test','password':'Disposable-UI-Customer-Password','role':'admin'}
            check(request('/account/register',dict(fields,csrf='forged'))[0]==403,'Signup rejects forged CSRF.')
            check(request('/account/register',fields)[0]==200,'Signup uses real Auth and private test delivery.')
            check(request('/account',{'csrf':csrf,'email':fields['email'],'password':fields['password']})[0]==401,'Unverified customer cannot sign in.')
            messages=list((temp/'private'/'test-mail').glob('*.json'))
            check(len(messages)==1,'One verification message is staged privately.')
            proof=json.loads(messages[0].read_text())['verification_url']
            parsed=urlparse(proof)
            check(parsed.path=='/auth/verify_email' and len(parse_qs(parsed.query)['token'][0])==64,'Delivered link targets the real PHP verification route.')
            check(request(parsed.path+'?'+parsed.query)[0]==200,'Valid proof verifies customer.')
            check(request(parsed.path+'?'+parsed.query)[0]==422,'Consumed verification proof cannot be replayed.')
            _,_,body=request('/account')
            csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1]
            status,headers,_=request('/account',{'csrf':csrf,'email':fields['email'],'password':fields['password']})
            check(status==303 and headers['Location']=='/cart','Verified account signs in normally.')
            check('UI Customer' in request('/account')[2],'Account dashboard reflects the verified identity.')
            check(request('/admin/products')[0]==403,'Browser signup cannot grant an admin role.')
            check(request('/orders')[0]==200,'New verified customer can open empty owned order history.')
            check('PHP Warning' not in (temp/'server.log').read_text() and 'PHP Fatal' not in (temp/'server.log').read_text(),'Signup HTTP flow produces no PHP warnings/fatal errors.')
        finally:
            os.killpg(process.pid,signal.SIGTERM)
            process.wait(timeout=5)
print(f'Registration UI HTTP: {checks} checks passed (disposable SQLite/test transport).')
