#!/bin/bash

# Final Production Deployment with Smoke Tests
# Implements the complete checklist to kill fopen() and 405 errors

set -e

echo "🚀 Final Production Deployment - Complete Checklist"
echo "===================================================="

# Function to check if we're in Laravel app
check_laravel_app() {
    if [ ! -f "artisan" ]; then
        echo "❌ Error: Not in Laravel application directory"
        exit 1
    fi
}

# Function to update environment for production
update_production_env() {
    echo ""
    echo "🔧 Configuring production environment variables..."
    
    # Read credentials
    read -p "Enter your DigitalOcean Spaces Access Key: " SPACES_KEY
    read -s -p "Enter your DigitalOcean Spaces Secret Key: " SPACES_SECRET
    echo ""
    read -p "Enter your production domain (e.g., bconnect.64.226.120.45.nip.io): " PROD_DOMAIN
    
    # Update .env with must-have production config
    cat >> .env << EOF

# Must-have production configuration
APP_ENV=production
DOCUMENTS_DRIVER=spaces
FILESYSTEM_DISK=spaces
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=spaces
LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH=livewire-tmp

# DigitalOcean Spaces Configuration
AWS_ACCESS_KEY_ID=${SPACES_KEY}
AWS_SECRET_ACCESS_KEY=${SPACES_SECRET}
AWS_DEFAULT_REGION=fra1
AWS_BUCKET=bconnect-documents
AWS_ENDPOINT=https://fra1.digitaloceanspaces.com
AWS_URL=https://bconnect-documents.fra1.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false

# Production domain for CORS
APP_URL=http://${PROD_DOMAIN}
EOF

    echo "✅ Production environment configured"
}

# Function to clear caches
clear_caches() {
    echo ""
    echo "🧹 Clearing caches..."
    php artisan optimize:clear
    php artisan config:cache
    
    # Only terminate Horizon if it's running
    if pgrep -f "horizon" > /dev/null; then
        php artisan horizon:terminate
        echo "✅ Horizon terminated"
    fi
    
    echo "✅ Caches cleared and configuration cached"
}

# Function to run smoke test A - print effective config
smoke_test_config() {
    echo ""
    echo "🧪 Smoke Test A: Checking effective configuration..."
    
    php artisan tinker --execute="
echo 'docs: '.config('filesystems.disks.documents.driver').PHP_EOL;
echo 'docs root: '.(config('filesystems.disks.documents.root') ?? '(s3)').PHP_EOL;
echo 'lw disk: '.config('livewire.temporary_file_uploads.disk').PHP_EOL;
echo 'lw path: '.config('livewire.temporary_file_uploads.directory').PHP_EOL;
"
    
    echo "✅ Configuration check complete"
}

# Function to run smoke test B - write to Spaces
smoke_test_storage() {
    echo ""
    echo "🧪 Smoke Test B: Testing Spaces storage..."
    
    php artisan tinker --execute="
use Illuminate\Support\Facades\Storage;
try {
    Storage::disk('documents')->put('healthcheck.txt', 'ok '.now());
    Storage::disk(config('livewire.temporary_file_uploads.disk'))
      ->put(config('livewire.temporary_file_uploads.directory').'/healthcheck.txt', 'ok '.now());
    echo '✅ Storage tests passed - check your bucket for:'.PHP_EOL;
    echo '   • documents/healthcheck.txt'.PHP_EOL;
    echo '   • livewire-tmp/healthcheck.txt'.PHP_EOL;
} catch (Exception \$e) {
    echo '❌ Storage test failed: '.\$e->getMessage().PHP_EOL;
    exit(1);
}
"
    
    echo "✅ Storage tests completed successfully"
}

# Function to create CORS configuration template
create_cors_config() {
    echo ""
    echo "📋 Creating CORS configuration for DigitalOcean Spaces..."
    
    cat > digitalocean_spaces_cors.json << EOF
[
  {
    "AllowedOrigins": ["http://${PROD_DOMAIN}"],
    "AllowedMethods": ["GET","PUT","POST","DELETE","HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag","x-amz-request-id","x-amz-id-2"],
    "MaxAgeSeconds": 3000
  }
]
EOF

    echo "✅ CORS configuration saved to digitalocean_spaces_cors.json"
    echo "📌 Apply this in DigitalOcean: Spaces → bconnect-documents → Settings → CORS"
}

# Function to create Nginx configuration
create_nginx_config() {
    echo ""
    echo "🌐 Creating recommended Nginx configuration..."
    
    cat > nginx_site_config.txt << 'EOF'
# Recommended Nginx configuration for Laravel + Livewire

server {
    listen 80;
    server_name your-domain.com;
    root /home/forge/your-site/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    # Main location - handles Livewire routes properly
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

    echo "✅ Nginx configuration saved to nginx_site_config.txt"
    echo "📌 Use this configuration in Forge for your site"
}

# Function to run database migrations
run_migrations() {
    echo ""
    echo "🗄️  Running database migrations..."
    php artisan migrate --force
    echo "✅ Migrations completed"
}

# Function to create fallback local temp configuration
create_fallback_config() {
    echo ""
    echo "💡 Creating fallback configuration (if CORS issues persist)..."
    
    cat > .env.fallback << 'EOF'
# Fallback configuration - local temp uploads, final files on Spaces
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=public
LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH=livewire-tmp

# Ensure storage link exists
# Run: php artisan storage:link
# Run: mkdir -p storage/app/public/livewire-tmp
EOF

    echo "✅ Fallback configuration saved to .env.fallback"
    echo "💡 If CORS issues persist, merge this into your .env"
}

# Main deployment function
main() {
    check_laravel_app
    update_production_env
    clear_caches
    run_migrations
    smoke_test_config
    smoke_test_storage
    create_cors_config
    create_nginx_config
    create_fallback_config
    
    echo ""
    echo "🎉 Final Production Deployment Complete!"
    echo "========================================"
    echo ""
    echo "📋 What's been configured:"
    echo "• ✅ Production environment variables"
    echo "• ✅ S3-safe file storage (no more fopen errors)"
    echo "• ✅ Environment-aware document storage"
    echo "• ✅ Livewire v3 compatible configuration"
    echo "• ✅ Database migrations applied"
    echo "• ✅ Storage functionality tested"
    echo "• ✅ CORS configuration template created"
    echo "• ✅ Nginx configuration template created"
    echo "• ✅ Fallback configuration prepared"
    echo ""
    echo "🔧 Next manual steps:"
    echo "1. Apply CORS config in DigitalOcean Spaces dashboard"
    echo "2. Update Nginx configuration (use nginx_site_config.txt)"
    echo "3. Reload Nginx: sudo service nginx reload"
    echo "4. Restart PHP-FPM: sudo service php8.3-fpm restart"
    echo ""
    echo "🧪 Verify deployment:"
    echo "• Check bucket for healthcheck files"
    echo "• Test file uploads in your app"
    echo "• Monitor logs for any remaining errors"
    echo ""
    echo "🚨 Security reminder: Rotate your API keys that were posted in chat!"
    echo ""
    echo "🌟 Your app should now upload files cleanly without fopen() or 405 errors!"
}

# Run deployment
main
