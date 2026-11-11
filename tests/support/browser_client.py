import json,time,urllib.request,base64
import websocket
class Browser:
 def __init__(self):
  tabs=json.load(urllib.request.urlopen('http://127.0.0.1:9222/json/list'))
  self.ws=websocket.create_connection(next(t['webSocketDebuggerUrl'] for t in tabs if t['type']=='page'),suppress_origin=True)
  self.n=0;self.errors=[]
  self.call('Runtime.enable');self.call('Page.enable')
 def call(self,method,params=None):
  self.n+=1;n=self.n;self.ws.send(json.dumps({'id':n,'method':method,'params':params or {}}))
  while True:
   r=json.loads(self.ws.recv())
   if r.get('method')=='Runtime.exceptionThrown':self.errors.append(r['params'])
   if r.get('id')==n:
    if 'error'in r:raise RuntimeError(r['error'])
    return r.get('result',{})
 def js(self,s):
  r=self.call('Runtime.evaluate',{'expression':s,'awaitPromise':True,'returnByValue':True})
  if 'exceptionDetails'in r:raise RuntimeError(r['exceptionDetails'])
  return r.get('result',{}).get('value')
 def go(self,url):self.call('Page.navigate',{'url':url});time.sleep(.4)
 def wait(self,s):
  for _ in range(100):
   if self.js(s):return
   time.sleep(.1)
  raise AssertionError('timeout '+s+' '+str(self.js('document.body.innerText.slice(-2500)')))
 def shot(self,path):
  data=self.call('Page.captureScreenshot',{'format':'png','captureBeyondViewport':False})['data'];open(path,'wb').write(base64.b64decode(data))
