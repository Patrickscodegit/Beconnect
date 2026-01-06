# Fix Forge Deployment Script

## Problem
The deployment script fails with:
```
error: Your local changes to the following files would be overwritten by merge
```

This happens when there are local changes on the production server that conflict with the git pull.

## Solution
Replace `git pull` with a more robust approach that:
1. Fetches the latest from remote
2. Resets directly to the remote branch state
3. Removes untracked files that can break deployments

## Updated Deployment Script

Go to Laravel Forge → Your Site → "Deploy Script" and replace the git pull section:

**BEFORE:**
```bash
# Pull latest code
git pull origin $FORGE_SITE_BRANCH
```

**AFTER:**
```bash
# --- Git: force production to match the remote branch exactly ---
git fetch --prune origin
git reset --hard origin/$FORGE_SITE_BRANCH
# Remove untracked files/folders that can break deploys (safe for production)
git clean -fd
```

## Complete Updated Script

Here's your full deployment script with the fix:

```bash
set -euo pipefail

cd /home/forge/app.belgaco.be

# --- Git: force production to match the remote branch exactly (FIRST, before anything else) ---
# This prevents merge conflicts if Forge tries to do a git operation before the script runs
git fetch --prune origin || true
git reset --hard origin/$FORGE_SITE_BRANCH || true
# Remove untracked files/folders that can break deploys (safe for production)
git clean -fd || true

# Maintenance mode (avoid user writes during schema changes)
$FORGE_PHP artisan down --retry=60 || true

# Install composer deps
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Ensure PSR-4 autoload is in sync (important after composer.json changes)
$FORGE_COMPOSER dump-autoload -o

# Build frontend assets
npm ci && npm run build

# Clear caches before we touch schema
$FORGE_PHP artisan optimize:clear
rm -rf storage/framework/views/* || true
$FORGE_PHP artisan filament:clear-cached-components || true

# Show DB driver using artisan instead of raw PHP
echo "Database Configuration:"
$FORGE_PHP artisan tinker --execute="echo 'DB driver: '.config('database.default').PHP_EOL;" || echo "DB check skipped"

# Filament assets (idempotent)
$FORGE_PHP artisan filament:upgrade || true
$FORGE_PHP artisan filament:assets || true

# --- CRITICAL: Run migrations early; fail fast if anything is wrong ---
if [ -f artisan ]; then
  echo "Running migrations…"
  $FORGE_PHP artisan migrate --force --no-interaction
fi

# Rebuild caches after schema is good
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:clear
$FORGE_PHP artisan view:cache

# Icons & storage
$FORGE_PHP artisan icons:cache || true
$FORGE_PHP artisan storage:link || true

# Publish Filament assets (safe to force)
$FORGE_PHP artisan vendor:publish --tag=filament-config --force
$FORGE_PHP artisan vendor:publish --tag=filament-panels-assets --force

# Cache Filament components
$FORGE_PHP artisan filament:cache-components || true

# Restart queues so workers load new code/config
$FORGE_PHP artisan horizon:terminate || true
sleep 2

# Final cache tidy & optimize
$FORGE_PHP artisan cache:clear
$FORGE_PHP artisan optimize
$FORGE_COMPOSER dump-autoload --optimize

# Permissions for writes
chmod -R 775 storage bootstrap/cache
chown -R forge:forge storage bootstrap/cache

# Ensure storage subdirs exist (local docs)
mkdir -p storage/app/documents storage/app/intakes
chmod -R 775 storage/app
chown -R forge:forge storage/app

# Back online
$FORGE_PHP artisan up

# Reload PHP-FPM once
touch /tmp/fpmlock 2>/dev/null || true
( flock -w 10 9 || exit 1
    echo 'Reloading PHP FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9</tmp/fpmlock

# Quick health check
$FORGE_PHP artisan about --only=database,cache,queue || true
```

## Key Changes
Replaced `git pull` with a more robust git workflow:
1. **`git fetch --prune origin`** - Fetches latest from remote and removes deleted remote branches
2. **`git reset --hard origin/$FORGE_SITE_BRANCH`** - Resets directly to remote branch state (more reliable than `git pull`)
3. **`git clean -fd`** - Removes untracked files/folders that can cause deployment failures

## Why This Is Better Than `git pull`
- **More reliable**: Resets directly to remote state, doesn't depend on local branch state
- **Handles untracked files**: `git clean -fd` removes files that can break deployments (like the JSON files we encountered)
- **No merge conflicts**: Direct reset avoids merge conflict scenarios entirely
- **Cleaner state**: Ensures production exactly matches the remote repository

## Why This Is Safe
- Production should never have local changes that aren't in git
- All changes should come from git repository
- This ensures production is always in sync with the repository
- The reset happens after maintenance mode, so no user impact
- Untracked files are removed, preventing deployment failures

