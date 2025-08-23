# SSH into your server and run these commands:

# Navigate to your site directory
cd /home/forge/bconnect.64.226.120.45.nip.io

# Reset any local changes
git reset --hard HEAD
git clean -df

# Pull the latest changes
git pull origin main

# Restart services
php artisan config:cache
php artisan route:cache
sudo supervisorctl restart all
