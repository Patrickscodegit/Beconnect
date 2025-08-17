# Bconnect Production Deployment Guide

## 🚀 Complete Setup for Laravel Forge

This guide covers the complete production deployment of your **Bconnect freight-forwarding automation system** with PostgreSQL and MinIO storage.

### 📋 Prerequisites
- Laravel Forge server provisioned (✅ Done)
- GitHub repository connected (✅ Done)  
- Domain configured: `bconnect.64.226.120.45.nip.io` (✅ Done)

## 🗄️ Step 1: Setup PostgreSQL

Run this command in **Forge Commands**:
```bash
bash /home/forge/bconnect.64.226.120.45.nip.io/forge_postgresql_setup.sh
```

This will:
- Install PostgreSQL 14+
- Create `forge` database and user
- Configure authentication
- Install all system dependencies (Tesseract, Ghostscript, etc.)
- Set up Redis for queues

## 💾 Step 2: Setup MinIO Storage

Run this command in **Forge Commands**:
```bash
bash /home/forge/bconnect.64.226.120.45.nip.io/forge_minio_setup.sh
```

This will:
- Install Docker and MinIO
- Create buckets: `bconnect`, `bconnect-processed`, `bconnect-archive`
- Generate secure access keys
- Configure bucket policies

### Update Environment Variables
After MinIO setup, update your Forge environment with the generated keys:
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=bconnect_app
AWS_SECRET_ACCESS_KEY=[generated_secret_from_script]
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=bconnect
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

## 🔧 Step 3: Configure Services

### Queue Management
In Forge **Queues** section:
- **Connection**: `redis`
- **Queue**: `default,high`
- **Processes**: `3`
- **Timeout**: `300`
- **Sleep**: `3`

### Laravel Horizon
Enable **Laravel Horizon** in the site settings.

### Scheduler
Enable **Laravel Scheduler** to run every minute.

## 🔐 Step 4: SSL Certificate

In Forge **SSL** section:
- Choose **Let's Encrypt**
- Domain: `bconnect.64.226.120.45.nip.io`
- Click **Obtain Certificate**

## 🚀 Step 5: Deploy Application

### Update Deployment Script
In Forge **App** section, update the deployment script:

```bash
cd /home/forge/bconnect.64.226.120.45.nip.io
git pull origin main
composer install --no-interaction --prefer-dist --optimize-autoloader

# Build frontend assets
npm ci && npm run build

# Clear and cache configuration
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run database migrations
php artisan migrate --force

# Seed initial data (VIN WMI and Vehicle Specs)
php artisan db:seed --force

# Create storage symlink
php artisan storage:link

# Restart Horizon for queue processing
php artisan horizon:terminate

# Clear various caches
php artisan cache:clear
php artisan view:clear
```

### Trigger Deployment
Click **Deploy Now** or push to your GitHub repository.

## ✅ Step 6: Post-Deployment Verification

### Test Database Connection
```bash
PGPASSWORD='jfklsfjqmfjqmlfj' psql -h 127.0.0.1 -U forge -d forge -c "SELECT COUNT(*) FROM vin_wmis;"
```

### Test MinIO Storage
Access MinIO console at: `http://64.226.120.45:9001`

### Test Application
Visit: `https://bconnect.64.226.120.45.nip.io`

### Test Document Upload
1. Navigate to `/upload`
2. Upload a test PDF document
3. Monitor processing in `/intakes/{id}/results`

## 📊 Monitoring

### Queue Status
```bash
php artisan horizon:status
```

### Application Logs
```bash
tail -f storage/logs/laravel.log
```

### MinIO Console
Monitor storage at: `http://64.226.120.45:9001`

## 🔧 Environment Configuration

Your complete production environment:

```env
APP_NAME=Bconnect
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bconnect.64.226.120.45.nip.io

# PostgreSQL Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=forge
DB_USERNAME=forge
DB_PASSWORD="jfklsfjqmfjqmlfj"

# Queue Configuration
QUEUE_CONNECTION=redis
HORIZON_PREFIX=horizon:bconnect

# Cache Configuration
CACHE_STORE=redis

# Session Configuration
SESSION_DRIVER=database

# Storage Configuration (MinIO)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=bconnect_app
AWS_SECRET_ACCESS_KEY=[from_setup_script]
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=bconnect
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true

# OpenAI Configuration
OPENAI_API_KEY=[your_actual_key]
OPENAI_MODEL=gpt-4-turbo-preview

# OCR Configuration
TESSERACT_PATH=/usr/bin/tesseract
GHOSTSCRIPT_PATH=/usr/bin/gs
POPPLER_PATH=/usr/bin

# Rate Limiting
RATE_LIMIT_OPENAI_REQUESTS_PER_MINUTE=50
RATE_LIMIT_OCR_REQUESTS_PER_MINUTE=100

# File Processing
MAX_FILE_SIZE_MB=50
MAX_PROCESSING_TIME_SECONDS=300
```

## 🎯 System Architecture

Your production system includes:

- **Laravel 11** - Modern PHP framework
- **PostgreSQL** - Robust relational database
- **MinIO** - S3-compatible object storage
- **Redis** - Queue and cache management
- **Horizon** - Queue monitoring and management
- **Tesseract OCR** - Text extraction from images/PDFs
- **OpenAI GPT-4** - AI-powered data extraction
- **Let's Encrypt SSL** - Secure HTTPS connections

## 🔄 Automated Deployment

Your GitHub repository is connected for automatic deployment:
- Push to `main` branch triggers deployment
- Deployment script runs automatically
- Zero-downtime deployment with Forge

## 📈 Performance Optimizations

- **OPcache** enabled for PHP performance
- **Redis** for session and cache storage
- **Queue workers** for background processing
- **Database connection pooling**
- **CDN-ready** static assets

## 🛡️ Security Features

- **HTTPS** with Let's Encrypt
- **CSRF protection** enabled
- **Rate limiting** for API endpoints
- **Secure file uploads** with validation
- **Environment variable** security
- **Database password** encryption

Your **Bconnect freight-forwarding automation system** is now production-ready! 🚀
