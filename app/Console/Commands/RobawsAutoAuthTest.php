<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class RobawsAutoAuthTest extends Command
{
    protected $signature = 'robaws:auto-auth-test {--path=/api/v2/metadata}';
    protected $description = 'Probe Robaws API with multiple auth schemes (API key/secret) and report the first that succeeds.';

    public function handle(): int
    {
        $base = config('services.robaws.base_url', 'https://app.robaws.com');
        $key  = config('services.robaws.api_key');
        $sec  = config('services.robaws.api_secret');
        $ten  = config('services.robaws.tenant_id'); // optional
        $path = $this->option('path');

        if (!$key || !$sec) {
            $this->error('Missing ROBAWS_API_KEY or ROBAWS_API_SECRET in .env');
            $this->warn('Please add your API credentials to .env:');
            $this->line('ROBAWS_API_KEY=your_key_here');
            $this->line('ROBAWS_API_SECRET=your_secret_here');
            return self::FAILURE;
        }

        $this->info('🔧 Testing Robaws API Authentication Methods');
        $this->warn('⚠️  Note: These keys will be rotated after finding the working method');
        $this->newLine();

        $client = new Client(['base_uri' => $base, 'timeout' => 20, 'http_errors' => false]);

        $variants = [
            [
                'name' => 'A) Basic auth (key:secret via Guzzle auth)',
                'req'  => function() use ($client, $path, $key, $sec, $ten) {
                    $headers = ['Accept' => 'application/json'];
                    if ($ten) $headers['X-Tenant'] = $ten;
                    return $client->get($path, [
                        'headers' => $headers,
                        'auth'    => [$key, $sec],
                    ]);
                },
            ],
            [
                'name' => 'B) Authorization: Basic base64(key:secret)',
                'req'  => function() use ($client, $path, $key, $sec, $ten) {
                    $headers = [
                        'Accept' => 'application/json',
                        'Authorization' => 'Basic '.base64_encode($key.':'.$sec),
                    ];
                    if ($ten) $headers['X-Tenant'] = $ten;
                    return $client->get($path, ['headers' => $headers]);
                },
            ],
            [
                'name' => 'C) Authorization: Bearer (key only)',
                'req'  => function() use ($client, $path, $key, $ten) {
                    $headers = [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer '.$key,
                    ];
                    if ($ten) $headers['X-Tenant'] = $ten;
                    return $client->get($path, ['headers' => $headers]);
                },
            ],
            [
                'name' => 'D) X-Api-Key + X-Api-Secret headers',
                'req'  => function() use ($client, $path, $key, $sec, $ten) {
                    $headers = [
                        'Accept'       => 'application/json',
                        'X-Api-Key'    => $key,
                        'X-Api-Secret' => $sec,
                    ];
                    if ($ten) $headers['X-Tenant'] = $ten;
                    return $client->get($path, ['headers' => $headers]);
                },
            ],
            [
                'name' => 'E) X-API-KEY + X-API-SECRET (uppercase)',
                'req'  => function() use ($client, $path, $key, $sec, $ten) {
                    $headers = [
                        'Accept'       => 'application/json',
                        'X-API-KEY'    => $key,
                        'X-API-SECRET' => $sec,
                    ];
                    if ($ten) $headers['X-Tenant'] = $ten;
                    return $client->get($path, ['headers' => $headers]);
                },
            ],
            [
                'name' => 'F) ApiKey + ApiSecret headers (simple)',
                'req'  => function() use ($client, $path, $key, $sec, $ten) {
                    $headers = [
                        'Accept'    => 'application/json',
                        'ApiKey'    => $key,
                        'ApiSecret' => $sec,
                    ];
                    if ($ten) $headers['X-Tenant'] = $ten;
                    return $client->get($path, ['headers' => $headers]);
                },
            ],
            [
                'name' => 'G) HMAC (X-Api-Key + X-Timestamp + X-Signature)',
                'req'  => function() use ($client, $path, $key, $sec, $ten) {
                    $ts = gmdate('Y-m-d\TH:i:s\Z');
                    // Canonical string (guess): METHOD \n PATH \n TIMESTAMP \n
                    $toSign = "GET\n{$path}\n{$ts}\n";
                    $sig = base64_encode(hash_hmac('sha256', $toSign, $sec, true));
                    $headers = [
                        'Accept'       => 'application/json',
                        'X-Api-Key'    => $key,
                        'X-Timestamp'  => $ts,
                        'X-Signature'  => $sig,
                    ];
                    if ($ten) $headers['X-Tenant'] = $ten;
                    return $client->get($path, ['headers' => $headers]);
                },
            ],
        ];

        $this->info("🔎 Probing {$base}{$path} with multiple auth schemes…");
        $this->line("Base URL: {$base}");
        $this->line("API Key: " . substr($key, 0, 8) . '...' . substr($key, -4));
        $this->line("Tenant ID: " . ($ten ?: 'Not configured'));
        $this->newLine();

        foreach ($variants as $v) {
            $name = $v['name'];
            $this->line("• Trying {$name}");
            try {
                $resp = ($v['req'])();
                $code = $resp->getStatusCode();
                
                if ($code >= 200 && $code < 300) {
                    $this->info("  ✅ SUCCESS {$code} with: {$name}");
                    $body = (string)$resp->getBody();
                    $this->line('  Preview: '.substr($body, 0, 200).'…');
                    $this->newLine();
                    $this->info('🎉 Found working auth method!');
                    $this->info('This is the authentication scheme that works with Robaws.');
                    $this->newLine();
                    $this->warn('Next steps:');
                    $this->line('1. Update RobawsClient to use this auth method');
                    $this->line('2. Rotate/regenerate API credentials in Robaws');
                    $this->line('3. Update .env with new credentials');
                    return self::SUCCESS;
                }
                
                $body = (string)$resp->getBody();
                $corr = null;
                $error = null;
                if ($body) {
                    $json = json_decode($body, true);
                    $corr = $json['correlationId'] ?? null;
                    $error = $json['error'] ?? $json['message'] ?? null;
                }
                
                // Check for special Robaws headers
                $unauthorizedReason = $resp->getHeaderLine('X-Robaws-Unauthorized-Reason');
                $www = $resp->getHeaderLine('WWW-Authenticate');
                
                $this->warn("  ❌ {$name} -> HTTP {$code}");
                if ($error) $this->line("     Error: {$error}");
                if ($unauthorizedReason) $this->line("     Robaws Reason: {$unauthorizedReason}");
                if ($www)  $this->line("     WWW-Authenticate: {$www}");
                if ($corr) $this->line("     correlationId: {$corr}");
                
            } catch (\Throwable $e) {
                $this->warn("  ❌ {$name} -> ".$e->getMessage());
            }
        }

        $this->error('All auth variants failed.');
        $this->newLine();
        $this->warn('Troubleshooting tips:');
        $this->line('1. Check if the API key is active/enabled in Robaws');
        $this->line('2. Verify any IP allow-listing requirements');
        $this->line('3. Check if a tenant ID is required (ROBAWS_TENANT_ID in .env)');
        $this->line('4. Try different API endpoints');
        $this->line('5. Contact Robaws support with the latest correlationId');
        return self::FAILURE;
    }
}
