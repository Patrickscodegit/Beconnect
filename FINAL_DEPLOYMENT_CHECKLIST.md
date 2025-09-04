# Final Deployment Checklist - Kill fopen() and 405 Errors

## ✅ Implementation Complete

All the heavy lifting is done! Here's your final checklist to deploy cleanly.

## 🎯 What's Been Fixed

### 1. S3-Safe IntakeCreationService ✅
- ✅ Never treats Spaces keys as local paths
- ✅ Uses `$file->storeAs()` for both TemporaryUploadedFile and UploadedFile
- ✅ Clean `storeFileOnly()` method that works with any disk
- ✅ Environment-aware `documents` disk usage

### 2. Proper Livewire v3 Configuration ✅  
- ✅ Uses `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` environment variable
- ✅ Uses `LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH` for directory
- ✅ Fallback to `FILESYSTEM_DISK` if not specified

### 3. Environment-Aware Storage ✅
- ✅ `documents` disk switches between local/Spaces based on `DOCUMENTS_DRIVER`
- ✅ Same codebase works in development and production
- ✅ All file operations go through Laravel Storage facade

## 🚀 Deploy to Production

### Step 1: Run Final Deployment Script
```bash
./deploy_final_production.sh
```

This will:
- Configure all must-have environment variables
- Clear caches and run migrations  
- Test storage connectivity to Spaces
- Generate CORS and Nginx configurations
- Verify everything is working

### Step 2: Manual Server Configuration

#### A. Update Nginx Configuration
Use the generated `nginx_recommended_config.conf`:
- In Laravel Forge: Sites → Edit Files → Nginx Configuration
- Replace with the recommended configuration
- Reload: `sudo service nginx reload`

#### B. Configure DigitalOcean Spaces CORS
- Go to: DigitalOcean → Spaces → bconnect-documents → Settings → CORS  
- Upload the generated `digitalocean_spaces_cors.json`
- Or manually add the CORS rules for your domain

#### C. Restart Services
```bash
sudo service nginx reload
sudo service php8.3-fpm restart
```

## 🧪 Verify Deployment

### Check Storage Files
After running deployment script, verify in your DigitalOcean Spaces bucket:
- `documents/healthcheck.txt` 
- `livewire-tmp/healthcheck.txt`

### Test File Upload
1. Go to your app's file upload page
2. Upload a test file
3. Verify it appears in the `documents/` folder in your Spaces bucket
4. Check for any errors in Laravel logs

### Monitor for Errors
```bash
tail -f storage/logs/laravel.log
```

Look for:
- ❌ No more `fopen(livewire-tmp/...)` errors
- ❌ No more 405 Method Not Allowed on `/livewire/update`
- ✅ Clean file upload completion

## 🔧 Must-Have Production Environment Variables

Your `.env` should have:
```env
APP_ENV=production
DOCUMENTS_DRIVER=spaces
FILESYSTEM_DISK=spaces
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=spaces
LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH=livewire-tmp

AWS_ACCESS_KEY_ID=your_actual_spaces_key
AWS_SECRET_ACCESS_KEY=your_actual_spaces_secret  
AWS_DEFAULT_REGION=fra1
AWS_BUCKET=bconnect-documents
AWS_ENDPOINT=https://fra1.digitaloceanspaces.com
AWS_URL=https://bconnect-documents.fra1.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

## 🆘 If Issues Persist

### CORS Still Failing?
Use the fallback configuration in `.env`:
```env
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=public
```

Then run:
```bash
php artisan storage:link
mkdir -p storage/app/public/livewire-tmp
```

This keeps temp uploads local but final files still go to Spaces.

### 405 Errors on Livewire?
1. Check Nginx has the main location block: `try_files $uri $uri/ /index.php?$query_string;`
2. Ensure no special `/livewire/` location blocks
3. Verify CSRF token in page: `<meta name="csrf-token" content="{{ csrf_token() }}">`
4. Include Livewire assets: `@livewireStyles` and `@livewireScripts`

## 🎉 Success Criteria

You'll know it's working when:
- ✅ File uploads complete without errors
- ✅ Files appear in DigitalOcean Spaces `documents/` folder
- ✅ No `fopen()` errors in logs
- ✅ No 405 errors on `/livewire/update`
- ✅ Livewire file upload components work smoothly

## 🚨 Security Reminder

**Rotate these API keys immediately:**
- DigitalOcean Spaces access keys
- OpenAI API keys  
- Anthropic API keys

(They were visible in the chat history)

---

Your app is now ready for clean, production-grade file uploads! 🌟
