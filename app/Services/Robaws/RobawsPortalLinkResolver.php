<?php

namespace App\Services\Robaws;

use App\Models\RobawsCustomerPortalLink;
use App\Models\RobawsDomainMapping;
use App\Models\User;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

class RobawsPortalLinkResolver
{
    public function __construct(
        private readonly RobawsApiClient $apiClient
    ) {
    }

    public function resolveForUser(User $user): ?RobawsCustomerPortalLink
    {
        $existing = RobawsCustomerPortalLink::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $email = $user->email;
        if (!$email) {
            return null;
        }

        $client = $this->apiClient->findClientByEmailRobust($email);
        if (!$client) {
            $client = $this->apiClient->scanClientsForEmail($email);
        }

        if ($client) {
            return RobawsCustomerPortalLink::create([
                'user_id' => $user->id,
                'robaws_client_id' => (string) ($client['id'] ?? ''),
                'source' => 'email',
            ]);
        }

        $domain = $this->extractDomain($email);
        if (!$domain) {
            return null;
        }

        $mapping = RobawsDomainMapping::where('domain', $domain)->first();
        if (!$mapping) {
            Log::info('No Robaws domain mapping found for portal user', [
                'user_id' => $user->id,
                'email' => $email,
                'domain' => $domain,
            ]);
            return null;
        }

        $mappedClient = $this->apiClient->getClientById((string) $mapping->robaws_client_id, ['contacts']);
        if (!$mappedClient) {
            Log::warning('Robaws domain mapping client not found', [
                'user_id' => $user->id,
                'email' => $email,
                'domain' => $domain,
                'robaws_client_id' => $mapping->robaws_client_id,
            ]);
            return null;
        }

        return RobawsCustomerPortalLink::create([
            'user_id' => $user->id,
            'robaws_client_id' => (string) ($mappedClient['id'] ?? $mapping->robaws_client_id),
            'source' => 'domain',
        ]);
    }

    private function extractDomain(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if (!$email || !str_contains($email, '@')) {
            return null;
        }

        $domain = substr(strrchr($email, '@'), 1);
        $domain = $domain ? trim($domain) : null;

        return $domain ?: null;
    }
}
