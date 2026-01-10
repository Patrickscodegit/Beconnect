#!/bin/bash

# Script to export Grimaldi data from production and import locally
# Usage: ./sync_grimaldi_from_production.sh

set -e

echo "=== Grimaldi Production Sync ==="
echo ""

# Production server details
PROD_SERVER="forge@bconnect.64.226.120.45.nip.io"
PROD_PATH="/home/forge/app.belgaco.be"
EXPORT_DIR="storage/exports"

echo "Step 1: Exporting data from production..."
echo "SSH into production and running export command..."
ssh $PROD_SERVER "cd $PROD_PATH && php artisan grimaldi:export-from-production --output=$EXPORT_DIR"

echo ""
echo "Step 2: Downloading export files..."
mkdir -p storage/exports
scp $PROD_SERVER:$PROD_PATH/$EXPORT_DIR/*.json ./storage/exports/

echo ""
echo "Step 3: Importing data locally..."
php artisan grimaldi:import-from-production --input=storage/exports

echo ""
echo "✅ Sync completed!"
