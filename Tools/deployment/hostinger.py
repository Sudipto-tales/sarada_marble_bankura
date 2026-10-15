#!/usr/bin/env python3
"""Deploy committed Website sources over pinned SSH; never print application secrets."""
import io
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
import tarfile
import tempfile
from urllib.parse import urlsplit
from urllib.request import Request, urlopen
from urllib.error import HTTPError


ROOT = Path(__file__).resolve().parents[2]
DEFAULTS = {
    'APP_NAME': 'Maa Sarada', 'APP_ENV': 'production', 'APP_DEBUG': 'false',
    'DEVELOPER_ENABLED': 'true', 'DB_TYPE': 'mysql', 'DB_HOST': 'localhost',
    'DB_PORT': '3306', 'SESSION_SECURE_COOKIE': 'true', 'TRUSTED_PROXIES': '',
    'AUTH_REGISTRATION_ENABLED': 'false', 'AUTH_VERIFICATION_TRANSPORT': 'disabled',
    'MAIL_TRANSPORT': 'disabled', 'MAIL_HOST': '', 'MAIL_PORT': '587',
    'MAIL_ENCRYPTION': 'tls', 'MAIL_USERNAME': '', 'MAIL_PASSWORD': '',
    'MAIL_FROM_ADDRESS': '', 'MAIL_FROM_NAME': 'Maa Sarada', 'MAIL_TIMEOUT_SECONDS': '10',
    'QUEUE_PAYLOAD_LIMIT': '16384', 'CACHE_DRIVER': 'file',
    'CHECKOUT_SIMULATION_ENABLED': 'false', 'CHECKOUT_COD_ENABLED': 'false',
    'CHECKOUT_SHIPPING_MINOR': '0', 'CHECKOUT_TAX_BPS': '0',
}
REQUIRED = ('APP_URL', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')


def configuration(environ):
    values = {name: environ.get(name) or default for name, default in DEFAULTS.items()}
    for name in REQUIRED:
        if not environ.get(name):
            raise ValueError(f'Missing production setting: {name}')
        values[name] = environ[name]
    if values['APP_ENV'] != 'production' or values['APP_DEBUG'] != 'false':
        raise ValueError('Deployment requires APP_ENV=production and APP_DEBUG=false.')
    if values['DB_TYPE'] != 'mysql' or values['SESSION_SECURE_COOKIE'] != 'true':
        raise ValueError('Production requires MySQL and secure cookies.')
    url = urlsplit(values['APP_URL'])
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
        raise ValueError('APP_URL must be an HTTPS domain root.')
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', url.hostname):
        raise ValueError('Invalid domain.')
    # Feature activation needs a separate policy/cron acceptance, not an accidental CI toggle.
    for name in ('AUTH_REGISTRATION_ENABLED', 'CHECKOUT_COD_ENABLED', 'CHECKOUT_SIMULATION_ENABLED'):
        if values[name] != 'false':
            raise ValueError(f'{name} must remain false until production acceptance.')
    if values['MAIL_TRANSPORT'] != 'disabled':
        raise ValueError('Email delivery needs verified SMTP and cron before activation.')
    values['APP_URL'] = values['APP_URL'].rstrip('/')
    return values, url.hostname


def dotenv(values):
    lines = []
    for name, value in values.items():
        if not re.fullmatch(r'[A-Z][A-Z0-9_]*', name) or '\0' in value:
            raise ValueError('Invalid dotenv key/value.')
        escaped = value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$')
        escaped = escaped.replace('\r', '\\r').replace('\n', '\\n').replace('\t', '\\t')
        lines.append(f'{name}="{escaped}"\n')
    return ''.join(lines).encode()


def archive(destination, values, commit, run_number):
    source = ROOT / 'Website'
    with tarfile.open(destination, 'w:gz') as bundle:
        # Git's index is the allowlist: local database, vendor, logs, and credentials never ship.
        tracked = subprocess.check_output(['git', 'ls-files', '-z', '--', 'Website'], cwd=ROOT).decode().split('\0')
        for filename in sorted(filter(None, tracked)):
            relative = Path(filename).relative_to('Website')
            if relative.parts[0] in ('vendor', 'tests', 'docs', 'logs', 'cache', 'storage', 'var'):
                continue
            if relative.name.startswith('.env') or relative.suffix in ('.sqlite', '.db', '.log', '.sql', '.bak'):
                continue
            path = source / relative
            if path.is_symlink() or not path.is_file():
                raise ValueError(f'Unsupported tracked website file: {relative}')
            bundle.add(path, arcname='site/' + relative.as_posix(), recursive=False)
        files = {
            'site/.env': dotenv(values),
            'site/deployment.json': json.dumps({'commit': commit, 'run_number': run_number}).encode(),
        }
        for filename in ('remote.sh', 'php-runtime.sh', 'database-backup.php'):
            files[filename] = (Path(__file__).parent / filename).read_bytes()
        for name, content in files.items():
            info = tarfile.TarInfo(name)
            info.size = len(content)
            info.mode = 0o600
            bundle.addfile(info, io.BytesIO(content))


def request_status(url):
    try:
        with urlopen(Request(url, headers={'Cache-Control': 'no-cache'}), timeout=30) as response:
            return response.status, response.read(1048576)
    except HTTPError as error:
        return error.code, b''


def main():
    values, domain = configuration(os.environ)
    host = os.environ.get('HOSTINGER_HOST', '')
    user = os.environ.get('HOSTINGER_USER', '')
    port = os.environ.get('HOSTINGER_PORT', '')
    trust = os.environ.get('HOSTINGER_KNOWN_HOSTS', '').strip()
    key = os.environ.get('HOSTINGER_SSH_PRIVATE_KEY', '')
    commit = os.environ.get('GITHUB_SHA', '')
    run_number = os.environ.get('GITHUB_RUN_NUMBER', '')
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', host) or not re.fullmatch(r'[a-zA-Z0-9_-]+', user):
        raise ValueError('Missing/invalid SSH host or user.')
    if not port.isdigit() or not 1 <= int(port) <= 65535:
        raise ValueError('Missing/invalid SSH port.')
    if not re.fullmatch(r'[a-f0-9]{40}', commit) or not run_number.isdigit():
        raise ValueError('Invalid deployment commit/run identity.')
    if not trust:
        raise ValueError('Missing HOSTINGER_KNOWN_HOSTS. Add the verified SSH server key to production Environment variables; deployment will not bypass server verification.')
    if 'PRIVATE KEY' not in key:
        raise ValueError('Missing SSH private key.')
    with tempfile.TemporaryDirectory(prefix='sarada-deploy-') as temp:
        temp = Path(temp)
        for name, contents in (('key', key.rstrip() + '\n'), ('known_hosts', trust + '\n')):
            path = temp / name
            path.write_text(contents)
            path.chmod(0o600)
        payload = temp / 'payload.tar.gz'
        archive(payload, values, commit, int(run_number))
        ssh = ['ssh', '-i', str(temp / 'key'), '-p', port,
               '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
               '-o', 'StrictHostKeyChecking=yes', '-o', f'UserKnownHostsFile={temp / "known_hosts"}',
               '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4', f'{user}@{host}']
        # A bounded bootstrap discovers the actual hosting HOME and exact domain path.
        command = "umask 077; test -d \"$HOME/domains/" + domain + "/public_html\" || { echo 'Domain public_html not found; confirm destination.' >&2; exit 1; }; mkdir -p \"$HOME/.sarada-deploy\"; work=$(mktemp -d \"$HOME/.sarada-deploy/incoming.XXXXXXXX\"); cat > \"$work/payload.tar.gz\"; tar -xzf \"$work/payload.tar.gz\" -C \"$work\"; bash \"$work/remote.sh\" " + shlex.join([domain, commit, run_number]) + ' "$work"'
        with payload.open('rb') as stream:
            subprocess.run(ssh + [command], stdin=stream, check=True, timeout=900)
        base = values['APP_URL']
        for path in ('/', '/shop', '/admin/login'):
            status, _ = request_status(base + path)
            if status != 200:
                raise ValueError(f'Post-deployment check failed for {path}: HTTP {status}. Inspect server; database is not automatically rolled back.')
        for path in ('/.env', '/composer.json', '/database/', '/config/runtime.php'):
            status, _ = request_status(base + path)
            if status not in (403, 404):
                raise ValueError(f'Private-file protection failed for {path}: HTTP {status}.')
        status, body = request_status(base + '/deployment.json?commit=' + commit)
        if status != 200 or json.loads(body).get('commit') != commit:
            raise ValueError('Live site did not report the deployed commit. Check DNS/cache/document root.')
        print(f'Verified deployment of {commit} to {base}.')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, subprocess.SubprocessError, OSError) as error:
        # Exceptions from dotenv/PDO are never used here; values are never logged.
        print(f'Deployment stopped: {error}', file=sys.stderr)
        sys.exit(1)
