 # Staging Setup Notes
 
## Environment
- App URL: `https://staging.app.belgaco.be`
 - Server user: `forge`
 - App path: `/home/forge/beconnect-rn6k77zh.on-forge.com/current`
 
## SSH Access
- Staging:
  - `ssh forge@46.101.70.55`
  - App path: `/home/forge/beconnect-rn6k77zh.on-forge.com/current`
- Production:
  - `ssh forge@bconnect.64.226.120.45.nip.io`
  - App path: `/home/forge/app.belgaco.be`
- Add your public key in Forge:
  - Server Management -> SSH Keys
 
 ## Database
 - Staging is PostgreSQL.
 - Ensure the staging DB user has privileges on the `public` schema.
 - If migrations fail with permission errors, grant privileges using a database admin.
 
 ## Spaces (Staging Bucket)
 - Create a separate staging bucket (example: `bconnect-staging-documents`) in `fra1`.
 - Use Limited Access with Read/Write for the staging bucket.
 - Set both `DO_SPACES_*` and `AWS_*` to match the staging bucket.
 
 ## Horizon
 - In Forge -> Processes, add a Custom background process:
   - Name: `horizon`
   - Command: `php artisan horizon`
   - Directory: `/home/forge/beconnect-rn6k77zh.on-forge.com/current`
   - User: `forge`
 - Verify:
   - `php artisan horizon:status`
 
 ## Verification Checklist
 - Migrations:
   - `php artisan migrate --force`
 - DB connection:
   - `php artisan tinker --execute="DB::connection()->getPdo(); echo 'DB ok'.PHP_EOL;"`
 - Spaces:
   - `php artisan tinker --execute="Storage::disk('spaces')->put('healthcheck.txt','ok'); Storage::disk('spaces')->exists('healthcheck.txt');"`
 - Webhook:
   - Register:
     - `php artisan robaws:register-webhook --url="https://beconnect-rn6k77zh.on-forge.com/api/webhooks/robaws/articles"`
   - Add `ROBAWS_WEBHOOK_SECRET` to `.env`
   - Test:
     - `php artisan robaws:test-webhook --url="https://beconnect-rn6k77zh.on-forge.com/api/webhooks/robaws/articles"`
 
 ## Robaws Test Commands
 - Sync extra fields:
   - `php artisan robaws:sync-extra-fields --batch-size=50 --delay=0.5`
 - Sync metadata:
   - `php artisan robaws:sync-articles --metadata-only --sync-now`
 - Create test offer:
   - `php artisan robaws:test --create-offer --no-interaction`
 
 ## Notes
 - Keep secrets out of this file. Use placeholders and set values in Forge or `.env`.
 - If Robaws credentials are missing, logs will show warnings until set.
