#!/bin/bash

# Forge PostgreSQL + Dependencies Setup Script
# Run this via Forge Commands section

echo "🐘 Setting up PostgreSQL and System Dependencies..."

# Update system
sudo apt update

# Install PostgreSQL
echo "Installing PostgreSQL..."
sudo apt install -y postgresql postgresql-contrib postgresql-client

# Start and enable PostgreSQL
sudo systemctl start postgresql
sudo systemctl enable postgresql

# Create database and user
echo "Creating database and user..."
sudo -u postgres psql << EOF
CREATE DATABASE forge;
CREATE USER forge WITH ENCRYPTED PASSWORD 'jfklsfjqmfjqmlfj';
GRANT ALL PRIVILEGES ON DATABASE forge TO forge;
ALTER USER forge CREATEDB;
\q
EOF

# Configure PostgreSQL for local connections
echo "Configuring PostgreSQL authentication..."
PG_VERSION=$(sudo -u postgres psql -t -c "SELECT version();" | grep -oP '\d+\.\d+' | head -1)
PG_CONFIG_PATH="/etc/postgresql/${PG_VERSION}/main"

# Update pg_hba.conf for local connections
sudo sed -i "s/local   all             all                                     peer/local   all             all                                     md5/" ${PG_CONFIG_PATH}/pg_hba.conf

# Restart PostgreSQL
sudo systemctl restart postgresql

# Test connection
echo "Testing PostgreSQL connection..."
PGPASSWORD='jfklsfjqmfjqmlfj' psql -h 127.0.0.1 -U forge -d forge -c "SELECT 'PostgreSQL is working!' as status;"

# Install system dependencies for document processing
echo "Installing OCR and PDF processing tools..."
sudo apt install -y \
    tesseract-ocr \
    tesseract-ocr-eng \
    ghostscript \
    poppler-utils \
    imagemagick \
    redis-server

# Configure Redis
sudo systemctl start redis-server
sudo systemctl enable redis-server

# Test Redis
redis-cli ping

# Verify installations
echo ""
echo "✅ Installation Verification:"
echo "PostgreSQL: $(psql --version)"
echo "Tesseract: $(tesseract --version | head -1)"
echo "Ghostscript: $(gs --version)"
echo "ImageMagick: $(identify -version | head -1)"
echo "Redis: $(redis-cli --version)"

echo ""
echo "🎉 All dependencies installed successfully!"
echo ""
echo "📋 Your environment is ready for:"
echo "- PostgreSQL database: forge"
echo "- OCR processing with Tesseract"
echo "- PDF processing with Ghostscript/Poppler"
echo "- Image processing with ImageMagick"
echo "- Queue processing with Redis"

echo ""
echo "🔄 Next Steps:"
echo "1. Deploy your application"
echo "2. Run: php artisan migrate --force"
echo "3. Run: php artisan db:seed --force"
echo "4. Set up Laravel Horizon"
