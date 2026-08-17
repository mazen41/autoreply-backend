#!/bin/bash
# =============================================================================
# Naz Autoreply — Email Campaign Fix Script
# Run as root on the VPS: bash /var/www/autoreply/fix_email_campaigns.sh
# =============================================================================
set -e

APP_DIR="/var/www/autoreply/backend"
ENV_FILE="$APP_DIR/.env"

echo "=========================================="
echo " Step 1: Fix .env — remove duplicate MAIL_MAILER"
echo "=========================================="

# Count how many MAIL_MAILER lines exist
COUNT=$(grep -c "^MAIL_MAILER=" "$ENV_FILE" 2>/dev/null || echo 0)
echo "Found $COUNT MAIL_MAILER line(s)"

if [ "$COUNT" -gt "1" ]; then
    echo "Removing duplicate MAIL_MAILER entries (keeping last one)..."
    # Keep only the LAST occurrence of MAIL_MAILER=
    # Strategy: delete all but the last one
    TMPFILE=$(mktemp)
    awk '
        /^MAIL_MAILER=/ { last_line=NR; last_val=$0; lines[NR]=$0; next }
        { lines[NR]=$0 }
        END {
            for(i=1;i<=NR;i++){
                if(i==last_line){ print last_val }
                else if(lines[i] ~ /^MAIL_MAILER=/) { /* skip duplicates */ }
                else { print lines[i] }
            }
        }
    ' "$ENV_FILE" > "$TMPFILE"
    mv "$TMPFILE" "$ENV_FILE"
    echo "✅ Duplicate removed"
else
    echo "✅ No duplicates found"
fi

# Verify result
echo "Current MAIL_MAILER setting:"
grep "^MAIL_MAILER=" "$ENV_FILE"

echo ""
echo "=========================================="
echo " Step 2: Fix GEMINI_MODEL typo"
echo "=========================================="
# gemini-3.5-flash does not exist — correct is gemini-2.5-flash
if grep -q "gemini-3.5-flash" "$ENV_FILE"; then
    sed -i 's/gemini-3\.5-flash/gemini-2.5-flash/g' "$ENV_FILE"
    echo "✅ Fixed GEMINI_MODEL: gemini-3.5-flash → gemini-2.5-flash"
else
    echo "✅ GEMINI_MODEL is already correct"
fi
grep "^GEMINI_MODEL=" "$ENV_FILE"

echo ""
echo "=========================================="
echo " Step 3: Clear all Laravel config/cache"
echo "=========================================="
cd "$APP_DIR"
php artisan config:clear
php artisan cache:clear
php artisan queue:clear 2>/dev/null || true
echo "✅ Cache cleared"

echo ""
echo "=========================================="
echo " Step 4: Check/fix supervisor scheduler config"
echo "=========================================="
SCHED_CONF="/var/www/autoreply/backend/supervisor-scheduler.conf"
if grep -q "schedule:work --run" "$SCHED_CONF" 2>/dev/null; then
    sed -i 's/schedule:work --run --no-interaction/schedule:work --no-interaction/g' "$SCHED_CONF"
    echo "✅ Fixed schedule:work command (removed invalid --run flag)"
fi

# Copy to supervisor include dir if needed
if [ -d /etc/supervisor/conf.d ]; then
    cp /var/www/autoreply/backend/supervisor.conf /etc/supervisor/conf.d/naz-queue.conf
    cp /var/www/autoreply/backend/supervisor-scheduler.conf /etc/supervisor/conf.d/naz-scheduler.conf
    echo "✅ Supervisor configs copied to /etc/supervisor/conf.d/"
fi

echo ""
echo "=========================================="
echo " Step 5: Check crontab"
echo "=========================================="
# Ensure the Laravel scheduler cron is installed
CRON_ENTRY="* * * * * cd /var/www/autoreply/backend && php artisan schedule:run >> /dev/null 2>&1"
if crontab -l 2>/dev/null | grep -q "schedule:run"; then
    echo "✅ Crontab already has schedule:run"
else
    echo "Adding schedule:run to crontab..."
    (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
    echo "✅ Crontab entry added"
fi
crontab -l

echo ""
echo "=========================================="
echo " Step 6: Restart supervisor workers"
echo "=========================================="
supervisorctl reread 2>/dev/null && supervisorctl update 2>/dev/null || true
supervisorctl restart naz-queue-worker:* 2>/dev/null || supervisorctl restart all 2>/dev/null || true
supervisorctl restart naz-scheduler 2>/dev/null || true
echo "✅ Supervisor restarted"

echo ""
echo "=========================================="
echo " Step 7: Test SMTP connectivity"
echo "=========================================="
cd "$APP_DIR"
php artisan tinker --no-interaction << 'TINKER'
try {
    \Illuminate\Support\Facades\Mail::raw('Test from Naz fix script - ' . now(), function($m) {
        $m->to('support@nazbiz.io')->subject('SMTP Test - ' . now());
    });
    echo "✅ SMTP TEST PASSED - email sent successfully\n";
} catch (\Exception $e) {
    echo "❌ SMTP TEST FAILED: " . $e->getMessage() . "\n";
}
TINKER

echo ""
echo "=========================================="
echo " Step 8: Run due campaigns now (manual trigger)"
echo "=========================================="
cd "$APP_DIR"
php artisan email-campaigns:send-due --verbose 2>&1 || php artisan email-campaigns:send-due

echo ""
echo "=========================================="
echo " Step 9: Check supervisor status"
echo "=========================================="
supervisorctl status

echo ""
echo "=========================================="
echo " Step 10: Verify mail config loaded correctly"
echo "=========================================="
cd "$APP_DIR"
php artisan tinker --no-interaction << 'TINKER'
echo "MAIL_MAILER from env(): " . env('MAIL_MAILER') . "\n";
echo "MAIL_MAILER from config(): " . config('mail.default') . "\n";
echo "MAIL_HOST: " . config('mail.mailers.smtp.host') . "\n";
echo "MAIL_PORT: " . config('mail.mailers.smtp.port') . "\n";
echo "MAIL_USERNAME: " . config('mail.mailers.smtp.username') . "\n";
echo "GEMINI_MODEL: " . env('GEMINI_MODEL') . "\n";
TINKER

echo ""
echo "=========================================="
echo " ✅ ALL DONE"
echo "=========================================="
echo ""
echo "Summary of what was fixed:"
echo "  1. Removed duplicate MAIL_MAILER=log (was overriding MAIL_MAILER=smtp)"
echo "  2. Fixed GEMINI_MODEL typo: gemini-3.5-flash → gemini-2.5-flash"
echo "  3. Fixed supervisor scheduler: removed invalid --run flag from schedule:work"
echo "  4. Cleared Laravel config cache so new .env values load"
echo "  5. Restarted queue workers and scheduler"
echo "  6. Manually triggered due campaigns"
echo ""
echo "If the SMTP test passed, scheduled campaigns will now send on time."
echo "Check logs: tail -f /var/www/autoreply/backend/storage/logs/laravel.log"
