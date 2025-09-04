# BConnect Production Deployment Fix Guide

## Issues Identified and Fixed:

### 1. ✅ **Hardcoded Amsterdam (ams3) References Fixed**
- **Problem**: `config/filesystems.php` had hardcoded `ams3` (Amsterdam) defaults
- **Solution**: Updated defaults to `fra1` (Frankfurt) to match your actual bucket

### 2. ✅ **Livewire Configuration Created**
- **Problem**: Missing Livewire config for DigitalOcean Spaces temp uploads
- **Solution**: Created `config/livewire.php` with proper Spaces configuration

### 3. ✅ **Production Environment Configuration Ready**
- **Problem**: Mismatched .env configuration
- **Solution**: Created complete production `.env` configuration

## Deployment Steps:

### Step 1: Update Your Laravel Forge .env File
Copy the configuration from `production-env-config.txt` and update these values in your Forge site's environment:

```bash
# Replace these with your actual values:
SPACES_KEY=YOUR_ACTUAL_SPACES_ACCESS_KEY
SPACES_SECRET=YOUR_ACTUAL_SPACES_SECRET_KEY
SPACES_REGION=fra1
SPACES_BUCKET=bconnect-documents
SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com
SPACES_URL=https://bconnect-documents.fra1.digitaloceanspaces.com

# Ensure these are set:
FILESYSTEM_DISK=spaces
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=spaces
LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH=livewire-tmp
```

### Step 2: Deploy Your Code Changes
Push the updated code to your repository:

```bash
git add .
git commit -m "Fix hardcoded ams3 references and add Livewire config for Frankfurt Spaces"
git push origin main
```

### Step 3: Run Debug Recipe on Forge
After deployment, run the debug recipe in your Forge site's terminal:

```bash
# Upload and run the debug recipe
bash forge-production-debug-recipe.sh > debug-output.log 2>&1
cat debug-output.log
```

### Step 4: Verify DigitalOcean Spaces Setup
Ensure your DO Spaces bucket has the correct structure:
- ✅ **Bucket name**: `bconnect-documents`
- ✅ **Region**: `fra1` (Frankfurt)
- ✅ **Folders**: `documents/` and `livewire-tmp/`
- ✅ **Access**: Private with proper CORS if needed

### Step 5: Clear Caches (Critical!)
After updating .env and deploying:

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

## Expected Fixes:

### ✅ **No More ams3 References**
- Default region changed from `ams3` → `fra1`
- Default endpoint changed from `ams3.digitaloceanspaces.com` → `fra1.digitaloceanspaces.com`

### ✅ **Proper Livewire Temp Storage**
- Livewire temporary uploads now use DigitalOcean Spaces
- Temp files stored in `livewire-tmp/` folder within your bucket
- Proper cleanup and expiration configured

### ✅ **Environment Consistency**
- Production .env matches actual bucket location and configuration
- All storage-related settings properly aligned

## Troubleshooting:

If you still get 500 errors after deployment:

1. **Check Laravel logs** on Forge:
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Verify storage connection**:
   ```bash
   php artisan tinker
   Storage::disk('spaces')->put('test.txt', 'test');
   Storage::disk('spaces')->exists('test.txt');
   ```

3. **Check Livewire temp directory permissions**:
   ```bash
   # In your bucket, ensure livewire-tmp folder exists
   # Check CORS settings if getting cross-origin errors
   ```

## Files Changed:
- ✅ `config/filesystems.php` - Fixed hardcoded ams3 references
- ✅ `config/livewire.php` - Added proper Spaces configuration
- ✅ `production-env-config.txt` - Complete Frankfurt configuration
- ✅ `forge-production-debug-recipe.sh` - Debug and validation script

The main issue was your app was trying to use Amsterdam (ams3) endpoints while your bucket is actually in Frankfurt (fra1). This is now fixed at the configuration level.
