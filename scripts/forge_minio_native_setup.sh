#!/bin/bash

# Native MinIO Production Setup for Laravel Forge
# This script installs MinIO as a systemd service (no Docker required)

echo "🚀 Installing MinIO natively for production deployment..."

# Step 1: Create directories and user
sudo mkdir -p /opt/minio /var/minio/data
sudo useradd -r -s /bin/false minio 2>/dev/null || echo "MinIO user already exists"

# Step 2: Download and install MinIO binary
echo "Downloading MinIO binary..."
curl -L -o /opt/minio/minio https://dl.min.io/server/minio/release/linux-amd64/minio
sudo chmod +x /opt/minio/minio
sudo chown -R minio:minio /opt/minio /var/minio

# Step 3: Create environment file with production credentials
echo "Creating MinIO environment configuration..."
# Use environment variables or prompt for credentials to avoid hard-coding
MINIO_ROOT_USER="${MINIO_ROOT_USER:-[YOUR_MINIO_ACCESS_KEY]}"
MINIO_ROOT_PASSWORD="${MINIO_ROOT_PASSWORD:-[YOUR_MINIO_SECRET_KEY]}"

if [ "$MINIO_ROOT_USER" = "[YOUR_MINIO_ACCESS_KEY]" ]; then
  read -p "Enter MinIO access key: " MINIO_ROOT_USER
fi

if [ "$MINIO_ROOT_PASSWORD" = "[YOUR_MINIO_SECRET_KEY]" ]; then
  read -s -p "Enter MinIO secret key: " MINIO_ROOT_PASSWORD
  echo
fi

echo "MINIO_ROOT_USER=$MINIO_ROOT_USER" | sudo tee /etc/minio.env
echo "MINIO_ROOT_PASSWORD=$MINIO_ROOT_PASSWORD" | sudo tee -a /etc/minio.env

# Step 4: Create systemd service
echo "Creating MinIO systemd service..."
cat <<'UNIT' | sudo tee /etc/systemd/system/minio.service
[Unit]
Description=MinIO Object Storage for Bconnect
After=network-online.target
Wants=network-online.target

[Service]
User=minio
Group=minio
EnvironmentFile=/etc/minio.env
ExecStart=/opt/minio/minio server /var/minio/data --console-address ":9001" --address ":9000"
Restart=on-failure
RestartSec=5
LimitNOFILE=65536
TimeoutStopSec=infinity
SendSIGKILL=no

[Install]
WantedBy=multi-user.target
UNIT

# Step 5: Start and enable MinIO service
echo "Starting MinIO service..."
sudo systemctl daemon-reload
sudo systemctl enable minio
sudo systemctl start minio

# Wait for MinIO to start
sleep 5

# Step 6: Verify MinIO is running
echo "Verifying MinIO installation..."
sudo systemctl status minio --no-pager
curl -I http://localhost:9000/minio/health/live || echo "MinIO health check failed"

# Step 7: Install MinIO client
echo "Installing MinIO client..."
curl -L -o /usr/local/bin/mc https://dl.min.io/client/mc/release/linux-amd64/mc
sudo chmod +x /usr/local/bin/mc

# Step 8: Configure client and create buckets
echo "Creating Bconnect storage buckets..."
mc alias set local http://localhost:9000 $MINIO_ROOT_USER $MINIO_ROOT_PASSWORD

# Create production buckets for freight document processing
mc mb local/bconnect 2>/dev/null || echo "Bucket 'bconnect' already exists"
mc mb local/bconnect-processed 2>/dev/null || echo "Bucket 'bconnect-processed' already exists"
mc mb local/bconnect-archive 2>/dev/null || echo "Bucket 'bconnect-archive' already exists"

# Verify buckets
echo "Verifying storage buckets:"
mc ls local/

echo ""
echo "✅ MinIO Native Installation Complete!"
echo ""
echo "📋 MinIO Configuration:"
echo "- API Endpoint: http://localhost:9000"
echo "- Console: http://localhost:9001"
echo "- Access Key: $MINIO_ROOT_USER"
echo "- Secret Key: [HIDDEN]"
echo ""
echo "🎯 Storage Buckets Created:"
echo "- bconnect (main document storage)"
echo "- bconnect-processed (processed documents)"
echo "- bconnect-archive (archived/failed documents)"
echo ""
echo "🔧 Service Management:"
echo "- Status: sudo systemctl status minio"
echo "- Restart: sudo systemctl restart minio"
echo "- Logs: sudo journalctl -u minio -f"
echo ""
echo "📦 Update your Forge Environment Variables:"
echo "AWS_ACCESS_KEY_ID=$MINIO_ROOT_USER"
echo "AWS_SECRET_ACCESS_KEY=[USE_THE_SECRET_KEY_YOU_ENTERED]"
echo "AWS_ENDPOINT=http://localhost:9000"
echo "AWS_BUCKET=bconnect"
echo "FILESYSTEM_DISK=s3"
echo ""
echo "🚀 Your production-ready Bconnect freight-forwarding system is ready!"
