#!/bin/bash
# Fix PostgreSQL table ownership so migrations can ALTER tables.
# Run via Forge Commands (runs in app context, may have passwordless sudo for postgres).
# Usage: bash scripts/forge_fix_table_ownership.sh [table_name]
#
# If no table given, fixes robaws_customers_cache (common culprit).

set -e
cd "$(dirname "$0")/.." || exit 1

# Load DB config from .env
if [ -f .env ]; then
  export $(grep -v '^#' .env | grep -E '^DB_' | xargs)
fi

DB_USER="${DB_USERNAME:-forge}"
DB_NAME="${DB_DATABASE:-forge}"
TABLE="${1:-robaws_customers_cache}"

echo "Fixing ownership for table: $TABLE"
echo "Database: $DB_NAME, Target owner: $DB_USER"

# Run as postgres superuser (Forge Commands often have passwordless sudo for this)
sudo -u postgres psql -d "$DB_NAME" -c "ALTER TABLE \"$TABLE\" OWNER TO \"$DB_USER\";"

echo "Done. Table $TABLE is now owned by $DB_USER."
echo "You can re-run deployment or: php artisan migrate --force"
