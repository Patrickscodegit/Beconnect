#!/bin/bash

# PostgreSQL and Dependencies Setup Script for Laravel Forge
# This script installs PostgreSQL, creates the database, and installs system dependencies

echo "Starting PostgreSQL and system dependencies installation..."

# Update system packages
sudo apt update

# Install PostgreSQL
echo "Installing PostgreSQL..."
sudo apt install -y postgresql postgresql-contrib

# Start and enable PostgreSQL
sudo systemctl start postgresql
sudo systemctl enable postgresql

# Create database and user
echo "Setting up PostgreSQL database..."
sudo -u postgres psql <<EOF
CREATE DATABASE forge;
CREATE USER forge WITH ENCRYPTED PASSWORD 'jfklsfjqmfjqmlfj';
GRANT ALL PRIVILEGES ON DATABASE forge TO forge;
\q
EOF

# Update PostgreSQL authentication
sudo sed -i "s/local   all             all                                     peer/local   all             all                                     md5/" /etc/postgresql/*/main/pg_hba.conf
sudo systemctl restart postgresql

# Install system dependencies for document processing
echo "Installing OCR and PDF processing tools..."
sudo apt install -y \
    tesseract-ocr \
    tesseract-ocr-eng \
    ghostscript \
    poppler-utils \
    imagemagick \
    postgresql-client

# Verify installations
echo "Verifying installations..."
tesseract --version
gs --version
pdftotext -v

# Test PostgreSQL connection
echo "Testing PostgreSQL connection..."
PGPASSWORD='jfklsfjqmfjqmlfj' psql -h 127.0.0.1 -U forge -d forge -c "SELECT version();"

echo "PostgreSQL and system dependencies installation complete!"
