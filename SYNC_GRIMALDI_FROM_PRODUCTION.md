# Sync Grimaldi Mappings from Production

This guide explains how to export Grimaldi mappings and tariffs from production and import them into your local environment.

## Prerequisites

- SSH access to production server
- Local Grimaldi carrier must exist (run `php artisan carriers:link-to-suppliers` first)

## Quick Start

### Option 1: Automated Script

```bash
./sync_grimaldi_from_production.sh
```

### Option 2: Manual Steps

#### Step 1: Export from Production

SSH into production and run the export command:

```bash
ssh forge@bconnect.64.226.120.45.nip.io
cd /home/forge/app.belgaco.be
php artisan grimaldi:export-from-production --output=storage/exports
```

This will create the following JSON files in `storage/exports/`:
- `grimaldi_mappings.json` - All CarrierArticleMapping records
- `grimaldi_tariffs.json` - All CarrierPurchaseTariff records
- `grimaldi_ports_reference.json` - Port reference data
- `grimaldi_category_groups_reference.json` - Category group reference data

#### Step 2: Download Export Files

From your local machine, download the export files:

```bash
mkdir -p storage/exports
scp forge@bconnect.64.226.120.45.nip.io:/home/forge/app.belgaco.be/storage/exports/*.json ./storage/exports/
```

#### Step 3: Import Locally

Run the import command:

```bash
# Dry run first (recommended)
php artisan grimaldi:import-from-production --input=storage/exports --dry-run

# Actual import
php artisan grimaldi:import-from-production --input=storage/exports
```

## What Gets Imported

1. **CarrierArticleMapping** records:
   - Mapped by `carrier_id` + `article_id` (using article codes)
   - Port IDs mapped from production port codes to local port IDs
   - Category group IDs mapped from production codes to local IDs

2. **CarrierPurchaseTariff** records:
   - Linked to imported mappings
   - All tariff fields (amounts, units, dates, etc.)

## Troubleshooting

### "Grimaldi carrier not found"
Run: `php artisan carriers:link-to-suppliers`

### "Article not found" errors
Some articles may not exist locally. The import will skip these and continue.

### Port ID mapping issues
The import uses article `pod_code` to map ports. If articles don't have `pod_code` set, ports may not map correctly.

## Verification

After import, verify the data:

```bash
php artisan tinker
>>> \App\Models\CarrierArticleMapping::whereHas('carrier', fn($q) => $q->where('code', 'GRIMALDI'))->count()
>>> \App\Models\CarrierPurchaseTariff::whereHas('carrierArticleMapping.carrier', fn($q) => $q->where('code', 'GRIMALDI'))->count()
```

Then check the Grimaldi Purchase Rates Overview page:
http://127.0.0.1:8000/admin/grimaldi-purchase-rates-overview
