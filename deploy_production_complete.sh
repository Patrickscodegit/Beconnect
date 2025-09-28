#!/bin/bash

# Complete Production Deployment Script
# Configures environment-aware storage and deploys to production

set -e  # Exit on any error

echo "🚀 Starting complete production deployment..."
echo "============================================="

# Function to check if we're in the right directory
check_laravel_app() {
    if [ ! -f "artisan" ]; then
        echo "❌ Error: Not in Laravel application directory"
        exit 1
    fi
}

# Function to backup current environment
backup_env() {
    if [ -f ".env" ]; then
        cp .env .env.backup.$(date +%s)
        echo "✅ Backed up current .env file"
    fi
}

# Function to update environment variables
update_environment() {
    echo ""
    echo "🔧 Configuring environment for production..."
    
    # Read DigitalOcean credentials
    read -p "Enter your DigitalOcean Spaces Access Key: " SPACES_KEY
    read -s -p "Enter your DigitalOcean Spaces Secret Key: " SPACES_SECRET
    echo ""
    
    # Update .env for production
    cat > .env.production.tmp << EOF
# Production Storage Configuration - DigitalOcean Spaces  
DOCUMENTS_DRIVER=spaces
LIVEWIRE_DISK=documents

# DigitalOcean Spaces Configuration
AWS_ACCESS_KEY_ID=${SPACES_KEY}
AWS_SECRET_ACCESS_KEY=${SPACES_SECRET}
AWS_DEFAULT_REGION=fra1
AWS_BUCKET=bconnect-documents
AWS_URL=https://bconnect-documents.fra1.digitaloceanspaces.com
AWS_ENDPOINT=https://fra1.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false

# File system defaults
FILESYSTEM_DISK=documents
EOF

    # Merge with existing .env if it exists
    if [ -f ".env" ]; then
        # Remove existing storage-related lines and append new ones
        grep -v '^DOCUMENTS_DRIVER\|^LIVEWIRE_DISK\|^AWS_\|^SPACES_\|^FILESYSTEM_DISK' .env > .env.tmp || true
        cat .env.production.tmp >> .env.tmp
        mv .env.tmp .env
    else
        mv .env.production.tmp .env
    fi
    
    rm -f .env.production.tmp
    echo "✅ Updated production environment variables"
}

# Function to cache configuration
cache_config() {
    echo ""
    echo "⚡ Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    echo "✅ Configuration cached"
}

# Function to run database migrations
migrate_database() {
    echo ""
    echo "🗄️  Running database migrations..."
    php artisan migrate --force
    echo "✅ Database migrations complete"
}

# Function to seed essential data
seed_database() {
    echo ""
    echo "🌱 Seeding essential data..."
    php artisan db:seed --force
    echo "✅ Database seeding complete"
}

# Function to test storage configuration
test_storage() {
    echo ""
    echo "🧪 Testing storage configuration..."
    
    # Create test script
    cat > storage_test.php << 'EOF'
<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $disk = \Storage::disk('documents');
    $testFile = 'test_' . uniqid() . '.txt';
    $content = 'Storage test: ' . now();
    
    // Test write
    $disk->put($testFile, $content);
    echo "✅ Write test passed\n";
    
    // Test read  
    $retrieved = $disk->get($testFile);
    if ($retrieved === $content) {
        echo "✅ Read test passed\n";
    } else {
        echo "❌ Read test failed\n";
        exit(1);
    }
    
    // Test delete
    $disk->delete($testFile);
    echo "✅ Delete test passed\n";
    
    echo "🎉 All storage tests passed!\n";
    
} catch (Exception $e) {
    echo "❌ Storage test failed: " . $e->getMessage() . "\n";
    exit(1);
}
EOF

    php storage_test.php
    rm storage_test.php
    echo "✅ Storage tests completed successfully"
}

# Function to clean up
cleanup() {
    echo ""
    echo "🧹 Cleaning up..."
    php artisan optimize
    echo "✅ Cleanup complete"
}

# Main deployment flow
main() {
    check_laravel_app
    backup_env
    update_environment
    cache_config
    migrate_database
    seed_database
    test_storage
    cleanup
    
    echo ""
    echo "🎉 Production deployment complete!"
    echo "============================================="
    echo ""
    echo "📋 Summary:"
    echo "• Environment configured for DigitalOcean Spaces"
    echo "• Storage disk: 'documents' (environment-aware)"
    echo "• Files stored in: bconnect-documents bucket (fra1)"
    echo "• Database migrations applied"
    echo "• Essential data seeded (vehicle specs, WMIs, users)"
    echo "• Configuration cached"
    echo "• Storage functionality tested"
    echo ""
    echo "🌟 Your app is ready for production!"
    echo ""
    echo "💡 To switch back to local development:"
    echo "   ./setup_dev_storage.sh"
    echo ""
    echo "🔄 To redeploy with different credentials:"
    echo "   ./setup_prod_storage.sh"
}

# Run deployment
main
