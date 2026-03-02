<?php

namespace App\Services\Robaws;

use App\Models\RobawsCustomerPortalLink;
use App\Models\RobawsDomainMapping;
use App\Models\User;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Http\Client\ConnectionException;
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

        $domain = $this->extractDomain($email);
        if (!$domain) {
            return null;
        }

        $mapping = RobawsDomainMapping::where('domain', $domain)->first();
        if ($mapping) {
            try {
                $mappedClient = $this->apiClient->getClientById((string) $mapping->robaws_client_id, ['contacts']);
            } catch (ConnectionException $e) {
                Log::warning('Robaws connection timeout resolving domain mapping', [
                    'user_id' => $user->id,
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            if ($mappedClient) {
                return RobawsCustomerPortalLink::create([
                    'user_id' => $user->id,
                    'robaws_client_id' => (string) ($mappedClient['id'] ?? $mapping->robaws_client_id),
                    'source' => 'domain',
                ]);
            }

            Log::warning('Robaws domain mapping client not found', [
                'user_id' => $user->id,
                'email' => $email,
                'domain' => $domain,
                'robaws_client_id' => $mapping->robaws_client_id,
            ]);
        }

        try {
            $client = $this->apiClient->findClientByEmailRobust($email);
            if (!$client) {
                $client = $this->apiClient->scanClientsForEmail($email);
            }
        } catch (ConnectionException $e) {
            Log::warning('Robaws connection timeout searching by email', [
                'user_id' => $user->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($client) {
            return RobawsCustomerPortalLink::create([
                'user_id' => $user->id,
                'robaws_client_id' => (string) ($client['id'] ?? ''),
                'source' => 'email',
            ]);
        }

        Log::info('No Robaws client found for portal user', [
            'user_id' => $user->id,
            'email' => $email,
            'domain' => $domain,
        ]);

        return null;
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
