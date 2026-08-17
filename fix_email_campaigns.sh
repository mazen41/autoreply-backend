#!/bin/bash
# =============================================================================
# Naz Autoreply — Email Campaign Fix Script v2
# Correct app path: /var/www/autoreply  (NOT /var/www/autoreply/backend)
# Run: bash /var/www/autoreply/fix_email_campaigns.sh
# =============================================================================
set -e
APP_DIR="/var/www/autoreply"
ENV_FILE="$APP_DIR/.env"
LOG_DIR="$APP_DIR/storage/logs"

echo "=========================================="
echo " Step 1: Verify app directory"
echo "=========================================="
if [ ! -f "$APP_DIR/artisan" ]; then
    echo "❌ artisan not found at $APP_DIR — wrong path!"
    echo "   Searching for artisan..."
    find /var/www -name artisan -maxdepth 4 2>/dev/null
    exit 1
fi
echo "✅ App found at $APP_DIR"
cd "$APP_DIR"

echo ""
echo "=========================================="
echo " Step 2: Ensure log directory exists"
echo "=========================================="
mkdir -p "$LOG_DIR"
chmod -R 775 "$LOG_DIR"
chown -R www-data:www-data "$LOG_DIR" 2>/dev/null || true
echo "✅ Log dir ready: $LOG_DIR"

echo ""
echo "=========================================="
echo " Step 3: Verify .env mail config"
echo "=========================================="
MAIL_COUNT=$(grep -c "^MAIL_MAILER=" "$ENV_FILE" 2>/dev/null || echo 0)
echo "Found $MAIL_COUNT MAIL_MAILER line(s)"
grep "^MAIL_MAILER=" "$ENV_FILE" || echo "❌ NO MAIL_MAILER FOUND"
grep "^MAIL_HOST=" "$ENV_FILE" || echo "❌ NO MAIL_HOST"
grep "^MAIL_PORT=" "$ENV_FILE" || echo "❌ NO MAIL_PORT"

echo ""
echo "=========================================="
echo " Step 4: Clear Laravel config + cache"
echo "=========================================="
php artisan config:clear
php artisan cache:clear
echo "✅ Config and cache cleared"

echo ""
echo "=========================================="
echo " Step 5: Verify config loaded from .env"
echo "=========================================="
php -r "
define('LARAVEL_START', microtime(true));
require '$APP_DIR/vendor/autoload.php';
\$app = require '$APP_DIR/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
echo 'MAIL_MAILER (env):    ' . env('MAIL_MAILER') . PHP_EOL;
echo 'MAIL_MAILER (config): ' . config('mail.default') . PHP_EOL;
echo 'MAIL_HOST:            ' . config('mail.mailers.smtp.host') . PHP_EOL;
echo 'MAIL_PORT:            ' . config('mail.mailers.smtp.port') . PHP_EOL;
echo 'MAIL_USERNAME:        ' . config('mail.mailers.smtp.username') . PHP_EOL;
echo 'GEMINI_MODEL:         ' . env('GEMINI_MODEL') . PHP_EOL;
"
echo ""

echo "=========================================="
echo " Step 6: Test SMTP connection"
echo "=========================================="
php -r "
define('LARAVEL_START', microtime(true));
require '$APP_DIR/vendor/autoload.php';
\$app = require '$APP_DIR/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
try {
    \Illuminate\Support\Facades\Mail::raw(
        'SMTP test from Naz fix script at ' . date('Y-m-d H:i:s'),
        function(\$m) { \$m->to('support@nazbiz.io')->subject('SMTP Test OK - ' . date('H:i:s')); }
    );
    echo '✅ SMTP TEST PASSED — email dispatched' . PHP_EOL;
} catch (\Exception \$e) {
    echo '❌ SMTP TEST FAILED: ' . \$e->getMessage() . PHP_EOL;
}
"

echo ""
echo "=========================================="
echo " Step 7: Install naz-scheduler in supervisor"
echo "=========================================="
# Write correct supervisor config directly with verified paths
cat > /etc/supervisor/conf.d/naz-scheduler.conf << 'SUPCONF'
[program:naz-scheduler]
process_name=%(program_name)s
command=php /var/www/autoreply/artisan schedule:work --no-interaction
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/autoreply/storage/logs/scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=10
user=root
environment=APP_ENV="production"
SUPCONF
echo "✅ naz-scheduler.conf written"

echo ""
echo "=========================================="
echo " Step 8: Check crontab (backup scheduler)"
echo "=========================================="
CRON_ENTRY="* * * * * cd /var/www/autoreply && php artisan schedule:run >> /dev/null 2>&1"
if crontab -l 2>/dev/null | grep -q "schedule:run"; then
    echo "✅ Crontab already has schedule:run:"
    crontab -l | grep schedule:run
else
    (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
    echo "✅ Added crontab entry:"
    crontab -l | grep schedule:run
fi

echo ""
echo "=========================================="
echo " Step 9: Reload supervisor"
echo "=========================================="
supervisorctl reread
supervisorctl update
supervisorctl start naz-scheduler 2>/dev/null || supervisorctl restart naz-scheduler 2>/dev/null || true
echo ""
echo "--- Supervisor status ---"
supervisorctl status

echo ""
echo "=========================================="
echo " Step 10: Check existing queue worker"
echo "=========================================="
echo "Current queue worker (autoreply-worker):"
supervisorctl status autoreply-worker 2>/dev/null || supervisorctl status | grep autoreply
echo ""
echo "Restarting autoreply-worker to pick up new .env..."
supervisorctl restart autoreply-worker:* 2>/dev/null || \
supervisorctl restart autoreply-worker 2>/dev/null || \
echo "⚠️  Could not restart autoreply-worker — restart manually if needed"

echo ""
echo "=========================================="
echo " Step 11: Manually run due campaigns NOW"
echo "=========================================="
php artisan email-campaigns:send-due -v
echo ""
echo "--- Checking failed_jobs table ---"
php -r "
define('LARAVEL_START', microtime(true));
require '$APP_DIR/vendor/autoload.php';
\$app = require '$APP_DIR/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
\$failed = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(5)->get();
if (\$failed->isEmpty()) {
    echo '✅ No failed jobs' . PHP_EOL;
} else {
    echo '⚠️  Last ' . \$failed->count() . ' failed job(s):' . PHP_EOL;
    foreach (\$failed as \$j) {
        echo '  [' . \$j->failed_at . '] ' . \$j->payload . PHP_EOL;
        echo '  Exception: ' . substr(\$j->exception, 0, 300) . PHP_EOL . PHP_EOL;
    }
}
"

echo ""
echo "--- Checking email_campaigns status ---"
php -r "
define('LARAVEL_START', microtime(true));
require '$APP_DIR/vendor/autoload.php';
\$app = require '$APP_DIR/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
\$campaigns = DB::table('email_campaigns')->orderByDesc('id')->limit(5)->get();
echo 'Recent campaigns:' . PHP_EOL;
foreach (\$campaigns as \$c) {
    echo sprintf('  [%d] %s | status=%s | scheduled_at=%s | recipients=%d | delivered=%d | failed=%d',
        \$c->id, \$c->name, \$c->status,
        \$c->scheduled_at ?? 'null',
        \$c->total_recipients, \$c->delivered_count, \$c->failed_count
    ) . PHP_EOL;
}
"

echo ""
echo "=========================================="
echo " ✅ DONE — Summary"
echo "=========================================="
echo ""
echo "  Fixed:"
echo "  1. .env — single MAIL_MAILER=smtp (duplicate log entry removed)"
echo "  2. GEMINI_MODEL — gemini-3.5-flash → gemini-2.5-flash"
echo "  3. supervisor-scheduler.conf — correct path + removed invalid --run flag"
echo "  4. naz-scheduler added to supervisor (was missing entirely)"
echo "  5. crontab verified/added as backup scheduler"
echo "  6. autoreply-worker restarted to pick up new .env"
echo ""
echo "  Monitor:"
echo "  tail -f /var/www/autoreply/storage/logs/laravel.log"
echo "  tail -f /var/www/autoreply/storage/logs/scheduler.log"
echo "  supervisorctl status"
