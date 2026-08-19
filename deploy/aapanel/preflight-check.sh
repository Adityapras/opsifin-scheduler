#!/usr/bin/env bash
set -u

PROJECT_DIR="/home/aditya_prasetyo/project/opsifin-crontab"
LEGACY_DIR="/home/aditya_prasetyo/project/crontab-legacy"
PHP_BIN="/www/server/php/84/bin/php"

pass() { printf 'PASS  %s\n' "$1"; }
warn() { printf 'WARN  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; }

printf 'Opsifin Scheduler aaPanel preflight\n'
printf '===================================\n'

if [ -x "$PHP_BIN" ]; then
    pass "aaPanel PHP binary exists"
else
    fail "aaPanel PHP binary is missing: $PHP_BIN"
fi

for extension_name in fileinfo curl intl mbstring openssl pdo_mysql bcmath xml; do
    if "$PHP_BIN" -r "exit(extension_loaded('$extension_name') ? 0 : 1);" 2>/dev/null; then
        pass "PHP extension $extension_name"
    else
        fail "PHP extension $extension_name"
    fi
done

if mysql --version 2>/dev/null | grep -q 'Distrib 5\.7\.'; then
    pass "MySQL client reports 5.7.x"
else
    fail "MySQL client is not 5.7.x or cannot be inspected"
fi

if [ -f "$PROJECT_DIR/.env" ]; then
    pass "project .env exists"
else
    fail "project .env is missing"
fi

if [ -f "$PROJECT_DIR/vendor/autoload.php" ]; then
    pass "Composer vendor directory exists"
else
    fail "Composer dependencies are missing"
fi

if [ -f "$PROJECT_DIR/public/build/manifest.json" ]; then
    pass "frontend production manifest exists"
else
    fail "frontend build manifest is missing"
fi

if [ -f "$LEGACY_DIR/opsifin_crontab" ] || [ -f "$LEGACY_DIR/crontab.txt" ]; then
    pass "legacy crontab source exists"
else
    fail "legacy opsifin_crontab/crontab.txt is missing from $LEGACY_DIR"
fi

if pgrep -x nginx >/dev/null 2>&1; then
    pass "Nginx process is running"
else
    fail "Nginx process is not running"
fi

if pgrep -f '/www/server/mysql/bin/mysqld' >/dev/null 2>&1; then
    pass "MySQL server process is running"
else
    fail "MySQL server process is not running"
fi

if pgrep -f 'supervisord' >/dev/null 2>&1; then
    pass "Supervisor service is running"
else
    fail "Supervisor service is not running"
fi

if ss -ltn 2>/dev/null | grep -qE ':80\s'; then
    pass "a service is listening on port 80"
else
    fail "nothing is listening on port 80"
fi

if curl -fsSI -H 'Host: opsifin-cron.local' http://127.0.0.1/admin >/dev/null 2>&1; then
    pass "opsifin-cron.local vhost responds"
else
    warn "vhost did not return a successful response yet (acceptable before migration)"
fi

if [ -r "$PROJECT_DIR/public/index.php" ]; then
    pass "public/index.php is readable by current user"
else
    fail "public/index.php is not readable"
fi

if [ -w "$PROJECT_DIR/storage/logs" ] && [ -w "$PROJECT_DIR/bootstrap/cache" ]; then
    pass "Laravel runtime directories are writable by current user"
else
    fail "Laravel runtime directories are not writable by current user"
fi

if [ -f "$PROJECT_DIR/.env" ]; then
    if "$PHP_BIN" "$PROJECT_DIR/artisan" db:show --database=mysql --no-interaction >/dev/null 2>&1; then
        pass "Laravel can connect to MySQL"
    else
        fail "Laravel cannot connect to MySQL; inspect locally without sharing the password"
    fi
fi

warn "Verify www permissions separately with: sudo -u www test -r public/index.php && sudo -u www test -w storage/logs && sudo -u www test -w bootstrap/cache"
warn "Do not enable imported schedules or disable legacy cron before the lean rewrite is verified"
