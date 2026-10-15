#!/usr/bin/env bash
# Executed on shared hosting. In-place publication uses an explicit maintenance gate.
set -euo pipefail
umask 077
domain=$1
commit=$2
run_number=$3
work=$4
[[ "$domain" =~ ^[a-zA-Z0-9.-]+$ && "$commit" =~ ^[a-f0-9]{40}$ && "$run_number" =~ ^[0-9]+$ ]]
[[ "$HOME" =~ ^/[a-zA-Z0-9_./-]+$ ]]
[[ "$work" == "$HOME"/.sarada-deploy/incoming.* && -d "$work/site" ]]
web=$(realpath "$HOME/domains/$domain/public_html")
account=$(realpath "$HOME")
[[ "$web" == "$account"/* && "$web" != "$account" && -d "$web" ]]
state="$HOME/.sarada-deploy/$domain"
private="$HOME/.sarada-runtime/$domain"
mkdir -p "$state/backups" "$private"
chmod 700 "$HOME/.sarada-deploy" "$state" "$state/backups" "$HOME/.sarada-runtime" "$private"
exec 9>"$state/deploy.lock"
flock -w 60 9 || { echo 'Another deployment holds the server lock.' >&2; exit 1; }
if [[ -f "$state/run-number" ]] && (( run_number < $(cat "$state/run-number") )); then
    echo 'Refusing an older workflow run over a newer deployment.' >&2
    exit 1
fi
source "$(dirname "$0")/php-runtime.sh"
select_deploy_php "$(command -v php || true)" /opt/alt/php83/usr/bin/php /opt/alt/php84/usr/bin/php /usr/bin/php8.3
php -r 'echo "Deployment CLI: PHP ",PHP_VERSION,"\n";'
for tool in php composer rsync tar mysqldump curl; do
    command -v "$tool" >/dev/null || { echo "Required server command unavailable: $tool" >&2; exit 1; }
done
php -r 'if (PHP_VERSION_ID < 80300 || PHP_INT_SIZE !== 8) {fwrite(STDERR,"PHP 8.3+ 64-bit CLI required.\n");exit(1);} foreach (["pdo_mysql","mbstring","fileinfo","openssl"] as $e) if (!extension_loaded($e)) {fwrite(STDERR,"Required PHP extension missing: $e\n");exit(1);}'
cd "$work/site"
printf '\nPRIVATE_STORAGE_PATH="%s"\n' "$private" >> .env
chmod 600 .env
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php -r 'try {require "config/bootstarp.php"; PrivateStorage::directory("deploy-check"); echo "Database connection and private storage verified.\n";} catch (Throwable $e) {fwrite(STDERR,"Database connection/private storage preflight failed. Check server settings.\n");exit(1);}'
# Existing hPanel Git checkout could independently overwrite the site; stop until disconnected.
[[ ! -e "$web/.git" ]] || { echo 'Disconnect Hostinger Git deployment and move its .git metadata out of public_html before using Actions.' >&2; exit 1; }
[[ ! -L "$web/assets" && ! -L "$web/assets/products" ]] || { echo 'Existing upload symlinks need an explicit migration plan.' >&2; exit 1; }
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup="$state/backups/$stamp-$run_number"
mkdir -m 700 "$backup"
tar -czf "$backup/website.tar.gz" -C "$web" .
database=$(php "$work/database-backup.php" "$work/site" "$work/database.cnf")
gate=0
published=0
cleanup() {
    result=$?
    # Do not expose a partially published app if a migration/copy fails.
    if (( result != 0 && gate == 1 )); then
        echo "Deployment failed; site remains in maintenance. Private backup: $backup. MySQL DDL may be partially applied; inspect before recovery." >&2
    fi
    rm -f "$work/database.cnf" "$work/payload.tar.gz" "$work/site/.env"
    exit "$result"
}
trap cleanup EXIT
if [[ -f "$web/.htaccess" ]]; then
    cp "$web/.htaccess" "$backup/original.htaccess"
fi
cat > "$web/.htaccess.deploy" <<'HTACCESS'
Options -Indexes
RewriteEngine On
RewriteRule ^ - [R=503,L]
ErrorDocument 503 "Deployment in progress. Please retry shortly."
HTACCESS
# Apache/LiteSpeed must read the maintenance rules despite the private staging umask.
chmod 644 "$web/.htaccess.deploy"
mv "$web/.htaccess.deploy" "$web/.htaccess"
gate=1
status=$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 30 "https://$domain/?deploy-maintenance=$run_number")
[[ "$status" == 503 ]] || { echo 'Maintenance gate was not observed over HTTPS; stopping before database changes.' >&2; exit 1; }
# Stop if a database snapshot cannot be taken. No credentials appear in argv or logs.
mysqldump --defaults-extra-file="$work/database.cnf" --single-transaction --quick --skip-lock-tables --no-tablespaces --hex-blob "$database" > "$backup/database.sql"
test -s "$backup/database.sql"
chmod 600 "$backup/database.sql" "$backup/website.tar.gz"
php vayu migrate
# Preserve runtime product images, then copy only new bundled images without replacing uploads.
mkdir -p "$web/assets/products"
if [[ -d assets/products ]]; then
    rsync -r --ignore-existing --chmod=D755,F644 assets/products/ "$web/assets/products/"
fi
# No --delete: unrelated hosting files/uploads are not removed. PHP/internal file access
# remains gated until the committed .htaccess is installed as the final publication step.
rsync -r --chmod=D755,F644 --exclude=/.env --exclude=/.htaccess --exclude=/assets/products/ ./ "$web/"
install -m 600 .env "$web/.env.next"
mv "$web/.env.next" "$web/.env"
cp .htaccess "$web/.htaccess.next"
chmod 644 "$web/.htaccess.next"
mv "$web/.htaccess.next" "$web/.htaccess"
gate=0
published=1
printf '%s\n' "$run_number" > "$state/run-number"
printf '%s\n' "$commit" > "$state/commit"
echo "Published commit $commit; migrations completed. Private backup: $backup"
echo 'Backups are retained; review disk usage and apply an operator retention policy.'
