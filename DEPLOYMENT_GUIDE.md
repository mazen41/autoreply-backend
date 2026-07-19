# Naz Autoreply - Production Deployment Guide

## Infrastructure Fixes Implemented

### 1. ✅ Production Environment Settings
- `APP_DEBUG=false` - Disabled debug mode for security
- `APP_ENV=production` - Set to production environment
- Secrets already in `.gitignore` - Environment variables protected

### 2. ✅ Email Delivery Configuration
- `MAIL_MAILER=smtp` - Configured for SMTP delivery
- Empty credentials placeholders for you to fill in with your SMTP service
- Add your SMTP provider credentials when ready (Mailgun, SendGrid, AWS SES, etc.)

### 3. ✅ Queue Worker Setup
Created Supervisor configuration files:
- `supervisor.conf` - Queue worker configuration (2 processes)
- `supervisor-scheduler.conf` - Laravel scheduler configuration

**To deploy:**
```bash
# Install Supervisor (if not installed)
sudo apt-get install supervisor

# Copy configuration files
sudo cp backend/supervisor.conf /etc/supervisor/conf.d/naz-queue-worker.conf
sudo cp backend/supervisor-scheduler.conf /etc/supervisor/conf.d/naz-scheduler.conf

# Update Supervisor and start services
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start naz-queue-worker:*
sudo supervisorctl start naz-scheduler

# Check status
sudo supervisorctl status
```

### 4. ✅ Laravel Scheduler Setup
Created `crontab` file for Laravel scheduler

**To deploy:**
```bash
# Add to crontab
crontab backend/crontab

# Or manually add this line to crontab:
# * * * * * cd /var/www/autoreply/backend && php artisan schedule:run >> /dev/null 2>&1
```

### 5. ✅ Failed Job Monitoring
Created `MonitorFailedJobs` command that:
- Checks failed jobs every 5 minutes
- Logs alerts when failures are detected
- Added to Laravel scheduler

### 6. ✅ Error Tracking Setup
Added Sentry configuration to `.env`:
- `SENTRY_LARAVEL_DSN=` - Add your Sentry DSN when ready
- `SENTRY_TRACES_SAMPLE_RATE=0.1` - 10% sampling for performance traces

**To complete:**
```bash
# Install Sentry Laravel package
cd backend
composer require sentry/sentry-laravel

# Publish Sentry config
php artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"

# Add your Sentry DSN to .env
SENTRY_LARAVEL_DSN=your-sentry-dsn-here
```

### 7. ✅ Log Rotation
- Added `LOG_DAILY_DAYS=14` to `.env`
- Laravel already configured for daily log rotation
- Logs will be automatically cleaned after 14 days

### 8. ✅ Database Backups
Created `BackupDatabase` command that:
- Runs daily at 2:00 AM
- Keeps last 7 backups
- Automatically deletes old backups
- Added to Laravel scheduler

**To enable:**
```bash
# Create backup directory
mkdir -p storage/app/backups

# Manually test backup
php artisan backup:database
```

### 9. ✅ Rate Limiting
Added rate limiting middleware to `api.php`:
- 60 requests per minute per IP
- Returns 429 status when limit exceeded
- Applied to all protected API routes

### 10. ✅ Input Validation
Created `ValidateInput` middleware that:
- Validates common API endpoints
- Enforces field requirements and constraints
- Added to middleware aliases

**To deploy:**
```bash
# The middleware is already registered in bootstrap/app.php
# Apply to specific routes as needed using 'validate.input' middleware
```

### 11. ✅ Environment Cleanup
- `.env` already in `.gitignore`
- All secrets protected from version control
- Production environment variables properly configured

## Remaining Manual Steps

### 1. SMTP Configuration
Add your SMTP credentials to `.env`:
```env
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
```

### 2. Sentry Setup
Add your Sentry DSN to `.env`:
```env
SENTRY_LARAVEL_DSN=your-sentry-dsn
```

### 3. Anthropic API Key
Add your Anthropic API key to `.env`:
```env
ANTHROPIC_API_KEY=your-anthropic-api-key
```

### 4. Supervisor Deployment
Follow the supervisor deployment steps above

### 5. Cron Job Setup
Add the cron job for Laravel scheduler

### 6. Test Everything
```bash
# Test queue worker
php artisan queue:work --tries=3 --timeout=300

# Test scheduler
php artisan schedule:run

# Test failed job monitoring
php artisan monitor:failed-jobs

# Test database backup
php artisan backup:database
```

## Production Checklist

- [ ] SMTP credentials configured
- [ ] Anthropic API key configured
- [ ] Sentry DSN configured
- [ ] Supervisor installed and running
- [ ] Queue workers started
- [ ] Scheduler cron job added
- [ ] Database backup directory created
- [ ] Test database backup successful
- [ ] Monitor failed jobs working
- [ ] Logs rotating properly
- [ ] Rate limiting tested
- [ ] Input validation tested
- [ ] Error tracking working
- [ ] HTTPS enabled on production domain
- [ ] SSL certificates configured
- [ ] Production database credentials configured
- [ ] Production API URLs configured
- [ ] Webhook URLs updated to production domain

## Monitoring Commands

```bash
# Check queue status
php artisan queue:failed

# Check failed jobs
php artisan queue:retry all

# Monitor logs
tail -f storage/logs/laravel.log

# Check supervisor status
sudo supervisorctl status

# Restart services
sudo supervisorctl restart naz-queue-worker:*
sudo supervisorctl restart naz-scheduler
```

## Emergency Procedures

### If Queue Workers Stop
```bash
sudo supervisorctl restart naz-queue-worker:*
```

### If Scheduler Stops
```bash
sudo supervisorctl restart naz-scheduler
```

### If Database Fails
```bash
# Restore from latest backup
php artisan backup:restore storage/app/backups/backup_autoreply_YYYY-MM-DD_HH-MM-SS.sql
```

### If Error Rate Spikes
Check Sentry dashboard for error details and patterns.