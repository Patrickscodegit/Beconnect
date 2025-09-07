#!/bin/bash

# Production Environment Setup and Fix Script
# This script should be run on the production server to fix the issues

set -e

echo "🚀 Bconnect Production Environment Setup & Fix Script"
echo "=================================================="
echo

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    local status=$1
    local message=$2
    case $status in
        "success") echo -e "${GREEN}✅ $message${NC}" ;;
        "error") echo -e "${RED}❌ $message${NC}" ;;
        "warning") echo -e "${YELLOW}⚠️  $message${NC}" ;;
        "info") echo -e "${YELLOW}ℹ️  $message${NC}" ;;
    esac
}

# Check if we're running as root (needed for system package installation)
check_root() {
    if [[ $EUID -eq 0 ]]; then
        print_status "warning" "Running as root. This is okay for system package installation."
    else
        print_status "info" "Not running as root. May need sudo for system packages."
    fi
}

# Install Redis if not present
install_redis() {
    print_status "info" "Checking Redis installation..."
    
    if command -v redis-server >/dev/null 2>&1; then
        print_status "success" "Redis is already installed"
    else
        print_status "warning" "Redis not found. Installing..."
        
        # Detect OS and install accordingly
        if [ -f /etc/debian_version ]; then
            # Ubuntu/Debian
            apt update
            apt install -y redis-server
        elif [ -f /etc/redhat-release ]; then
            # CentOS/RHEL/Amazon Linux
            yum install -y epel-release
            yum install -y redis
        else
            print_status "error" "Unsupported OS for automatic Redis installation"
            exit 1
        fi
        
        print_status "success" "Redis installed successfully"
    fi
    
    # Start and enable Redis
    systemctl start redis-server 2>/dev/null || systemctl start redis
    systemctl enable redis-server 2>/dev/null || systemctl enable redis
    
    print_status "success" "Redis service started and enabled"
}

# Test Redis connection
test_redis() {
    print_status "info" "Testing Redis connection..."
    
    if redis-cli ping > /dev/null 2>&1; then
        print_status "success" "Redis is responding to ping"
    else
        print_status "error" "Redis is not responding. Check the service status:"
        systemctl status redis-server || systemctl status redis
        exit 1
    fi
}

# Check PostgreSQL connection
test_postgresql() {
    print_status "info" "Testing PostgreSQL connection..."
    
    # This will be tested by Laravel later, but we can check if psql is available
    if command -v psql >/dev/null 2>&1; then
        print_status "success" "PostgreSQL client is available"
    else
        print_status "warning" "PostgreSQL client (psql) not found. Database connection will be tested by Laravel."
    fi
}

# Set up Laravel environment
setup_laravel_env() {
    print_status "info" "Setting up Laravel environment..."
    
    # Check if .env file exists
    if [ ! -f .env ]; then
        if [ -f .env.example ]; then
            cp .env.example .env
            print_status "success" "Created .env from .env.example"
        else
            print_status "error" ".env file not found and no .env.example available"
            exit 1
        fi
    fi
    
    # Generate app key if not set
    if ! grep -q "APP_KEY=base64:" .env; then
        php artisan key:generate --force
        print_status "success" "Generated Laravel application key"
    fi
    
    # Install/update composer dependencies
    if [ -f composer.json ]; then
        composer install --no-dev --optimize-autoloader
        print_status "success" "Composer dependencies installed"
    fi
    
    # Clear and cache config
    php artisan config:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    print_status "success" "Laravel caches cleared and optimized"
}

# Run database migrations
run_migrations() {
    print_status "info" "Running database migrations..."
    
    if php artisan migrate --force; then
        print_status "success" "Database migrations completed"
    else
        print_status "error" "Database migrations failed"
        exit 1
    fi
}

# Test the application
test_application() {
    print_status "info" "Testing Laravel application..."
    
    # Test basic Laravel functionality
    if php artisan inspire > /dev/null 2>&1; then
        print_status "success" "Laravel application is responding"
    else
        print_status "error" "Laravel application test failed"
        exit 1
    fi
    
    # Test our production health check
    if [ -f fix_production_issues.php ]; then
        print_status "info" "Running production health check..."
        php fix_production_issues.php
    else
        print_status "warning" "Production health check script not found"
    fi
}

# Set proper permissions
set_permissions() {
    print_status "info" "Setting proper file permissions..."
    
    # Laravel standard permissions
    chown -R $USER:www-data storage bootstrap/cache
    chmod -R 775 storage bootstrap/cache
    
    print_status "success" "File permissions set correctly"
}

# Setup Horizon (if using Redis queues)
setup_horizon() {
    print_status "info" "Setting up Horizon for queue processing..."
    
    # Publish Horizon assets
    php artisan horizon:install
    
    # Create supervisor configuration for Horizon
    cat > /etc/supervisor/conf.d/horizon.conf << EOF
[program:horizon]
process_name=%(program_name)s
command=php $(pwd)/artisan horizon
autostart=true
autorestart=true
user=$(whoami)
redirect_stderr=true
stdout_logfile=$(pwd)/storage/logs/horizon.log
stopwaitsecs=3600
EOF
    
    # Reload supervisor
    supervisorctl reread
    supervisorctl update
    supervisorctl start horizon
    
    print_status "success" "Horizon setup completed"
}

# Main execution
main() {
    echo "Starting production environment setup..."
    echo
    
    check_root
    
    # System level setup
    install_redis
    test_redis
    test_postgresql
    
    # Application level setup
    setup_laravel_env
    run_migrations
    set_permissions
    
    # Queue system setup
    if grep -q "QUEUE_CONNECTION=redis" .env; then
        setup_horizon
    else
        print_status "info" "Not using Redis queues, skipping Horizon setup"
    fi
    
    # Final tests
    test_application
    
    echo
    print_status "success" "🎉 Production environment setup completed successfully!"
    echo
    echo "Next steps:"
    echo "1. Configure your web server (Nginx/Apache) to point to the public directory"
    echo "2. Set up SSL certificate"
    echo "3. Configure your domain DNS"
    echo "4. Update your .env file with production database and API credentials"
    echo "5. Test the application through your web server"
    
    if [ -f forge-production.env ]; then
        echo
        print_status "info" "Found forge-production.env file. Consider copying relevant settings to .env"
    fi
}

# Run main function
main "$@"
