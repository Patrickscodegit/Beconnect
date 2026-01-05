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
        $mapping = $tariff->carrierArticleMapping;
        
        if (!$mapping || !$mapping->article) {
            return;
        }
        
        $article = $mapping->article;
        
        // Check if values actually changed (no-op guard)
        $newUpdateDate = $tariff->update_date ?: null;
        $newValidityDate = $tariff->validity_date ?: null;
        
        $currentUpdateDate = $article->update_date_override;
        $currentValidityDate = $article->validity_date_override;
        
        // Compare dates (handle Carbon comparison)
        $updateDateChanged = $this->datesDiffer($newUpdateDate, $currentUpdateDate);
        $validityDateChanged = $this->datesDiffer($newValidityDate, $currentValidityDate);
        
        // Only update if something changed
        if (!$updateDateChanged && !$validityDateChanged) {
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
            
            $article->update($updates);
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

