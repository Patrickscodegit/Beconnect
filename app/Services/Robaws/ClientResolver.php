<?php

namespace App\Services\Robaws;

use App\Services\Export\Clients\RobawsApiClient;

/**
 * Unified client resolution service that ensures consistent client matching
 * across all intake types (.eml, manual uploads, images)
 */
final class ClientResolver
{
    public function __construct(private readonly RobawsApiClient $api) {}

    /** @return array{id:string, confidence:float}|null */
    public function resolve(array $hints): ?array
    {
        // A) explicit ID override (admin mapping / UI pin)
        if (!empty($hints['id'])) {
            if ($c = $this->api->getClientById((string)$hints['id'])) {
                return ['id' => (string)$c['id'], 'confidence' => 0.99];
            }
        }

        // B) strong: email (for .eml)
        if (!empty($hints['email']) && ($c = $this->api->findClientByEmail($hints['email']))) {
            return ['id' => (string)$c['id'], 'confidence' => 0.99];
        }

        // C) strong: phone → contacts
        if (!empty($hints['phone']) && ($c = $this->api->findClientByPhone($hints['phone']))) {
            return ['id' => (string)$c['id'], 'confidence' => 0.95];
        }

        // D) name path: paged + fuzzy (deterministic, v2 only)
        if (!empty($hints['name'])) {
            $needle = (string)$hints['name'];
            $best = null; $bestScore = 0.0;
            $page = 0; $size = 100; $maxPages = 50;

            do {
                $json = $this->api->listClients($page, $size);
                $rows = $json['items'] ?? [];
                if (!$rows) break;

                foreach ($rows as $r) {
                    $score = NameNormalizer::similarity($needle, (string)($r['name'] ?? ''));
                    if ($score > $bestScore) { $best = $r; $bestScore = $score; }
                }
                $page++;
                $total = (int)($json['totalItems'] ?? 0);
            } while ($page < $maxPages && $page * $size < $total);

            if ($best && $bestScore >= 82.0) {
                return ['id' => (string)$best['id'], 'confidence' => $bestScore / 100];
            }
        }

        return null;
    }
}
