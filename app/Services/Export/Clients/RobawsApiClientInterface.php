<?php

namespace App\Services\Export\Clients;

interface RobawsApiClientInterface
{
    public function findClientId(?string $name, ?string $email, ?string $phone = null): ?int;
    public function findOrCreateClient(array $clientData): ?array;
    public function findContactByEmail(string $email): ?array;
    public function createContact(string|int $clientId, array $contact): ?array;
    public function setOfferContact(int $offerId, int $contactId): bool;
    public function findOrCreateClientContactId(int $clientId, array $contactData): ?int;
    public function attachFileToOffer(int $offerId, string $absolutePath, string $filename): array;
    public function getOffer(string $offerId, array $include = []): array;
    public function createQuotation(array $payload, string $idempotencyKey): array;
    public function updateQuotation(string $quotationId, array $payload, string $idempotencyKey): array;
    public function validateConfig(): array;
    public function testConnection(): array;
    public function buildRobawsClientPayload(array $customer): array;
}

