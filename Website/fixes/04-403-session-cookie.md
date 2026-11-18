# 04: 403 "Invalid session token" / session not saved in the browser

## How the session works
- `RequestSecurity::session()` (`Website/core/RequestSecurity.php`) starts the PHP session. The session files go in `PrivateStorage::directory('sessions')`, which is `PRIVATE_STORAGE_PATH`, by default `<repo>/var/sessions`, **outside** `Website/`.
- Cookie: `PHPSESSID`, `path=/`, `HttpOnly`, `SameSite=Lax`, and **`Secure` = `secureCookies()`**.
- The login form carries `csrf = $_SESSION['commerce_csrf']`. The POST must bring the **same session cookie** back, or `requireMutation()` returns **403 invalid_csrf**.
- After a good login, `session_regenerate_id(true)` issues a new cookie and the browser must store it. `admin_id` and `admin_seen` expire after 30 minutes idle.

## Root cause 1: the `Secure` cookie on plain HTTP (most likely 403)
```php
if (filter_var(env('SESSION_SECURE_COOKIE', false), …) || env('APP_ENV', 'production') === 'production') return true;
```
`APP_ENV` defaults to **production**, so the cookie is always `Secure`. Over `http://` (a LAN IP, `127.0.0.1` in some browsers, or a Hostinger domain without SSL or with HTTP not redirected) the browser **drops** the cookie. Each request then starts a new session, the CSRF token never matches, and you get **403**.
Behind Hostinger's proxy, `$_SERVER['HTTPS']` can be empty. That doesn't break the cookie (it's forced Secure anyway), but it does produce `http://` URLs.

### Fix
- **Local:** set `APP_ENV=local` in `Website/.env`, and use `http://localhost:8000`. Don't use the IP.
- **Production:** force HTTPS. Add this at the top of `Website/.htaccess`, after `RewriteEngine On`:
  ```apache
  RewriteCond %{HTTPS} off
  RewriteCond %{HTTP:X-Forwarded-Proto} !https
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  ```
  Set `APP_URL=https://your-domain`. If you're behind a proxy or CDN, set `TRUSTED_PROXIES` to the proxy IP(s).
- Open **one** host only: always `www.` or always bare. The cookie is host-only (`cookie_domain=''`), so a session set on `example.com` doesn't exist on `www.example.com`. Add a canonical redirect:
  ```apache
  RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
  RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]
  ```

## Root cause 2: the session directory is not writable (gives 503, not 403)
`PrivateStorage::create()` requires `chmod 0700` to succeed and the directory to be writable. If PHP runs as a different user than the deploy user, or the path is inside `public_html`, you get `RuntimeException('Private storage is unavailable.' / 'must be outside the document root')` → **503**.
Fix over SSH:
```bash
mkdir -p ~/sarada-private/sessions && chmod 700 ~/sarada-private ~/sarada-private/sessions
# .env
PRIVATE_STORAGE_PATH=/home/<user>/sarada-private
```

## Root cause 3: output before the session starts
`session()` throws "Session must start before output" if anything has already been echoed. Look for a BOM or whitespace at the top of PHP files, and `?>` followed by blank lines (`config/bootstarp.php`, `config/config.php` and `config/framework.php` all end with `?>`). Remove the closing `?>` from those three files.

## Check in the browser
DevTools → Application → Cookies:
1. GET `/admin/login` sets `PHPSESSID`. If it's missing or shows a ⚠ "Secure" warning, that's root cause 1.
2. In the Network tab, the POST `/admin/login` request has a `Cookie: PHPSESSID=…` header **with the same value**.
3. The response is 303 with a **new** `Set-Cookie: PHPSESSID`, and the next GET `/admin` sends that new value.

curl check (any host):
```bash
curl -s -c j.txt https://HOST/admin/login | grep -o 'name="csrf" value="[^"]*"'
curl -s -b j.txt -c j.txt -o /dev/null -w '%{http_code}\n' -d "csrf=TOKEN&email=E&password=P" https://HOST/admin/login   # expect 303
curl -s -b j.txt -o /dev/null -w '%{http_code}\n' https://HOST/admin                                                     # expect 200
rm j.txt   # never commit cookie jars (see 06)
```

## Test
The curl sequence returns `303` then `200`, and the browser login works twice in a row.

## Done when
No 403 on login and the session persists. **STOP. Report and ask for "next".**
