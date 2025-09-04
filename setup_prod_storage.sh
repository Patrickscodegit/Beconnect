#!/bin/bash

# Production Storage Setup Script  
# This configures the app to use DigitalOcean Spaces for production

echo "☁️ Setting up production storage configuration..."

# Production environment template
cat > .env.production << 'EOF'
# Production Storage Configuration - DigitalOcean Spaces
DOCUMENTS_DRIVER=spaces
LIVEWIRE_DISK=documents

# DigitalOcean Spaces Configuration
AWS_ACCESS_KEY_ID=your_spaces_key_here
AWS_SECRET_ACCESS_KEY=your_spaces_secret_here
AWS_DEFAULT_REGION=fra1
AWS_BUCKET=bconnect-documents
AWS_URL=https://bconnect-documents.fra1.digitaloceanspaces.com
AWS_ENDPOINT=https://fra1.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false

# Backup configuration (keep for compatibility)
SPACES_KEY=${AWS_ACCESS_KEY_ID}
SPACES_SECRET=${AWS_SECRET_ACCESS_KEY}
SPACES_REGION=${AWS_DEFAULT_REGION}
SPACES_BUCKET=${AWS_BUCKET}
SPACES_URL=${AWS_URL}
SPACES_ENDPOINT=${AWS_ENDPOINT}
SPACES_USE_PATH_STYLE_ENDPOINT=${AWS_USE_PATH_STYLE_ENDPOINT}

# File system defaults (production with Spaces)
FILESYSTEM_DISK=documents
EOF

echo "✅ Production storage configuration template created!"
echo ""
echo "📋 Next steps for production deployment:"
echo ""
echo "1. 🔑 Update DigitalOcean Spaces credentials:"
echo "   • Edit .env.production with your actual Spaces keys"
echo "   • Replace 'your_spaces_key_here' with real access key"
echo "   • Replace 'your_spaces_secret_here' with real secret key"
echo ""
echo "2. 🚀 Deploy to production:"
echo "   • Copy .env.production to .env on your production server"
echo "   • Run: php artisan config:cache"
echo "   • Run: php artisan storage:link (if using any public storage)"
echo ""
echo "3. 📁 Bucket configuration:"
echo "   • Bucket: bconnect-documents"
echo "   • Region: Frankfurt (fra1)" 
echo "   • Files will be stored with 'documents/' prefix"
echo ""
echo "🔄 Environment switching:"
echo "   • Dev: DOCUMENTS_DRIVER=local (uses storage/app/public/documents)"
echo "   • Prod: DOCUMENTS_DRIVER=spaces (uses DigitalOcean Spaces)"
echo ""
echo "⚠️  Remember to configure CORS in your DigitalOcean Spaces bucket!"
