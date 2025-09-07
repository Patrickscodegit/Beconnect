# Production Issues Resolution Guide

## Overview
This document outlines the three main production issues identified and their solutions:

1. **Redis Connection Failures** - Horizon/Queue system can't connect to Redis
2. **Authentication Issues** - RobawsApiClient failing during composer operations
3. **File Storage Path Issues** - Storage disk path resolution problems

## Issues and Solutions

### 1. Redis Connection Issues ❌➡️✅

**Problem:** 
- Production logs show "Connection refused" errors when trying to connect to Redis
- Horizon dashboard not working
- Queue jobs not processing

**Root Cause:**
- Redis server not installed or not running on production server
- Queue configuration pointing to Redis but service unavailable

**Solution:**
```bash
# Install and start Redis on Ubuntu/Debian
sudo apt update
sudo apt install redis-server
sudo systemctl start redis-server
sudo systemctl enable redis-server

# Test Redis
redis-cli ping
# Should return PONG

# Restart Laravel queues/Horizon
php artisan horizon:terminate
php artisan horizon
```

**Alternative Fallback:**
If Redis installation is not possible, switch to database queues:
```env
# In .env file
QUEUE_CONNECTION=database
```

### 2. RobawsApiClient Authentication Issues ❌➡️✅

**Problem:**
- `withBasicAuth()` calls failing with null values during composer autoload discovery
- Preventing successful composer operations

**Root Cause:**
- Two locations in RobawsApiClient were calling `withBasicAuth()` without null checks
- One in `createHttpClient()` method (fixed earlier)
- One in `attachFileToOffer()` method (just fixed)

**Solution Applied:**
```php
// Before (problematic)
$http = $http->withBasicAuth(
    config('services.robaws.username'),
    config('services.robaws.password')
);

// After (null-safe)
$username = config('services.robaws.username');
$password = config('services.robaws.password');

// Handle null values gracefully for CI environments
if ($username !== null && $password !== null) {
    $http = $http->withBasicAuth($username, $password);
}
```

**Files Modified:**
- `app/Services/Export/Clients/RobawsApiClient.php` (line ~337 in attachFileToOffer method)

### 3. File Storage Path Resolution ❌➡️✅

**Problem:**
- File paths not resolving correctly between local and cloud storage
- `getFileContent()` method not handling path normalization

**Root Cause:**
- Mixed path formats (absolute vs relative)
- Storage disk configuration differences between environments

**Solution Applied:**
Enhanced `getFileContent()` method to:
1. Normalize paths by removing storage disk prefix if present
2. Try multiple path resolution strategies
3. Provide detailed error information for debugging
4. Handle both local file system and Storage facade access

**Files Modified:**
- `app/Services/Export/Clients/RobawsApiClient.php` (`getFileContent()` method)

## Deployment Scripts Created

### 1. `fix_production_issues.php`
- Comprehensive health check script
- Tests all system components (Redis, DB, Storage, API client)
- Provides detailed status and recommendations
- Can be run on both local and production environments

### 2. `setup_production.sh` 
- Complete production environment setup script for **Ubuntu/Debian servers**
- Installs Redis, configures Laravel, sets permissions
- Sets up Horizon for queue processing
- Includes proper error handling and colored output
- **Use this on your production server only**

### 3. `setup_local_macos.sh`
- Local development environment setup script for **macOS**
- Uses Homebrew instead of apt package manager
- Uses `brew services` instead of systemctl
- Sets up complete local development environment
- **Use this on your local Mac for development**

## How to Deploy the Fixes

### Option 1: Local Development (macOS)
1. Run the macOS setup script:
   ```bash
   chmod +x setup_local_macos.sh
   ./setup_local_macos.sh
   ```

### Option 2: Production Server Deployment (Ubuntu/Debian)
1. Upload the fixed files to production server
2. Run the production setup script:
   ```bash
   chmod +x setup_production.sh
   ./setup_production.sh
   ```

### Option 3: Git Deployment (Any Environment)
1. Upload the fixed files to production server
2. Run the setup script:
   ```bash
   chmod +x setup_production.sh
   ./setup_production.sh
   ```

### Option 2: Git Deployment
1. Commit the fixes:
   ```bash
   git add .
   git commit -m "Fix production issues: Redis auth, file storage paths, null auth handling"
   git push origin main
   ```

2. On production server:
   ```bash
   git pull origin main
   php artisan config:clear
   php artisan config:cache
   php artisan queue:restart
   ```

### Option 3: Using Forge (if applicable)
1. Push changes to Git repository
2. Deploy through Forge dashboard
3. Run the health check script after deployment

## Verification Steps

After deploying the fixes, verify everything is working:

```bash
# 1. Run the health check
php fix_production_issues.php

# 2. Test Redis connection
redis-cli ping

# 3. Test Laravel application
php artisan inspire

# 4. Check queue status (if using Horizon)
php artisan horizon:status

# 5. Test file storage
php artisan tinker
>>> Storage::disk('documents')->directories();
```

## Environment Configuration

Ensure your production `.env` file has:

```env
# Queue Configuration
QUEUE_CONNECTION=redis  # or 'database' as fallback
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Database Configuration (PostgreSQL)
DB_CONNECTION=pgsql
DB_HOST=your-db-host
DB_PORT=5432
DB_DATABASE=your-database
DB_USERNAME=your-username
DB_PASSWORD=your-password

# Robaws API Configuration
ROBAWS_BASE_URL=your-api-url
ROBAWS_API_KEY=your-api-key
ROBAWS_AUTH=basic  # or 'token'
ROBAWS_USERNAME=your-username  # if using basic auth
ROBAWS_PASSWORD=your-password  # if using basic auth
```

## Monitoring and Maintenance

### Health Check Automation
Consider adding the health check to a cron job:
```bash
# Add to crontab (crontab -e)
0 */6 * * * cd /path/to/your/app && php fix_production_issues.php >> storage/logs/health-check.log 2>&1
```

### Log Monitoring
Monitor these log files for ongoing issues:
- `storage/logs/laravel.log` - General application errors
- `storage/logs/horizon.log` - Queue processing issues
- `/var/log/redis/redis-server.log` - Redis service issues

## Success Indicators

✅ All systems operational when:
- Redis responds to `redis-cli ping`
- Laravel health check passes
- Queue jobs process successfully
- File uploads work without path errors
- No authentication errors in logs

The fixes address the root causes of all three production issues and include comprehensive error handling and fallback mechanisms.
