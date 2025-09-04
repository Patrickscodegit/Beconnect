#!/bin/bash

# Development Storage Setup Script
# This configures the app to use local storage for development

echo "🔧 Setting up development storage configuration..."

# Create storage directories
mkdir -p storage/app/public/documents
mkdir -p storage/app/public/livewire-tmp

# Set environment variables for local development
cat >> .env << 'EOF'

# Development Storage Configuration
DOCUMENTS_DRIVER=local
LIVEWIRE_DISK=documents

# File system defaults (local development)
FILESYSTEM_DISK=public

# Local file permissions (if needed)
# SESSION_DRIVER=file
EOF

echo "✅ Development storage configuration complete!"
echo ""
echo "📁 Local directories created:"
echo "   • storage/app/public/documents"
echo "   • storage/app/public/livewire-tmp"
echo ""
echo "🔧 Environment variables added to .env:"
echo "   • DOCUMENTS_DRIVER=local"
echo "   • LIVEWIRE_DISK=documents"
echo ""
echo "🚀 You can now run the development server with:"
echo "   php artisan serve"
echo ""
echo "📂 Files will be stored locally in:"
echo "   storage/app/public/documents/"
echo "   (accessible via /storage/documents URLs)"
