#!/bin/bash

# 🛡️ DATABASE BACKUP SCRIPT
# Creates timestamped backups of your database

set -e  # Exit on any error

BACKUP_DIR="database/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "🛡️ Creating Database Backup..."

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Backup SQLite database
if [ -f "database/database.sqlite" ]; then
    BACKUP_FILE="$BACKUP_DIR/database_$TIMESTAMP.sqlite"
    cp database/database.sqlite "$BACKUP_FILE"
    echo "✅ SQLite backup created: $BACKUP_FILE"
    
    # Get file size
    SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo "📊 Backup size: $SIZE"
else
    echo "⚠️  No SQLite database found"
fi

# List recent backups
echo ""
echo "📋 Recent backups:"
ls -la "$BACKUP_DIR"/database_*.sqlite 2>/dev/null | tail -5 || echo "No backups found"

echo ""
echo "🔄 To restore a backup:"
echo "   cp $BACKUP_DIR/database_YYYYMMDD_HHMMSS.sqlite database/database.sqlite"
