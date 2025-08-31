<?php

namespace App\Services;

use App\Services\Robaws\RobawsExportService;
use App\Services\RobawsClient; // keep this if your client is App\Services\RobawsClient
// If your client actually lives under App\Services\Robaws\Sdk, swap the import:
// use App\Services\Robaws\Sdk\RobawsClient;

class RobawsService
{
    public function __construct(
        private ?RobawsClient $client = null,
        private ?RobawsExportService $exporter = null,
    ) {
        // Defer to the container so the tests can simply `new RobawsService()`
        $this->client   ??= app(RobawsClient::class);
        $this->exporter ??= app(RobawsExportService::class);
    }

    public function ping(): array
    {
        if (method_exists($this->client, 'testConnection')) {
            return $this->client->testConnection();
        }

        return ['success' => true, 'message' => 'RobawsService OK'];
    }

    public function client(): RobawsClient
    {
        return $this->client;
    }

    public function exporter(): RobawsExportService
    {
        return $this->exporter;
    }
}