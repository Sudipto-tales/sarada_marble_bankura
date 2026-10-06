"""Disposable HTTP sessions, CSRF, remember replay, JSON/errors and private-file checks.

Set TEST_PHP_BINARY if PHP isn't on PATH. Optional Apache .htaccess checks require
TEST_APACHE_BINARY, TEST_APACHE_MODULES and TEST_APACHE_PHP_MODULE.
"""
import contextlib
import http.client
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('TEST_PHP_BINARY') or shutil.which('php')
if not PHP:
    raise SystemExit('PHP with PDO SQLite required (or set TEST_PHP_BINARY).')
checks = 0


def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1


class Browser:
    def __init__(self, port):
        self.port, self.cookies = port, {}

    def request(self, path, method='GET', fields=None, body=None, headers=None):
        connection = http.client.HTTPConnection('127.0.0.1', self.port, timeout=5)
        headers = dict(headers or {})
        headers['Cookie'] = '; '.join(f'{k}={v}' for k, v in self.cookies.items())
        if fields is not None:
            body = urlencode(fields)
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        try:
            connection.request(method, path, body=body, headers=headers)
            response = connection.getresponse()
            pairs = response.getheaders()
            for key, value in pairs:
                if key.lower() == 'set-cookie':
                    name, cookie = value.split(';', 1)[0].split('=', 1)
                    if cookie in ('deleted', ''):
                        self.cookies.pop(name, None)
                    else:
                        self.cookies[name] = cookie
            return response.status, pairs, response.read().decode()
        finally:
            connection.close()


@contextlib.contextmanager
def server(site, temp, env, apache=False, production=False):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = dict(env, APP_ENV='production' if production else 'development')
    if apache:
        modules = Path(os.environ['TEST_APACHE_MODULES'])
        load = '\n'.join(f'LoadModule {name}_module "{modules}/mod_{name}.so"'
                         for name in ['mpm_prefork', 'authz_core', 'authz_host', 'dir', 'mime', 'rewrite', 'env', 'alias'])
        config = temp / f'apache-{port}.conf'
        config.write_text(f'''ServerRoot "{temp}"
ServerName localhost
Listen 127.0.0.1:{port}
PidFile "{temp}/apache-{port}.pid"
DefaultRuntimeDir "{temp}"
ErrorLog "{temp}/apache-{port}.error.log"
{load}
LoadModule php_module "{os.environ['TEST_APACHE_PHP_MODULE']}"
TypesConfig /etc/mime.types
DocumentRoot "{site}"
Alias /shop/ "{site}/"
DirectoryIndex index.php
<Directory "{site}">
    Require all granted
    AllowOverride All
</Directory>
<FilesMatch "\\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
StartServers 1
MinSpareServers 1
MaxSpareServers 2
MaxRequestWorkers 4
''')
        args = [os.environ['TEST_APACHE_BINARY'], '-f', str(config), '-DFOREGROUND']
    else:
        args = [PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site / 'vayu')]
    with (temp / f'server-{port}.log').open('w+') as log:
        process = subprocess.Popen(args, env=env, cwd=site, stdout=log, stderr=log, start_new_session=True)
        try:
            deadline = time.monotonic() + 8
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    log.seek(0)
                    raise AssertionError('Server startup failed: ' + log.read())
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise AssertionError('Server readiness timeout.')
            yield port
        finally:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=5)


with tempfile.TemporaryDirectory(prefix='vayu-auth-http-') as directory:
    temp = Path(directory)
    site = temp / 'site'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', '.env', '*.sqlite', '*.log', '__pycache__'))
    env = dict(os.environ, DB_TYPE='sqlite', DB_DATABASE=str(temp / 'http.sqlite'),
               PRIVATE_STORAGE_PATH=str(temp / 'private'), DEVELOPER_ENABLED='true',
               SESSION_SECURE_COOKIE='false', TRUSTED_PROXIES='', MAIL_TRANSPORT='disabled')
    subprocess.run([PHP, 'vayu', 'migrate'], cwd=site, env=env, check=True, capture_output=True)
    seed = temp / 'seed.php'
    seed.write_text('''<?php
require %s;
$pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, email_verify) VALUES (?, ?, ?, ?, ?, 1)")
    ->execute(['HTTP', 'User', 'HTTP User', 'http@example.test', password_hash('Disposable-HTTP-Password', PASSWORD_DEFAULT)]);
''' % repr(str(site / 'config/runtime.php')))
    subprocess.run([PHP, str(seed)], cwd=site, env=env, check=True, capture_output=True)
    (site / 'app/bridge/AuthHttpProbe.php').write_text('''<?php
class AuthHttpProbe {
    public function session(): void {
        header('Content-Type: application/json');
        $authenticated = Auth::isAuthenticated();
        echo json_encode(['csrf' => RequestSecurity::csrfToken(), 'id' => session_id(), 'authenticated' => $authenticated]);
    }
    public function login(): void {
        $result = Auth::login($_POST['email'] ?? '', $_POST['password'] ?? '', ($_POST['remember'] ?? '') === '1');
        header('Content-Type: application/json'); echo json_encode($result);
    }
    public function logout(): void { Auth::logout(); echo 'logged out'; }
    public function mutation(): void {
        RequestSecurity::requireMutation();
        if (!Auth::isAuthenticated()) throw new RequestRejected(401, 'unauthenticated', 'Authentication required.');
        echo 'changed';
    }
    public function json(): void { echo json_encode(RequestSecurity::jsonBody()); }
    public function error(): void { throw new RuntimeException('must-not-leak-SQL-provider-detail'); }
}
''')
    classes = site / 'config/classes.php'
    classes.write_text(classes.read_text().replace('return [', "return ['AuthHttpProbe' => 'app/bridge/AuthHttpProbe.php',", 1))
    (site / 'app/view.php').write_text('''<?php return [
    'probe/session' => ['GET' => ['AuthHttpProbe', 'session']],
    'probe/login' => ['POST' => ['AuthHttpProbe', 'login']],
    'probe/logout' => ['POST' => ['AuthHttpProbe', 'logout']],
    'probe/mutation' => ['POST' => ['AuthHttpProbe', 'mutation']],
    'api/probe/json' => ['POST' => ['AuthHttpProbe', 'json']],
    'api/probe/error' => ['GET' => ['AuthHttpProbe', 'error']],
];''')
    private_files = ['.env', 'config/probe.txt', 'vendor/probe.txt', 'database/probe.sqlite',
                     'logs/probe.log', 'cache/probe.txt', 'imports/probe.csv', 'storage/probe.txt',
                     'tests/probe.txt', 'app/probe.txt', 'assets/.private.txt', 'assets/.private.css', 'assets/uploads/probe.php']
    for name in private_files:
        path = site / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php echo 'private-file-must-not-leak';" if name.endswith('.php') else 'private-file-must-not-leak')
    # The fixture .env is only a denial probe; no Composer loader is copied.
    transports = [False, True] if os.environ.get('TEST_APACHE_BINARY') else [False]
    for apache in transports:
        with server(site, temp, env, apache=apache) as port:
            browser = Browser(port)
            browser.cookies['PHPSESSID'] = 'attacker-selected-session-id'
            status, headers, body = browser.request('/probe/session')
            check(status == 200, 'Session endpoint works through front controller.')
            session = json.loads(body)
            check(session['id'] != 'attacker-selected-session-id', 'Strict session rejects attacker cookie ID.')
            check(any('httponly' in v.lower() and 'samesite=lax' in v.lower() for k, v in headers if k.lower() == 'set-cookie'), 'HTTP session cookie carries protections.')
            credentials = {'email': 'http@example.test', 'password': 'Disposable-HTTP-Password'}
            check(browser.request('/probe/login', 'POST', fields=credentials)[0] == 403, 'Missing CSRF denied.')
            status, headers, body = browser.request('/probe/login', 'POST', fields=dict(credentials, csrf=session['csrf'], remember='1'))
            check(status == 200 and json.loads(body)['status'], 'Valid credentials and CSRF sign in.')
            check(browser.cookies['PHPSESSID'] != session['id'], 'Login changes session cookie.')
            check(any('httponly' in v.lower() and 'samesite=lax' in v.lower() for k, v in headers if k.lower() == 'set-cookie' and v.startswith('remember_me=')), 'Remember cookie is protected.')
            stale = browser.cookies['remember_me']
            current = json.loads(browser.request('/probe/session')[2])
            check(current['authenticated'], 'Signed-in session resolves.')
            check(browser.request('/probe/mutation', 'POST', fields={'csrf': session['csrf']})[0] == 403, 'Pre-login CSRF rejected after login.')
            check(browser.request('/probe/mutation', 'POST', headers={'X-CSRF-Token': current['csrf']})[0] == 200, 'CSRF header accepted.')
            anonymous = Browser(port)
            anon = json.loads(anonymous.request('/probe/session')[2])
            check(anonymous.request('/probe/mutation', 'POST', fields={'csrf': anon['csrf']})[0] == 401, 'Unauthenticated mutation denied with valid CSRF.')
            check(anonymous.request('/probe/mutation', 'POST', fields={'csrf': current['csrf']})[0] == 403, 'Cross-session CSRF denied.')
            remembered = Browser(port)
            remembered.cookies['remember_me'] = stale
            restored = json.loads(remembered.request('/probe/session')[2])
            check(restored['authenticated'] and remembered.cookies['remember_me'] != stale, 'Remember-only session restores and rotates.')
            replay = Browser(port)
            replay.cookies['remember_me'] = stale
            check(not json.loads(replay.request('/probe/session')[2])['authenticated'], 'Remember replay denied.')
            check(remembered.request('/probe/logout', 'POST', fields={'csrf': restored['csrf']})[0] == 200, 'Logout with current CSRF works.')
            check(not json.loads(remembered.request('/probe/session')[2])['authenticated'], 'Logout removes commerce identity.')
            for name in private_files:
                status, headers, body = browser.request('/' + name)
                check(status in (403, 404) and 'private-file-must-not-leak' not in body, f'Private file denied: {name}')
            status, headers, body = browser.request('/api/probe/error')
            check(status == 503 and 'must-not-leak' not in body and json.loads(body)['error']['code'] == 'service_unavailable', 'Infrastructure errors sanitized.')
            json_headers = {'Content-Type': 'application/json'}
            status, headers, body = browser.request('/api/probe/json', 'POST', body='{"name":"Stone"}', headers=json_headers)
            check(status == 200 and json.loads(body) == {'name': 'Stone'}, 'HTTP JSON object parsing.')
            check(browser.request('/api/probe/json', 'POST', body='[]', headers=json_headers)[0] == 400, 'JSON arrays denied.')
            check(browser.request('/api/probe/json', 'POST', body='x' * 20000, headers=json_headers)[0] == 413, 'Oversized body denied.')
            check(browser.request('/api/probe/json', 'POST', body='{}')[0] == 415, 'Content type enforced.')
            if apache:
                status, headers, body = Browser(port).request('/shop/probe/session')
                check(status == 200 and not json.loads(body)['authenticated'], 'Apache subdirectory rewrite works without root RewriteBase.')
                for name in private_files:
                    status, headers, body = browser.request('/shop/' + name)
                    check(status in (403, 404) and 'private-file-must-not-leak' not in body, f'Subdirectory private file denied: {name}')
        with server(site, temp, env, apache=apache, production=True) as port:
            status, headers, body = Browser(port).request('/probe/session')
            check(status == 200 and any('secure' in v.lower() for k, v in headers if k.lower() == 'set-cookie'), 'Production cookie uses Secure.')
print(f"Auth HTTP: {checks} checks passed ({'PHP and Apache' if len(transports) == 2 else 'PHP server'}).")
