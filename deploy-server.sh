#!/bin/bash

# Generic VPS/Server Deployment Script

# Set your production server details
SERVER_USER="your_user"
SERVER_HOST="your-server.com"
PROJECT_PATH="/var/www/your-site"

echo "🚀 Deploying to production server..."

# Copy encrypted environment file to server
scp .env.production.encrypted $SERVER_USER@$SERVER_HOST:$PROJECT_PATH/

# SSH into server and complete deployment
ssh $SERVER_USER@$SERVER_HOST << EOF
cd $PROJECT_PATH

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader

# Decrypt environment file (you'll need to set the key as environment variable on server)
php artisan env:decrypt --key=\$LARAVEL_ENV_ENCRYPTION_KEY --env=production --force

# Clear and optimize
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
php artisan migrate --force

# Restart services
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx
sudo supervisorctl restart all

echo "✅ Deployment completed!"
EOF
