#!/bin/bash
# Fix robaws_customers_cache ownership on DigitalOcean managed PostgreSQL (staging).
# Run locally: bash scripts/fix_staging_table_ownership.sh
# You will be prompted for the DigitalOcean doadmin password.

set -e
echo "Fix staging PostgreSQL table ownership"
echo "You need the 'doadmin' password from DigitalOcean dashboard."
read -s -p "doadmin password: " DOADMIN_PW
echo

ssh forge@46.101.70.55 'cd beconnect-rn6k77zh.on-forge.com/current && \
  export $(grep -E "^DB_" .env 2>/dev/null | grep -v PASSWORD | xargs) && \
  command -v psql >/dev/null || sudo apt-get install -y postgresql-client && \
  PGPASSWORD="'"$DOADMIN_PW"'" psql "host=$DB_HOST port=$DB_PORT user=doadmin dbname=$DB_DATABASE sslmode=require" -c "ALTER TABLE robaws_customers_cache OWNER TO \"$DB_USERNAME\";"'

echo "Done. Run 'ssh forge@46.101.70.55 \"cd beconnect-rn6k77zh.on-forge.com/current && php artisan migrate --force\"' to complete migrations."
