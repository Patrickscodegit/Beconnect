# 🚢 Ports Database Management Guide

## 📋 Overview

The ports database stores all shipping ports (POLs and PODs) used in the system. This guide explains how to manage, enrich, and protect the ports database.

## ✅ Current Status

- **Total Ports**: 68 ports (standard ports + ports from article cache)
- **Grimaldi PODs**: 11 ports (ABJ, CKY, COO, DKR, DLA, FNA, LBV, LFW, LOS, PNR, NKC)
- **Source**: `PortSeeder` + `ports:sync-from-articles` command

## 🔍 How Ports Were Emptied

The ports table was likely emptied by one of these scenarios:

1. **Running `migrate fresh`** - Drops all tables and re-runs migrations
2. **Running `migrate reset`** - Rolls back all migrations (drops tables)
3. **Running `db wipe`** - Wipes the database
4. **Migration rollback** - If someone rolled back the `create_ports_table` migration

**Root Cause**: These commands are dangerous and should **NEVER** be run without a backup, especially in production.

## 🛡️ Prevention Measures

### 1. Use Safe Migration Commands

```bash
# ✅ SAFE - Only runs new migrations
php artisan migrate

# ✅ SAFE - Check migration status
php artisan migrate:status

# ❌ DANGEROUS - Never use these without backup
# php artisan migrate fresh --seed
# php artisan migrate reset
# php artisan db wipe
```

### 2. Always Backup Before Major Changes

```bash
# SQLite (local)
cp database/database.sqlite database/database.sqlite.backup.$(date +%Y%m%d_%H%M%S)

# PostgreSQL (production)
pg_dump your_database > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 3. Use Safe Deployment Script

Use the `scripts/safe-deploy.sh` script which:
- Creates a backup before deployment
- Only runs new migrations (no data loss)
- Verifies database integrity after deployment

```bash
bash scripts/safe-deploy.sh
```

### 4. Automatic Port Seeding

The `DatabaseSeeder` automatically includes `PortSeeder`, so ports are seeded when running:
```bash
php artisan db:seed
```

However, **ports won't be seeded automatically** if:
- You run `migrate fresh` (drops everything first)
- You manually drop the ports table
- The seeder isn't called in `DatabaseSeeder`

## 🔄 Enriching Ports Database

### Method 1: Sync Ports from Article Cache (Recommended)

This command extracts ports from article cache data (PODs from articles):

```bash
# Dry run (see what would be created)
php artisan ports:sync-from-articles --dry-run

# Actually sync ports
php artisan ports:sync-from-articles

# Force update existing ports
php artisan ports:sync-from-articles --force
```

**What it does:**
- Extracts port codes and names from article `pod_code` and `pod` fields
- Determines country and region automatically
- Filters out invalid port codes (HULL, NMT, etc.)
- Creates ports that don't exist
- Updates existing ports if `--force` is used

### Method 2: Update PortSeeder

Add ports directly to `database/seeders/PortSeeder.php`:

```php
['name' => 'Port Name', 'code' => 'CODE', 'country' => 'Country', 'region' => 'Region'],
```

Then run:
```bash
php artisan db:seed --class=PortSeeder
```

### Method 3: Manual Creation

```bash
php artisan tinker
>>> Port::create(['code' => 'CODE', 'name' => 'Port Name', 'country' => 'Country', 'region' => 'Region']);
```

## 🔧 Recovery After Ports Are Emptied

If ports table gets emptied, follow these steps:

### Step 1: Restore from Backup (If Available)

```bash
# SQLite
cp database/database.sqlite.backup database/database.sqlite

# PostgreSQL
psql your_database < backup_file.sql
```

### Step 2: Re-seed Ports

```bash
# Seed standard ports
php artisan db:seed --class=PortSeeder

# Sync ports from article cache
php artisan ports:sync-from-articles
```

### Step 3: Verify Recovery

```bash
php artisan tinker --execute="echo 'Ports: ' . App\Models\Port::count();"
```

## 📊 Current Port Data Sources

1. **PortSeeder** - Contains 70+ standard ports (POLs and PODs)
2. **Article Cache** - Extracts ports from article PODs via `ports:sync-from-articles`
3. **Manual Creation** - Ports created via Filament admin or tinker

## 🔄 Maintaining Port Data

### Regular Maintenance

Run periodically to sync new ports from articles:

```bash
# Check for new ports in articles
php artisan ports:sync-from-articles --dry-run

# Sync if new ports found
php artisan ports:sync-from-articles
```

### Adding New Ports

When adding a new carrier or route:

1. **Check if port exists:**
   ```bash
   php artisan tinker --execute="echo App\Models\Port::where('code', 'CODE')->exists() ? 'EXISTS' : 'NOT FOUND';"
   ```

2. **If not found, add to PortSeeder or sync from articles:**
   ```bash
   php artisan ports:sync-from-articles
   ```

## ⚠️ Important Notes

1. **Never run `migrate fresh` in production** - It wipes all data
2. **Always backup before migrations** - Use the safe-deploy script
3. **PortSeeder uses `updateOrCreate`** - Won't duplicate ports, safe to run multiple times
4. **`ports:sync-from-articles` filters invalid codes** - HULL, NMT, etc. are automatically skipped
5. **Port codes must be unique** - The migration enforces this with a unique index

## 📝 Files Related to Ports

- `database/migrations/2025_10_06_175825_create_ports_table.php` - Ports table migration
- `database/seeders/PortSeeder.php` - Standard ports seeder
- `app/Console/Commands/SyncPortsFromArticles.php` - Sync command
- `app/Models/Port.php` - Port model
- `scripts/safe-deploy.sh` - Safe deployment script
- `database-protection.md` - General database protection guide

## 🎯 Best Practices

1. **Use `updateOrCreate`** - Prevents duplicates
2. **Always verify** - Check port count after seeding
3. **Document changes** - Update this guide when adding new ports
4. **Test first** - Use `--dry-run` before actual sync
5. **Backup regularly** - Especially before deployments

## 🚨 Emergency Contacts

If ports database gets corrupted or emptied:

1. **STOP** - Don't run more commands
2. **CHECK** - Look for backups
3. **RESTORE** - Use backup if available
4. **RE-SEED** - Run seeders if no backup
5. **VERIFY** - Check port count matches expected

---

**Last Updated**: 2026-01-10
**Maintained By**: Development Team
