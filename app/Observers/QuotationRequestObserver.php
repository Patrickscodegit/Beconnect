<?php

namespace App\Observers;

use App\Models\QuotationRequest;
use App\Services\Pricing\VatResolverInterface;
use App\Services\Pricing\QuotationVatService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class QuotationRequestObserver
{
    public function __construct(
        private readonly VatResolverInterface $vatResolver,
        private readonly QuotationVatService $quotationVatService,
    ) {}

    /**
     * Set project_vat_code before saving
     */
    public function saving(QuotationRequest $quotationRequest): void
    {
        // Check if column exists to prevent errors if migration hasn't run
        if (!$this->columnExists('quotation_requests', 'project_vat_code')) {
            Log::warning('QuotationRequestObserver::saving - project_vat_code column does not exist, skipping VAT code assignment');
            return;
        }

        try {
            $projectVatCode = $this->vatResolver->determineProjectVatCode($quotationRequest);
            $quotationRequest->project_vat_code = $projectVatCode;
            
            Log::debug('QuotationRequestObserver::saving - Set project_vat_code', [
                'quotation_id' => $quotationRequest->id,
                'pol' => $quotationRequest->pol,
                'pod' => $quotationRequest->pod,
                'project_vat_code' => $projectVatCode,
            ]);
        } catch (\Exception $e) {
            Log::error('QuotationRequestObserver::saving - Error setting VAT code', [
                'quotation_id' => $quotationRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Fallback to default - only set if column exists
            try {
                $quotationRequest->project_vat_code = '21% VF';
            } catch (\Exception $fallbackError) {
                Log::error('QuotationRequestObserver::saving - Error setting fallback VAT code', [
                    'quotation_id' => $quotationRequest->id,
                    'error' => $fallbackError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Recalculate VAT for quotation and all articles after save
     */
    public function saved(QuotationRequest $quotationRequest): void
    {
        // Check if columns exist to prevent errors if migration hasn't run
        if (!$this->columnExists('quotation_requests', 'project_vat_code')) {
            Log::warning('QuotationRequestObserver::saved - project_vat_code column does not exist, skipping VAT recalculation');
            return;
        }

        // Recalculate if relevant fields changed OR if project_vat_code changed
        $relevantFieldsChanged = $quotationRequest->wasChanged(['pol', 'pod', 'robaws_client_id', 'project_vat_code']);
        
        if ($relevantFieldsChanged) {
            try {
                Log::debug('QuotationRequestObserver::saved - Recalculating VAT', [
                    'quotation_id' => $quotationRequest->id,
                    'pol' => $quotationRequest->pol,
                    'pod' => $quotationRequest->pod,
                    'project_vat_code' => $quotationRequest->project_vat_code,
                    'changed_fields' => $quotationRequest->getChanges(),
                ]);
                
                $this->quotationVatService->recalculateVatForQuotation($quotationRequest);
            } catch (\Exception $e) {
                Log::error('QuotationRequestObserver::saved - Error recalculating VAT', [
                    'quotation_id' => $quotationRequest->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Don't throw - allow quotation to save even if VAT calculation fails
            }
        }

        // Ensure admin article exists (handle POD changes and missing admin articles)
        $this->ensureAdminArticleExists($quotationRequest);
    }

    /**
     * Handle the QuotationRequest "updated" event.
     * Sync status back to linked intake when quotation status changes
     */
    public function updated(QuotationRequest $quotationRequest): void
    {
        // Only proceed if status changed and there's a linked intake
        if ($quotationRequest->isDirty('status') && $quotationRequest->intake_id) {
            $this->syncStatusToIntake($quotationRequest);
        }
    }

    /**
     * Sync quotation status back to intake
     */
    protected function syncStatusToIntake(QuotationRequest $quotationRequest): void
    {
        $intake = $quotationRequest->intake;
        
        if (!$intake) {
            return;
        }

        // Map quotation status to intake status
        $intakeStatus = match ($quotationRequest->status) {
            'pending' => 'processing',      // Quotation pending → Intake still processing
            'processing' => 'processing',   // Quotation being worked on → Intake processing
            'quoted' => 'completed',        // Quotation sent → Intake completed
            'accepted' => 'completed',      // Quotation accepted → Intake completed
            'rejected' => 'completed',      // Quotation rejected → Still completed (we tried)
            'expired' => 'completed',       // Quotation expired → Intake completed
            default => null,
        };

        if ($intakeStatus && $intake->status !== $intakeStatus) {
            $oldStatus = $intake->status;
            
            // Prevent observer recursion
            $intake->withoutEvents(function () use ($intake, $intakeStatus) {
                $intake->update(['status' => $intakeStatus]);
            });
            
            Log::info('Synced quotation status to intake', [
                'quotation_id' => $quotationRequest->id,
                'quotation_request_number' => $quotationRequest->request_number,
                'quotation_status' => $quotationRequest->status,
                'intake_id' => $intake->id,
                'intake_status_old' => $oldStatus,
                'intake_status_new' => $intakeStatus,
            ]);
        }
    }

    /**
     * Ensure an admin article exists for the quotation
     * Handles POD changes and missing admin articles
     */
    protected function ensureAdminArticleExists(QuotationRequest $quotationRequest): void
    {
        // Admin article IDs: 14 (Admin 115), 15 (Admin 100), 16 (Admin 110), 17 (Admin 125), 20 (Admin 75)
        $adminArticleIds = [14, 15, 16, 17, 20];
        
        // Check if any admin article exists
        $existingAdminArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
            ->whereIn('article_cache_id', $adminArticleIds)
            ->first();
        
        // If POD changed, we may need to update the admin article
        $podChanged = $quotationRequest->wasChanged('pod');
        
        if ($podChanged && $existingAdminArticle) {
            // POD changed - remove old admin article and let it be re-added with correct one
            try {
                $existingAdminArticle->delete();
                Log::info('Removed admin article due to POD change', [
                    'quotation_id' => $quotationRequest->id,
                    'old_pod' => $quotationRequest->getOriginal('pod'),
                    'new_pod' => $quotationRequest->pod,
                    'removed_admin_id' => $existingAdminArticle->article_cache_id,
                ]);
                $existingAdminArticle = null; // Reset to trigger re-addition
            } catch (\Exception $e) {
                Log::error('Error removing admin article on POD change', [
                    'quotation_id' => $quotationRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // If no admin article exists, add the appropriate one
        if (!$existingAdminArticle) {
            try {
                $this->addAdminArticleForQuotation($quotationRequest);
            } catch (\Exception $e) {
                Log::error('Error ensuring admin article exists', [
                    'quotation_id' => $quotationRequest->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Don't throw - allow quotation to save even if admin article addition fails
            }
        }
    }

    /**
     * Add the appropriate admin article based on POD and commodity type
     */
    protected function addAdminArticleForQuotation(QuotationRequest $quotationRequest): void
    {
        // Find admin articles dynamically (works in both local and production)
        $admin75 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 75')->where('unit_price', 75)->first();
        $admin100 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 100')->where('unit_price', 100)->first();
        $admin110 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 110')->where('unit_price', 110)->first();
        $admin115 = \App\Models\RobawsArticleCache::where('article_name', 'Admin')->where('unit_price', 115)->first();
        $admin125 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 125')->where('unit_price', 125)->first();
        
        $adminArticles = collect();
        if ($admin75) $adminArticles->put('admin_75', $admin75);
        if ($admin100) $adminArticles->put('admin_100', $admin100);
        if ($admin110) $adminArticles->put('admin_110', $admin110);
        if ($admin115) $adminArticles->put('admin_115', $admin115);
        if ($admin125) $adminArticles->put('admin_125', $admin125);
        
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'B', 'location' => 'QuotationRequestObserver.php:206', 'message' => 'Admin articles lookup in observer', 'data' => ['admin75_id' => $admin75?->id, 'admin100_id' => $admin100?->id, 'admin110_id' => $admin110?->id, 'admin115_id' => $admin115?->id, 'admin125_id' => $admin125?->id, 'quotation_id' => $quotationRequest->id, 'pod' => $quotationRequest->pod], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion

        if ($adminArticles->isEmpty()) {
            Log::warning('No admin articles found', [
                'quotation_id' => $quotationRequest->id,
            ]);
            return;
        }

        $conditionMatcher = app(\App\Services\CompositeItems\ConditionMatcherService::class);
        $role = $quotationRequest->customer_role ?: 'default';

        // Priority order: 110, 115, 125, 100, 75
        $adminPriority = [
            'admin_110' => ['id' => $adminArticles->get('admin_110')->id ?? null, 'conditions' => ['route' => ['pod' => ['PNR']]]],
            'admin_115' => ['id' => $adminArticles->get('admin_115')->id ?? null, 'conditions' => ['route' => ['pod' => ['LAD']]]],
            'admin_125' => ['id' => $adminArticles->get('admin_125')->id ?? null, 'conditions' => ['route' => ['pod' => ['FNA']]]],
            'admin_100' => ['id' => $adminArticles->get('admin_100')->id ?? null, 'conditions' => ['route' => ['pod' => ['DKR']], 'commodity' => ['LM Cargo']]],
            'admin_75' => ['id' => $adminArticles->get('admin_75')->id ?? null, 'conditions' => []], // Default
        ];

        // Find first parent article to use as parent_article_id
        $firstParentArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
            ->where('item_type', 'parent')
            ->first();

        $parentArticleId = $firstParentArticle ? $firstParentArticle->article_cache_id : null;

        // Evaluate conditions in priority order
        foreach ($adminPriority as $adminKey => $adminData) {
            $adminId = $adminData['id'];
            $conditions = $adminData['conditions'];

            if (!$adminId) {
                continue;
            }

            $shouldAdd = false;
            if (empty($conditions)) {
                // Admin 75 (default) - always add if no other matched
                $shouldAdd = true;
            } else {
                $shouldAdd = $conditionMatcher->matchConditions($conditions, $quotationRequest);
            }

            if ($shouldAdd) {
                $adminArticle = \App\Models\RobawsArticleCache::find($adminId);
                if (!$adminArticle) {
                    continue;
                }

                // Check if already exists (shouldn't, but double-check)
                $exists = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
                    ->where('article_cache_id', $adminId)
                    ->exists();

                if (!$exists) {
                    \App\Models\QuotationRequestArticle::create([
                        'quotation_request_id' => $quotationRequest->id,
                        'article_cache_id' => $adminId,
                        'parent_article_id' => $parentArticleId,
                        'item_type' => 'child',
                        'quantity' => 1, // Always 1 for admin articles
                        'unit_type' => $adminArticle->unit_type ?? 'unit',
                        'unit_price' => $adminArticle->unit_price,
                        'selling_price' => $adminArticle->getPriceForRole($role),
                        'currency' => $adminArticle->currency,
                    ]);

                    Log::info('Added admin article to quotation', [
                        'quotation_id' => $quotationRequest->id,
                        'admin_article_id' => $adminId,
                        'admin_article_name' => $adminArticle->article_name,
                        'pod' => $quotationRequest->pod,
                    ]);
                }

                // Only add one admin article
                break;
            }
        }
    }

    /**
     * Check if a column exists in a table
     */
    protected function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Exception $e) {
            Log::warning('QuotationRequestObserver::columnExists - Error checking column', [
                'table' => $table,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);
            // If we can't check, assume it exists to avoid breaking saves
            return true;
        }
    }
}
