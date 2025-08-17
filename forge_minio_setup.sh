#!/bin/bash

# Forge MinIO Production Setup Script
# Run this via Forge Commands section

echo "🚀 Setting up MinIO for Bconnect Production..."

# Update system packages
sudo apt update

# Install Docker if not present
if ! command -v docker &> /dev/null; then
    echo "Installing Docker..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sudo sh get-docker.sh
    sudo usermod -aG docker forge
    rm get-docker.sh
fi

# Create MinIO data directory
sudo mkdir -p /opt/minio/data
sudo chown forge:forge /opt/minio/data

# Create MinIO configuration directory
sudo mkdir -p /opt/minio/config
sudo chown forge:forge /opt/minio/config

# Generate secure credentials
MINIO_ROOT_USER="bconnect_admin"
MINIO_ROOT_PASSWORD=$(openssl rand -base64 32)

echo "Generated MinIO credentials:"
echo "User: ${MINIO_ROOT_USER}"
echo "Password: ${MINIO_ROOT_PASSWORD}"

# Create MinIO Docker Compose file
cat > /opt/minio/docker-compose.yml << EOF
version: '3.8'

services:
  minio:
    image: quay.io/minio/minio:latest
    container_name: minio
    restart: unless-stopped
    ports:
      - "9000:9000"
      - "9001:9001"
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
    volumes:
      - /opt/minio/data:/data
    command: server /data --console-address ":9001"
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]
      interval: 30s
      timeout: 20s
      retries: 3

networks:
  default:
    name: minio_network
EOF

# Start MinIO
cd /opt/minio
sudo docker-compose up -d

# Wait for MinIO to start
echo "Waiting for MinIO to start..."
sleep 10

# Install MinIO Client
curl https://dl.min.io/client/mc/release/linux-amd64/mc -o mc
chmod +x mc
sudo mv mc /usr/local/bin/

# Configure MinIO client
mc alias set local http://localhost:9000 ${MINIO_ROOT_USER} ${MINIO_ROOT_PASSWORD}

# Create buckets
echo "Creating Bconnect buckets..."
mc mb local/bconnect
mc mb local/bconnect-processed
mc mb local/bconnect-archive

# Set bucket policies (public read for processed results)
mc anonymous set download local/bconnect-processed

# Create access key for Laravel
ACCESS_KEY="bconnect_app"
SECRET_KEY=$(openssl rand -base64 24)

mc admin user add local ${ACCESS_KEY} ${SECRET_KEY}

# Create policy for Laravel app
cat > /tmp/bconnect-policy.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:DeleteObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::bconnect/*",
        "arn:aws:s3:::bconnect-processed/*",
        "arn:aws:s3:::bconnect-archive/*"
      ]
    }
  ]
}
EOF

mc admin policy create local bconnect-app-policy /tmp/bconnect-policy.json
mc admin policy attach local bconnect-app-policy --user ${ACCESS_KEY}

echo ""
echo "✅ MinIO Setup Complete!"
echo ""
echo "📋 Update your Forge Environment Variables:"
echo "FILESYSTEM_DISK=s3"
echo "AWS_ACCESS_KEY_ID=${ACCESS_KEY}"
echo "AWS_SECRET_ACCESS_KEY=${SECRET_KEY}"
echo "AWS_DEFAULT_REGION=us-east-1"
echo "AWS_BUCKET=bconnect"
echo "AWS_ENDPOINT=http://127.0.0.1:9000"
echo "AWS_USE_PATH_STYLE_ENDPOINT=true"
echo ""
echo "🌐 MinIO Console: http://YOUR_SERVER_IP:9001"
echo "👤 Admin User: ${MINIO_ROOT_USER}"
echo "🔑 Admin Password: ${MINIO_ROOT_PASSWORD}"
echo ""
echo "🔄 Next Steps:"
echo "1. Update environment variables in Forge"
echo "2. Redeploy your application"
echo "3. Test file uploads"

# Clean up
rm /tmp/bconnect-policy.json
