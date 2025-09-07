#!/bin/bash

# Bconnect Local macOS Environment Setup Script
# ================================================

set -e  # Exit on error

echo "🚀 Bconnect Local macOS Environment Setup & Fix Script"
echo "======================================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "Starting local macOS environment setup..."
echo ""

# Check if running on macOS
if [[ "$OSTYPE" != "darwin"* ]]; then
    echo -e "${RED}❌ This script is designed for macOS only${NC}"
    exit 1
fi

# 1. Check Homebrew
echo -e "${YELLOW}ℹ️  Checking Homebrew installation...${NC}"
if ! command -v brew &> /dev/null; then
    echo -e "${RED}❌ Homebrew not installed${NC}"
    echo "Installing Homebrew..."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
else
    echo -e "${GREEN}✅ Homebrew is installed${NC}"
fi

# 2. Check and Install Redis
echo -e "${YELLOW}ℹ️  Checking Redis installation...${NC}"
if ! brew list redis &> /dev/null; then
    echo "Installing Redis via Homebrew..."
    brew install redis
else
    echo -e "${GREEN}✅ Redis is already installed${NC}"
fi

# Start Redis using brew services
echo "Starting Redis service..."
brew services start redis

# Check if Redis is running
if redis-cli ping &> /dev/null; then
    echo -e "${GREEN}✅ Redis is running${NC}"
else
    echo -e "${RED}❌ Redis is not responding${NC}"
    echo "Trying to restart Redis..."
    brew services restart redis
    sleep 2
    if redis-cli ping &> /dev/null; then
        echo -e "${GREEN}✅ Redis is now running${NC}"
    else
        echo -e "${RED}❌ Failed to start Redis${NC}"
    fi
fi

# 3. Check PostgreSQL
echo -e "${YELLOW}ℹ️  Checking PostgreSQL...${NC}"
if ! brew list postgresql@15 &> /dev/null && ! brew list postgresql@14 &> /dev/null; then
    echo "Installing PostgreSQL via Homebrew..."
    brew install postgresql@15
    brew services start postgresql@15
else
    echo -e "${GREEN}✅ PostgreSQL is installed${NC}"
fi

# 4. Check PHP and required extensions
echo -e "${YELLOW}ℹ️  Checking PHP installation...${NC}"
if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ PHP not installed${NC}"
    echo "Please install PHP 8.2+ via Homebrew: brew install php@8.2"
else
    PHP_VERSION=$(php -v | head -n 1 | cut -d " " -f 2 | cut -c 1-3)
    echo -e "${GREEN}✅ PHP $PHP_VERSION is installed${NC}"
fi

# 5. Check Composer
echo -e "${YELLOW}ℹ️  Checking Composer...${NC}"
if ! command -v composer &> /dev/null; then
    echo -e "${RED}❌ Composer not installed${NC}"
    echo "Installing Composer..."
    brew install composer
else
    echo -e "${GREEN}✅ Composer is installed${NC}"
fi

# 6. Check Node.js and npm
echo -e "${YELLOW}ℹ️  Checking Node.js...${NC}"
if ! command -v node &> /dev/null; then
    echo -e "${RED}❌ Node.js not installed${NC}"
    echo "Installing Node.js via Homebrew..."
    brew install node
else
    NODE_VERSION=$(node -v)
    echo -e "${GREEN}✅ Node.js $NODE_VERSION is installed${NC}"
fi

# 7. Laravel Environment Setup
echo ""
echo -e "${YELLOW}📦 Setting up Laravel environment...${NC}"

# Check if .env exists
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        echo "Creating .env from .env.example..."
        cp .env.example .env
    else
        echo -e "${RED}❌ No .env or .env.example file found${NC}"
    fi
else
    echo -e "${GREEN}✅ .env file exists${NC}"
fi

# Install Composer dependencies
echo "Installing Composer dependencies..."
composer install --no-interaction --prefer-dist

# Install npm dependencies
echo "Installing npm dependencies..."
npm install

# Build assets
echo "Building assets..."
npm run build

# Generate application key if needed
if ! grep -q "^APP_KEY=base64:" .env; then
    echo "Generating application key..."
    php artisan key:generate
fi

# 8. Database Setup
echo ""
echo -e "${YELLOW}🗄️  Setting up database...${NC}"

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# 9. Storage Setup
echo ""
echo -e "${YELLOW}📁 Setting up storage...${NC}"

# Create storage link
if [ ! -L public/storage ]; then
    echo "Creating storage link..."
    php artisan storage:link
fi

# Set permissions for storage and cache
echo "Setting permissions..."
chmod -R 775 storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;

# 10. Queue Setup
echo ""
echo -e "${YELLOW}⚡ Setting up queues...${NC}"

# Clear and cache config
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache

# 11. Run Health Check
echo ""
echo -e "${YELLOW}🏥 Running health check...${NC}"
php fix_production_issues.php

echo ""
echo "======================================================="
echo -e "${GREEN}✅ Local macOS environment setup complete!${NC}"
echo ""
echo "To start the development server:"
echo "  php artisan serve"
echo ""
echo "To start Horizon (in a separate terminal):"
echo "  php artisan horizon"
echo ""
echo "To run tests:"
echo "  ./vendor/bin/pest"
echo ""
echo "Redis status:"
echo "  brew services list | grep redis"
echo ""
echo "======================================================="
