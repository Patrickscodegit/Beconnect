<?php

namespace App\Console\Commands;

use App\Models\RobawsCustomerPortalLink;
use App\Models\RobawsDomainMapping;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RelinkRobawsPortalUsers extends Command
{
    protected $signature = 'robaws:relink-portal-users
        {domain : Email domain to relink}
        {--client-id= : Robaws client id to link}
        {--include-manual : Also update links marked as manual}
        {--dry-run : Do not write changes}';

    protected $description = 'Relink portal users for a domain to a Robaws client.';

    public function handle(): int
    {
        $domain = mb_strtolower(trim((string) $this->argument('domain')));
        if (!$domain) {
            $this->error('Domain is required.');
            return self::FAILURE;
        }

        $clientId = (string) ($this->option('client-id') ?? '');
        if ($clientId === '') {
            $mapping = RobawsDomainMapping::where('domain', $domain)->first();
            if (!$mapping) {
                $this->error("No domain mapping found for {$domain} and no --client-id provided.");
                return self::FAILURE;
            }
            $clientId = (string) $mapping->robaws_client_id;
        }

        $includeManual = (bool) $this->option('include-manual');
        $dryRun = (bool) $this->option('dry-run');

        $users = User::where('email', 'like', '%@' . $domain)->get();
        if ($users->isEmpty()) {
            $this->info("No users found for domain {$domain}.");
            return self::SUCCESS;
        }

        $updated = 0;
        $created = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $link = RobawsCustomerPortalLink::where('user_id', $user->id)->first();
            if ($link) {
                if ($link->source === 'manual' && !$includeManual) {
                    $skipped++;
                    continue;
                }
                if ((string) $link->robaws_client_id === $clientId) {
                    $skipped++;
                    continue;
                }

                if (!$dryRun) {
                    $link->update([
                        'robaws_client_id' => $clientId,
                        'source' => 'domain',
                    ]);
                }
                $updated++;
                continue;
            }

            if (!$dryRun) {
                RobawsCustomerPortalLink::create([
                    'user_id' => $user->id,
                    'robaws_client_id' => $clientId,
                    'source' => 'domain',
                ]);
            }
            $created++;
        }

        $this->info("Relinked domain {$domain} to client {$clientId}.");
        $this->info("Updated: {$updated}, Created: {$created}, Skipped: {$skipped}" . ($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
