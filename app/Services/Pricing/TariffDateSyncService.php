<?php

namespace App\Services\Pricing;

use App\Models\CarrierPurchaseTariff;
use App\Models\RobawsArticleCache;

class TariffDateSyncService
{
    /**
     * Sync tariff dates to article override columns
     * Only writes if values actually changed (no-op guard)
     */
    public function syncTariffDatesToArticle(CarrierPurchaseTariff $tariff): void
    {
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'C', 'location' => 'TariffDateSyncService.php:14', 'message' => 'syncTariffDatesToArticle entry', 'data' => ['tariffId' => $tariff->id, 'tariffUpdateDate' => $tariff->update_date?->format('Y-m-d'), 'tariffValidityDate' => $tariff->validity_date?->format('Y-m-d')], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion
        
        $mapping = $tariff->carrierArticleMapping;
        
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'C', 'location' => 'TariffDateSyncService.php:19', 'message' => 'Mapping check', 'data' => ['tariffId' => $tariff->id, 'hasMapping' => $mapping !== null, 'mappingId' => $mapping?->id, 'hasArticle' => $mapping?->article !== null, 'articleId' => $mapping?->article?->id, 'articleCode' => $mapping?->article?->article_code], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion
        
        if (!$mapping || !$mapping->article) {
            // #region agent log
            file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'C', 'location' => 'TariffDateSyncService.php:22', 'message' => 'Early return - no mapping/article', 'data' => ['tariffId' => $tariff->id], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
            // #endregion
            return;
        }
        
        $article = $mapping->article;
        
        // Check if values actually changed (no-op guard)
        $newUpdateDate = $tariff->update_date ?: null;
        $newValidityDate = $tariff->validity_date ?: null;
        
        $currentUpdateDate = $article->update_date_override;
        $currentValidityDate = $article->validity_date_override;
        
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'D', 'location' => 'TariffDateSyncService.php:32', 'message' => 'Date comparison', 'data' => ['articleId' => $article->id, 'articleCode' => $article->article_code, 'newUpdateDate' => $newUpdateDate?->format('Y-m-d'), 'currentUpdateDate' => $currentUpdateDate?->format('Y-m-d'), 'newValidityDate' => $newValidityDate?->format('Y-m-d'), 'currentValidityDate' => $currentValidityDate?->format('Y-m-d')], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion
        
        // Compare dates (handle Carbon comparison)
        $updateDateChanged = $this->datesDiffer($newUpdateDate, $currentUpdateDate);
        $validityDateChanged = $this->datesDiffer($newValidityDate, $currentValidityDate);
        
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'D', 'location' => 'TariffDateSyncService.php:36', 'message' => 'Date change detection', 'data' => ['articleId' => $article->id, 'updateDateChanged' => $updateDateChanged, 'validityDateChanged' => $validityDateChanged], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion
        
        // Only update if something changed
        if (!$updateDateChanged && !$validityDateChanged) {
            // #region agent log
            file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'D', 'location' => 'TariffDateSyncService.php:40', 'message' => 'No-op: dates unchanged', 'data' => ['articleId' => $article->id], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
            // #endregion
            return; // No-op: values are the same
        }
        
        $updates = [];
        
        if ($updateDateChanged) {
            $updates['update_date_override'] = $newUpdateDate;
        }
        if ($validityDateChanged) {
            $updates['validity_date_override'] = $newValidityDate;
        }
        
        // Only update metadata if values changed
        if (!empty($updates)) {
            $updates['dates_override_source'] = 'tariff';
            $updates['dates_override_at'] = now();
            
            // #region agent log
            file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'E', 'location' => 'TariffDateSyncService.php:52', 'message' => 'Before article update', 'data' => ['articleId' => $article->id, 'articleCode' => $article->article_code, 'updates' => array_map(function($v) { return $v instanceof \Carbon\Carbon ? $v->format('Y-m-d') : $v; }, $updates)], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
            // #endregion
            
            $article->update($updates);
            
            // #region agent log
            $article->refresh();
            file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'E', 'location' => 'TariffDateSyncService.php:58', 'message' => 'Article update successful', 'data' => ['articleId' => $article->id, 'articleCode' => $article->article_code, 'updateDateOverride' => $article->update_date_override?->format('Y-m-d'), 'validityDateOverride' => $article->validity_date_override?->format('Y-m-d')], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
            // #endregion
        }
    }
    
    /**
     * Compare two date values (handles Carbon, string, null)
     */
    private function datesDiffer($date1, $date2): bool
    {
        // Normalize to Y-m-d strings for comparison
        $str1 = $date1 ? (is_string($date1) ? $date1 : $date1->format('Y-m-d')) : null;
        $str2 = $date2 ? (is_string($date2) ? $date2 : $date2->format('Y-m-d')) : null;
        
        return $str1 !== $str2;
    }
}

