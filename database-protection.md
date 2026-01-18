# 🛡️ Database Protection Guide

## ⚠️ CRITICAL: Database Wiping Prevention

**NEVER run these commands in production or development without explicit backup:**

```bash
# ❌ DANGEROUS - Wipes entire database
# php artisan migrate fresh --seed --force
# php artisan db wipe --force  
# php artisan migrate reset --force

# ✅ SAFE - Regular migrations
php artisan migrate
php artisan migrate:rollback
php artisan migrate:status
```

## 🚨 What Caused Database Loss

The database was wiped by running `forge-real-data-only-fix.sh` which contains:
```bash
# php artisan migrate fresh --seed --force  # Line 35 - DANGEROUS!
```

This command:
1. **Drops all tables** (deletes all data)
2. **Re-runs all migrations** (recreates structure)
3. **Runs seeders** (populates with initial data)

## 🛡️ Prevention Measures Implemented

### 1. Gitignore Protection
Added to `.gitignore`:
- All `forge-*-fix*.sh` scripts
- All `test_*.php` files
- All `debug_*.php` files
- All deployment scripts

### 2. Safe Database Commands

#### ✅ Safe Commands (Use These)
```bash
# Check migration status
php artisan migrate:status

# Run new migrations only
php artisan migrate

# Rollback last batch
php artisan migrate:rollback

# Seed without wiping (if seeders are safe)
php artisan db:seed

# Clear caches (safe)
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

#### ❌ Dangerous Commands (Avoid)
```bash
# These WIPE your database:
# php artisan migrate fresh --seed --force
# php artisan db wipe --force
# php artisan migrate reset --force

# These might cause issues:
# php artisan migrate fresh --seed  # Still wipes data
# php artisan db wipe  # Still wipes data
```

### 3. Backup Before Any Major Changes

#### Create Database Backup
```bash
# SQLite (local development)
cp database/database.sqlite database/database.sqlite.backup

# PostgreSQL (production)
pg_dump your_database > backup_$(date +%Y%m%d_%H%M%S).sql
```

#### Restore Database Backup
```bash
# SQLite
cp database/database.sqlite.backup database/database.sqlite

# PostgreSQL
psql your_database < backup_20241212_143022.sql
```

## 🔧 Safe Development Workflow

### 1. Before Making Changes
```bash
# Always backup first
cp database/database.sqlite database/database.sqlite.backup

# Check current status
php artisan migrate:status
```

### 2. During Development
```bash
# Only run new migrations
php artisan migrate

# If you need to test seeders, create a test database
php artisan migrate --database=testing
php artisan db:seed --database=testing
```

### 3. After Making Changes
```bash
# Verify data still exists
php artisan tinker --execute="echo 'Ports: ' . App\Models\Port::count();"
php artisan tinker --execute="echo 'Carriers: ' . App\Models\ShippingCarrier::count();"
```

## 🚨 Emergency Recovery

If database gets wiped:

### 1. Check for Backups
```bash
ls -la database/*.backup
ls -la *.sql
```

### 2. Restore from Backup
```bash
# If you have a backup
cp database/database.sqlite.backup database/database.sqlite
```

### 3. Re-seed if No Backup
```bash
# This will restore basic data (ports, carriers, users)
php artisan db:seed
```

### 4. Verify Recovery
```bash
php artisan tinker --execute="
echo 'Ports: ' . App\Models\Port::count() . PHP_EOL;
echo 'Carriers: ' . App\Models\ShippingCarrier::count() . PHP_EOL;
echo 'Users: ' . App\Models\User::count() . PHP_EOL;
"
```

## 📋 Pre-Commit Checklist

Before committing changes:

- [ ] Database backup created
- [ ] No dangerous scripts in commit
- [ ] No `# migrate fresh` commands
- [ ] All migrations are additive (not destructive)
- [ ] Tested on copy of database first

## 🔍 Script Safety Audit

Before running any `.sh` script:

```bash
# Check what the script does
grep -n "migrate fresh|db wipe|migrate reset" script_name.sh

# If found, DON'T RUN without backup
```

## 📞 Emergency Contacts

If database gets wiped:
1. **STOP** - Don't run more commands
2. **CHECK** for backups
3. **RESTORE** from backup
4. **RESEED** if no backup available
5. **VERIFY** data integrity

---

**Remember: `# migrate fresh` = DATA LOSS. Always backup first!** 🛡️
