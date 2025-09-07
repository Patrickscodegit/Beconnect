#!/bin/bash

# File Attachment Fix Deployment Script
# Fixes the file attachment issue where absolute paths from Storage::disk()->path()
# weren't being handled properly in RobawsApiClient::getFileContent()

echo "🚀 Deploying File Attachment Fix"
echo "================================="
echo

echo "📋 Changes being deployed:"
echo "- Enhanced RobawsApiClient::getFileContent() method"
echo "- Improved absolute path handling for file retrieval"
echo "- Better path normalization for local/production differences"
echo "- Enhanced logging for debugging file access attempts"
echo

echo "🔍 Current file attachment status check:"
php artisan tinker --execute="
echo 'Checking recent IntakeFile records...';
\$files = \App\Models\IntakeFile::latest()->take(3)->get(['id', 'filename', 'storage_path', 'storage_disk']);
foreach (\$files as \$file) {
    echo \"File ID: {\$file->id}, Path: {\$file->storage_path}, Exists: \" . (\Storage::disk(\$file->storage_disk)->exists(\$file->storage_path) ? 'YES' : 'NO') . \"\n\";
}
"

echo
echo "🎯 Testing enhanced file content retrieval:"
php artisan test:file-content

echo
echo "✅ File attachment fix deployment complete!"
echo
echo "📝 Next steps:"
echo "1. Monitor production logs for file attachment attempts"
echo "2. Check that 'File not found' errors are resolved"
echo "3. Verify files are successfully attached to Robaws offers"
echo
echo "🔧 If issues persist, check logs at:"
echo "   - storage/logs/laravel.log"  
echo "   - Look for 'Attempting to retrieve file' and 'File found' messages"
echo
