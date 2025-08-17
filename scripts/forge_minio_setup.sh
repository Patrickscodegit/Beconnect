#!/bin/bash

# MinIO Setup Script for Laravel Forge
# This script installs MinIO for S3-compatible object storage

echo "Starting MinIO installation..."

# Install Docker if not present
if ! command -v docker &> /dev/null; then
    echo "Installing Docker..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sudo sh get-docker.sh
    sudo usermod -aG docker forge
    rm get-docker.sh
fi

# Create MinIO directories
mkdir -p ~/minio/data
mkdir -p ~/minio/config

# Generate secure credentials
MINIO_ACCESS_KEY=$(openssl rand -hex 16)
MINIO_SECRET_KEY=$(openssl rand -hex 32)

echo "MinIO Credentials Generated:"
echo "Access Key: $MINIO_ACCESS_KEY"
echo "Secret Key: $MINIO_SECRET_KEY"
echo ""
echo "Add these to your Forge environment variables:"
echo "AWS_ACCESS_KEY_ID=$MINIO_ACCESS_KEY"
echo "AWS_SECRET_ACCESS_KEY=$MINIO_SECRET_KEY"
echo "AWS_ENDPOINT=http://localhost:9000"

# Create MinIO docker-compose file
cat > ~/minio/docker-compose.yml <<EOF
version: '3.8'

services:
  minio:
    image: quay.io/minio/minio:latest
    container_name: minio
    ports:
      - "9000:9000"
      - "9001:9001"
    volumes:
      - ./data:/data
      - ./config:/root/.minio
    environment:
      MINIO_ROOT_USER: $MINIO_ACCESS_KEY
      MINIO_ROOT_PASSWORD: $MINIO_SECRET_KEY
    command: server /data --console-address ":9001"
    restart: unless-stopped

networks:
  default:
    name: minio-network
EOF

# Start MinIO
cd ~/minio
docker compose up -d

# Wait for MinIO to start
sleep 10

# Install MinIO client
wget https://dl.min.io/client/mc/release/linux-amd64/mc
chmod +x mc
sudo mv mc /usr/local/bin/

# Configure MinIO client
mc alias set local http://localhost:9000 $MINIO_ACCESS_KEY $MINIO_SECRET_KEY

# Create buckets
mc mb local/bconnect
mc mb local/bconnect-processed
mc mb local/bconnect-archive

# Set bucket policies
mc anonymous set download local/bconnect
mc anonymous set download local/bconnect-processed

echo "MinIO installation complete!"
echo "MinIO Console: http://localhost:9001"
echo "Login with the credentials shown above"
