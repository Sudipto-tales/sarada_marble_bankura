import json
from pathlib import Path
import subprocess
import tempfile
import unittest
import os
import tarfile
import shutil

import hostinger


class DeploymentConfigurationTest(unittest.TestCase):
    def settings(self):
        return {'APP_URL': 'https://example.com/', 'DB_DATABASE': 'db',
                'DB_USERNAME': 'user', 'DB_PASSWORD': 'not-a-live-password'}

    def test_missing_credentials_stop_before_connection(self):
        values = self.settings()
        del values['DB_PASSWORD']
        with self.assertRaisesRegex(ValueError, 'Missing production setting: DB_PASSWORD'):
            hostinger.configuration(values)

    def test_production_defaults_and_url_normalization(self):
        values, domain = hostinger.configuration(self.settings())
        self.assertEqual(domain, 'example.com')
        self.assertEqual(values['APP_URL'], 'https://example.com')
        self.assertEqual(values['APP_DEBUG'], 'false')
        self.assertEqual(values['CHECKOUT_COD_ENABLED'], 'false')
        self.assertNotIn('HOSTINGER_SSH_PRIVATE_KEY', values)

    def test_unsafe_urls_and_unverified_feature_activation_refused(self):
        for update in ({'APP_URL': 'http://example.com'}, {'APP_URL': 'https://example.com/path'},
                       {'APP_URL': 'https://user:pass@example.com'}, {'APP_DEBUG': 'true'},
                       {'APP_ENV': 'development'}, {'DB_TYPE': 'sqlite'},
                       {'CHECKOUT_COD_ENABLED': 'true'}, {'MAIL_TRANSPORT': 'smtp'}):
            with self.subTest(update=update), self.assertRaises(ValueError):
                hostinger.configuration(self.settings() | update)

    def test_dotenv_round_trip_preserves_secret_characters_without_expansion(self):
        values = {'PASSWORD': 'a"b\\c\'d # e ${HOME} $OTHER\nline\r\ttab',
                  'UNICODE': 'Maa Sarada — नमस्ते', 'EMPTY': ''}
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'settings'
            path.write_bytes(hostinger.dotenv(values))
            script = 'require $argv[1]; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[2])), JSON_THROW_ON_ERROR);'
            result = subprocess.check_output(['php', '-r', script,
                                               str(hostinger.ROOT / 'Website/vendor/autoload.php'), str(path)])
            self.assertEqual(json.loads(result), values)

    def test_null_bytes_rejected(self):
        with self.assertRaises(ValueError):
            hostinger.dotenv({'DB_PASSWORD': 'unsafe\0value'})

    def test_transfer_contains_committed_app_and_generated_config_only(self):
        values, _ = hostinger.configuration(self.settings())
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'payload.tar.gz'
            hostinger.archive(path, values, 'a' * 40, 1)
            with tarfile.open(path) as payload:
                names = payload.getnames()
                self.assertIn('site/index.php', names)
                self.assertIn('php-runtime.sh', names)
                self.assertIn('site/.htaccess', names)
                self.assertIn('site/database/migrations/0013_product_detail_features.php', names)
                self.assertEqual(names.count('site/.env'), 1)
                self.assertFalse(any(name.startswith(('site/vendor/', 'site/tests/', 'site/docs/')) for name in names))
                self.assertFalse(any(name.endswith(('.sqlite', '.db', '.log', '.sql')) for name in names))
                self.assertEqual(payload.extractfile('site/.env').read(), hostinger.dotenv(values))

    def test_php_fallback_is_shared_with_composer_and_migrations(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            old = root / 'old'
            compatible = root / 'compatible'
            for directory, code in ((old, 1), (compatible, 0)):
                directory.mkdir()
                (directory / 'php').write_text(f'#!/bin/sh\nexit {code}\n')
                (directory / 'php').chmod(0o700)
            helper = Path(hostinger.__file__).parent / 'php-runtime.sh'
            script = 'source "$1"; select_deploy_php "$2" "$3"; command -v php; /usr/bin/env php -r "exit(0);"'
            result = subprocess.run(['bash', '-e', '-c', script, 'runtime-test', str(helper),
                                     str(old / 'php'), str(compatible / 'php')],
                                    env=os.environ | {'PATH': str(old) + ':' + os.environ['PATH']},
                                    capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout.strip(), str(compatible / 'php'))
            failure = subprocess.run(['bash', '-e', '-c', script, 'runtime-test', str(helper),
                                      str(old / 'php'), str(root / 'missing/php')],
                                     capture_output=True, text=True)
            self.assertNotEqual(failure.returncode, 0)
            self.assertIn('no compatible deployment runtime found', failure.stderr)

    @unittest.skipUnless(shutil.which('rsync'), 'Successful publication check requires rsync')
    def test_publication_permissions_preserve_private_config_and_uploads(self):
        with tempfile.TemporaryDirectory() as temp:
            home = Path(temp)
            web = home / 'domains/example.com/public_html'
            (web / 'assets/products').mkdir(parents=True)
            (web / 'assets/products/existing.webp').write_text('customer-upload')
            work = home / '.sarada-deploy/incoming.test'
            site = work / 'site'
            (site / 'assets/products').mkdir(parents=True)
            (site / 'assets/storefront').mkdir()
            for relative, content in {'.env':'DB_TYPE=mysql\n', '.htaccess':'new-rules',
                                      'index.php':'new-site', 'deployment.json':'{}',
                                      'assets/storefront/style.css':'body{}',
                                      'assets/products/existing.webp':'bundled-image',
                                      'assets/products/new.webp':'new-image'}.items():
                path = site / relative
                path.write_text(content)
                path.chmod(0o600)
            tools = home / 'bin'
            tools.mkdir()
            for name, script in {
                'php': '#!/bin/bash\nif [[ "$1" == *database-backup.php ]]; then echo fixture_db; fi\nexit 0\n',
                'composer':'#!/bin/bash\nexit 0\n',
                'mysqldump':'#!/bin/bash\necho snapshot\n',
                'curl':'#!/bin/bash\necho 503\n',
            }.items():
                (tools / name).write_text(script)
                (tools / name).chmod(0o700)
            result = subprocess.run(['bash', str(Path(hostinger.__file__).parent / 'remote.sh'),
                                     'example.com', 'a' * 40, '1', str(work)],
                                    env=os.environ | {'HOME':str(home), 'PATH':str(tools)+':'+os.environ['PATH']},
                                    capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            for relative in ('index.php', '.htaccess', 'deployment.json',
                             'assets/storefront/style.css', 'assets/products/new.webp'):
                self.assertEqual((web / relative).stat().st_mode & 0o777, 0o644, relative)
            self.assertEqual((web / 'assets/storefront').stat().st_mode & 0o777, 0o755)
            self.assertEqual((web / '.env').stat().st_mode & 0o777, 0o600)
            self.assertEqual((web / 'assets/products/existing.webp').read_text(), 'customer-upload')
            backup = next((home / '.sarada-deploy/example.com/backups').iterdir())
            self.assertEqual((backup / 'database.sql').stat().st_mode & 0o777, 0o600)
            self.assertFalse((site / '.env').exists())

    def test_server_failures_keep_maintenance_and_do_not_publish(self):
        for failure in ('migration', 'backup', 'maintenance'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                home = Path(temp)
                web = home / 'domains/example.com/public_html'
                web.mkdir(parents=True)
                (web / 'index.php').write_text('existing-site')
                (web / '.htaccess').write_text('existing-rules')
                work = home / '.sarada-deploy/incoming.test'
                site = work / 'site'
                site.mkdir(parents=True)
                (site / '.env').write_text('DB_TYPE=mysql\n')
                (site / '.htaccess').write_text('new-rules')
                tools = home / 'bin'
                tools.mkdir()
                scripts = {
                    'php': '''#!/bin/bash
if [[ "$1" == *database-backup.php ]]; then echo disposable_db; exit 0; fi
if [[ "$1" == vayu ]]; then
  touch "$HOME/migration-attempted"
  [[ "$FAILURE" != migration ]] || exit 1
fi
exit 0
''',
                    'composer': '#!/bin/bash\nexit 0\n',
                    'rsync': '#!/bin/bash\ntouch "$HOME/publish-attempted"\n',
                    'mysqldump': '#!/bin/bash\n[[ "$FAILURE" != backup ]] || exit 1\necho snapshot\n',
                    'curl': '#!/bin/bash\n[[ $(stat -c %a "$HOME/domains/example.com/public_html/.htaccess") == 644 ]] || { echo 403; exit 0; }\nif [[ "$FAILURE" == maintenance ]]; then echo 200; else echo 503; fi\n',
                }
                for name, script in scripts.items():
                    (tools / name).write_text(script)
                    (tools / name).chmod(0o700)
                env = os.environ | {'HOME': str(home), 'PATH': str(tools) + ':' + os.environ['PATH'], 'FAILURE': failure}
                result = subprocess.run(['bash', str(Path(hostinger.__file__).parent / 'remote.sh'),
                                         'example.com', 'a' * 40, '1', str(work)],
                                        env=env, capture_output=True, text=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual((web / 'index.php').read_text(), 'existing-site')
                self.assertIn('R=503', (web / '.htaccess').read_text())
                self.assertFalse((home / 'publish-attempted').exists())
                self.assertEqual((home / 'migration-attempted').exists(), failure == 'migration')
                self.assertTrue(list((home / '.sarada-deploy/example.com/backups').glob('*/website.tar.gz')))
                self.assertFalse((site / '.env').exists())


if __name__ == '__main__':
    unittest.main()
