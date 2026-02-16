<?php

namespace App\Http\Controllers;

use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Robaws\RobawsPortalLinkResolver;

class CustomerRobawsController extends Controller
{
    public function offerPdf(string $offerId)
    {
        $user = auth()->user();
        $link = app(RobawsPortalLinkResolver::class)->resolveForUser($user);
        if (!$link) {
            \Log::warning('Portal PDF access denied: no Robaws link', [
                'user_id' => $user->id,
                'offer_id' => $offerId,
            ]);
            abort(404, 'Robaws client not linked.');
        }

        $apiClient = app(RobawsApiClient::class);
        $offerResult = $apiClient->getOffer($offerId, ['client']);
        if (empty($offerResult['success'])) {
            abort(404, 'Offer not found.');
        }

        $offer = $offerResult['data'] ?? [];
        $offerClientId = (string) ($offer['clientId'] ?? ($offer['client']['id'] ?? ''));
        if ($offerClientId !== (string) $link->robaws_client_id) {
            \Log::warning('Portal PDF access denied: client mismatch', [
                'user_id' => $user->id,
                'offer_id' => $offerId,
                'offer_client_id' => $offerClientId,
                'linked_client_id' => $link->robaws_client_id,
            ]);
            abort(403, 'Unauthorized access to this offer.');
        }

        $documentsResult = $apiClient->listOfferDocuments($offerId);
        if (empty($documentsResult['success'])) {
            abort(404, 'Offer documents not found.');
        }

        $documentsPayload = $documentsResult['data'] ?? [];
        $documents = $documentsPayload['items'] ?? $documentsPayload;
        $pdfDocument = collect($documents)->first(function ($doc) {
            $fileName = mb_strtolower($doc['fileName'] ?? $doc['name'] ?? '');
            $mimeType = mb_strtolower($doc['mimeType'] ?? $doc['contentType'] ?? '');

            return str_ends_with($fileName, '.pdf') || $mimeType === 'application/pdf';
        });

        if (!$pdfDocument) {
            \Log::warning('Portal PDF access failed: no PDF document found', [
                'user_id' => $user->id,
                'offer_id' => $offerId,
            ]);
            abort(404, 'No PDF available for this offer.');
        }

        $downloadUrl = $pdfDocument['downloadUrl'] ?? $pdfDocument['url'] ?? null;
        if ($downloadUrl) {
            return redirect()->away($downloadUrl);
        }

        $documentId = $pdfDocument['id'] ?? null;
        if (!$documentId) {
            \Log::warning('Portal PDF access failed: missing document id', [
                'user_id' => $user->id,
                'offer_id' => $offerId,
            ]);
            abort(404, 'PDF document is missing.');
        }

        $downloadResult = $apiClient->downloadDocument((string) $documentId);
        if (empty($downloadResult['success'])) {
            \Log::warning('Portal PDF access failed: download error', [
                'user_id' => $user->id,
                'offer_id' => $offerId,
                'document_id' => $documentId,
                'error' => $downloadResult['error'] ?? 'Unknown error',
            ]);
            abort(404, 'PDF download failed.');
        }

        $filename = $this->extractFilename($downloadResult['content_disposition'] ?? '')
            ?: ($pdfDocument['fileName'] ?? 'offer.pdf');

        return response($downloadResult['body'] ?? '', 200, [
            'Content-Type' => $downloadResult['content_type'] ?? 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    private function extractFilename(string $contentDisposition): ?string
    {
        if (!$contentDisposition) {
            return null;
        }

        if (preg_match('/filename\\*?=(?:UTF-8\'\')?\"?([^\";]+)\"?/i', $contentDisposition, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
