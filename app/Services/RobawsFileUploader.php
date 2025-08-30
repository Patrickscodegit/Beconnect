<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class RobawsFileUploader
{
    public function __construct(private RobawsClient $client) {}

    /**
     * Upload and attach a file to a Robaws entity
     * 
     * @param string $entityPath e.g. 'offers', 'projects', 'purchase-invoices'
     * @param int|string $entityId
     * @param string $filename
     * @param string $mime
     * @param string $contents binary string
     */
    public function uploadAndAttach(string $entityPath, $entityId, string $filename, string $mime, string $contents): array
    {
        $size = strlen($contents);
        
        Log::info('Starting Robaws file upload', [
            'entity_path' => $entityPath,
            'entity_id' => $entityId,
            'filename' => $filename,
            'size' => $size,
            'strategy' => $size <= 6 * 1024 * 1024 ? 'direct' : 'chunked'
        ]);

        // For ≤ 6MB, prefer direct entity upload
        if ($size <= 6 * 1024 * 1024) {
            return $this->uploadDirectly($entityPath, $entityId, $filename, $mime, $contents);
        }

        // For > 6MB, use temp bucket + session
        return $this->uploadViaChunking($entityPath, $entityId, $filename, $mime, $contents);
    }

    /**
     * Upload small files directly to entity
     */
    private function uploadDirectly(string $entityPath, $entityId, string $filename, string $mime, string $contents): array
    {
        try {
            return $this->client->uploadDirectToEntity($entityPath, $entityId, $filename, $mime, $contents);
        } catch (\Exception $e) {
            Log::error('Direct upload failed, trying temp bucket method', [
                'entity_path' => $entityPath,
                'entity_id' => $entityId,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to temp bucket method
            return $this->uploadViaChunking($entityPath, $entityId, $filename, $mime, $contents);
        }
    }

    /**
     * Upload large files via temp bucket and chunking
     */
    private function uploadViaChunking(string $entityPath, $entityId, string $filename, string $mime, string $contents): array
    {
        $size = strlen($contents);
        
        // Create temp bucket
        $bucket = $this->client->createTempBucket();
        
        // For files ≤ 6MB, use simple bucket upload
        if ($size <= 6 * 1024 * 1024) {
            $document = $this->client->uploadSmallToBucket($bucket['id'], $filename, $mime, $contents);
            
            // If we get a document ID, attach it to the entity
            if (isset($document['id'])) {
                return $this->client->attachByDocumentId($entityPath, $entityId, $document['id']);
            }
            
            return $document;
        }
        
        // For files > 6MB, use upload session
        $session = $this->client->startUploadSession($bucket['id']);
        
        $chunkSize = 5 * 1024 * 1024; // 5MB chunks (must be < 6MB)
        $part = 0;
        $lastResponse = null;

        for ($offset = 0; $offset < $size; $offset += $chunkSize) {
            $chunk = substr($contents, $offset, $chunkSize);
            $b64 = base64_encode($chunk);
            
            $lastResponse = $this->client->uploadPart($session['id'], $b64, $part);
            $part++;
        }

        // Extract document ID from final response or session
        $documentId = $lastResponse['documentId'] ?? $session['documentId'] ?? null;

        if (!$documentId) {
            throw new \RuntimeException('Robaws did not return a documentId after multipart upload.');
        }

        // Attach to the entity
        return $this->client->attachByDocumentId($entityPath, $entityId, $documentId);
    }

    /**
     * Upload a PDF document specifically
     */
    public function uploadPdf(string $entityPath, $entityId, string $filename, string $pdfContents): array
    {
        return $this->uploadAndAttach($entityPath, $entityId, $filename, 'application/pdf', $pdfContents);
    }

    /**
     * Upload PDF from file path
     */
    public function uploadPdfFromPath(string $entityPath, $entityId, string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $contents = file_get_contents($filePath);
        $filename = basename($filePath);

        return $this->uploadPdf($entityPath, $entityId, $filename, $contents);
    }
}
