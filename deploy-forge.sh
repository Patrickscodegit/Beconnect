#!/bin/bash

# Laravel Forge Deployment Script
# Add this to your Forge site's deployment script

cd /home/forge/your-site

# Pull latest code
git pull origin main

# Install/update dependencies
composer install --no-dev --optimize-autoloader

# If using encrypted environment file:
# php artisan env:decrypt --key=base64:VciDRffsZ/jKS2lNJshiaBBd2eO1DE96kasV9K2t5Y8= --env=production --force

# Or copy your .env.production.template to .env and set variables via Forge UI

# Clear and cache everything
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations (if needed)
php artisan migrate --force

# Restart queues
php artisan queue:restart

# Restart PHP-FPM (Forge does this automatically)
echo "Deployment completed!"
