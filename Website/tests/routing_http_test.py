"""Real HTTP checks for the Vayu launcher and new dispatcher. Uses temporary SQLite."""
import contextlib
import http.client
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("TEST_PHP_BINARY") or shutil.which("php")
if not PHP:
    raise SystemExit("PHP CLI with PDO SQLite is required (or set TEST_PHP_BINARY).")
checks = 0


def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1


@contextlib.contextmanager
def server(temp, enabled=True, fixture=None):
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, DB_TYPE="sqlite", DB_DATABASE=str(temp / "http.sqlite"),
               DEVELOPER_ENABLED="true" if enabled else "false")
    args = [PHP, "vayu", "run", f"--port={port}"] if fixture is None else [
        PHP, "-S", f"127.0.0.1:{port}", str(fixture)]
    with (temp / f"server-{port}.log").open("w+") as log:
        process = subprocess.Popen(args, cwd=ROOT, env=env, stdout=log, stderr=log,
                                   start_new_session=True)
        try:
            deadline = time.monotonic() + 8
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    log.seek(0)
                    raise AssertionError("Server startup failed: " + log.read())
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                        break
                except OSError:
                    time.sleep(0.05)
            else:
                raise AssertionError("Server readiness timeout.")
            yield port
        finally:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=5)


def request(port, path, method="GET"):
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
    try:
        connection.request(method, path)
        response = connection.getresponse()
        return response.status, dict(response.getheaders()), response.read().decode()
    finally:
        connection.close()


with tempfile.TemporaryDirectory(prefix="vayu-http-test-") as directory:
    temp = Path(directory)
    with server(temp) as port:
        status, headers, body = request(port, "/")
        check(status == 200 and "<!DOCTYPE" in body.upper(), "Welcome renders through actual bootstrap.")
        status, headers, body = request(port, "/developer")
        check(status == 303 and "/developer/login" in headers["Location"], "Anonymous Developer redirects.")
        status, headers, body = request(port, "/developer/login")
        check(status == 200 and "csrf" in body, "Developer login renders.")
        status, headers, body = request(port, "/developer/logout")
        check(status == 405 and headers["Allow"] == "POST", "Legacy Developer logout method guard.")
        status, headers, body = request(port, "/api/developer/metrics")
        check(status == 401 and "application/json" in headers["Content-Type"], "Legacy protected metrics API.")
        status, headers, body = request(port, "/not-found")
        check(status == 404 and "View" not in body, "HTML 404 renders without a missing-template error.")
        status, headers, body = request(port, "/api/v1/unknown")
        check(status == 404 and "application/json" in headers["Content-Type"]
              and '"not_found"' in body, "Unknown API returns structured JSON.")
        status, headers, body = request(port, "/index.php?route=developer/login")
        check(status == 200 and "csrf" in body, "Query-route mode works through HTTP.")
        status, headers, body = request(port, "/config/db.php")
        check(status == 404, "Existing development-router private-path denial preserved.")
        asset = next((ROOT / "assets").rglob("*.css"))
        status, headers, body = request(port, "/" + asset.relative_to(ROOT).as_posix())
        check(status == 200 and len(body) > 0, "Existing development-server asset serving preserved.")
    with server(temp, enabled=False) as port:
        check(request(port, "/developer/login")[0] == 404, "Disabled Developer HTML route absent.")
        status, headers, body = request(port, "/api/developer/metrics")
        check(status == 404 and '"not_found"' in body, "Disabled Developer API route absent.")

    # Trusted fixture routes exercise methods/typed parameters through the real bootstrap.
    (temp / "Probe.php").write_text(
        '<?php class HttpRouteProbe { public function show(int $id): void { echo "id:" . $id; }'
        ' public function change(): void { echo "changed"; } }\n')
    fixture = temp / "router.php"
    fixture.write_text("""<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
require %s;
ClassLoader::register(['HttpRouteProbe' => 'Probe.php'], __DIR__);
$routes += ['api/v1/probe/{id}' => ['GET' => ['HttpRouteProbe', 'show'], 'POST' => ['HttpRouteProbe', 'change']]];
$_SERVER['SCRIPT_NAME'] = '/shop/index.php';
RouteManager::dispatch($routes);
""" % repr(str(ROOT / "config/bootstarp.php")))
    with server(temp, fixture=fixture) as port:
        check(request(port, "/shop/api/v1/probe/7")[2] == "id:7", "Parameterized nested controller via HTTP.")
        check(request(port, "/shop/api/v1/probe/7", "POST")[2] == "changed", "POST method-map dispatch.")
        status, headers, body = request(port, "/shop/api/v1/probe/7", "DELETE")
        check(status == 405 and headers["Allow"] == "GET, POST"
              and "application/json" in headers["Content-Type"], "405 JSON and Allow header.")
        check(request(port, "/shopper/api/v1/probe/7")[0] == 404, "Subdirectory prefix collision remains unknown.")
        check(request(port, "/shop")[0] == 200, "Bare subdirectory root renders.")
        status, headers, body = request(port, "/shop/api/v1/probe/%2e%2e")
        check(status == 400 and '"invalid_path"' in body, "Unsafe API path returns structured 400.")
print(f"HTTP routing: {checks} checks passed.")
