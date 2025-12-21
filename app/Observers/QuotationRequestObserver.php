<?php

namespace App\Observers;

use App\Models\QuotationRequest;
use App\Services\Pricing\VatResolverInterface;
use App\Services\Pricing\QuotationVatService;
use App\Services\Waivers\WaiverService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class QuotationRequestObserver
{
    public function __construct(
        private readonly VatResolverInterface $vatResolver,
        private readonly QuotationVatService $quotationVatService,
        private readonly WaiverService $waiverService,
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
        
        // Re-evaluate conditional child articles when POD or in_transit_to changes
        // This handles port-specific waivers (e.g., Dakar) that use database attachments
        $this->reevaluateConditionalChildArticles($quotationRequest);
        
        // Process hinterland waivers when POD or in_transit_to changes
        // This handles hinterland destination waivers (e.g., Burkina Faso) that use quotation logic
        $this->processHinterlandWaivers($quotationRequest);
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
     * Handles POD changes - removes old admin article when POD changes
     * The correct admin article will be added by addChildArticles() when parent articles are processed
     */
    protected function ensureAdminArticleExists(QuotationRequest $quotationRequest): void
    {
        // Find admin articles dynamically (works in both local and production)
        $admin75 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 75')->where('unit_price', 75)->first();
        $admin100 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 100')->where('unit_price', 100)->first();
        $admin110 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 110')->where('unit_price', 110)->first();
        $admin115 = \App\Models\RobawsArticleCache::where('article_name', 'Admin')->where('unit_price', 115)->first();
        $admin125 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 125')->where('unit_price', 125)->first();
        
        $adminArticleIds = array_filter([
            $admin75 ? $admin75->id : null,
            $admin100 ? $admin100->id : null,
            $admin110 ? $admin110->id : null,
            $admin115 ? $admin115->id : null,
            $admin125 ? $admin125->id : null,
        ]);
        
        // Check if any admin article exists
        $existingAdminArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
            ->whereIn('article_cache_id', $adminArticleIds)
            ->first();
        
        // If POD changed, remove old admin article
        // The correct admin article will be added by addChildArticles() when parent articles are processed
        $podChanged = $quotationRequest->wasChanged('pod');
        
        if ($podChanged && $existingAdminArticle) {
            try {
                $existingAdminArticle->delete();
                Log::info('Removed admin article due to POD change', [
                    'quotation_id' => $quotationRequest->id,
                    'old_pod' => $quotationRequest->getOriginal('pod'),
                    'new_pod' => $quotationRequest->pod,
                    'removed_admin_id' => $existingAdminArticle->article_cache_id,
                ]);
                
                // Re-trigger addChildArticles() for all parent articles to add correct admin article
                $parentArticles = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
                    ->where('item_type', 'parent')
                    ->get();
                
                foreach ($parentArticles as $parentArticle) {
                    try {
                        $parentArticle->addChildArticles();
                    } catch (\Exception $e) {
                        Log::error('Error re-adding child articles after POD change', [
                            'quotation_id' => $quotationRequest->id,
                            'parent_article_id' => $parentArticle->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error removing admin article on POD change', [
                    'quotation_id' => $quotationRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }


    /**
     * Re-evaluate conditional child articles when POD or in_transit_to changes
     * Removes waivers that no longer match conditions and adds waivers that now match
     */
    protected function reevaluateConditionalChildArticles(QuotationRequest $quotationRequest): void
    {
        $podChanged = $quotationRequest->wasChanged('pod');
        $inTransitToChanged = $quotationRequest->wasChanged('in_transit_to');
        
        // Only proceed if POD or in_transit_to changed
        if (!$podChanged && !$inTransitToChanged) {
            return;
        }
        
        try {
            Log::info('Re-evaluating conditional child articles', [
                'quotation_id' => $quotationRequest->id,
                'pod_changed' => $podChanged,
                'in_transit_to_changed' => $inTransitToChanged,
                'old_pod' => $podChanged ? $quotationRequest->getOriginal('pod') : null,
                'new_pod' => $podChanged ? $quotationRequest->pod : null,
                'old_in_transit_to' => $inTransitToChanged ? $quotationRequest->getOriginal('in_transit_to') : null,
                'new_in_transit_to' => $inTransitToChanged ? $quotationRequest->in_transit_to : null,
            ]);
            
            // Get all parent articles in this quotation
            $parentArticles = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
                ->where('item_type', 'parent')
                ->get();
            
            if ($parentArticles->isEmpty()) {
                return;
            }
            
            $conditionMatcher = app(\App\Services\CompositeItems\ConditionMatcherService::class);
            
            // Process each parent article
            foreach ($parentArticles as $parentArticle) {
                try {
                    // Get all conditional child articles for this parent
                    if (!$parentArticle->articleCache || !$parentArticle->articleCache->relationLoaded('children')) {
                        $parentArticle->load('articleCache.children');
                    }
                    
                    $children = $parentArticle->articleCache->children ?? collect();
                    
                    // Find existing conditional child articles in quotation
                    $existingChildArticles = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
                        ->where('parent_article_id', $parentArticle->article_cache_id)
                        ->where('item_type', 'child')
                        ->with('articleCache')
                        ->get();
                    
                    // Re-evaluate each conditional child
                    foreach ($children as $child) {
                        $childType = $child->pivot->child_type ?? 'optional';
                        
                        // Only process conditional children
                        if ($childType !== 'conditional') {
                            continue;
                        }
                        
                        // Parse conditions
                        $conditionsRaw = $child->pivot->conditions ?? null;
                        $conditions = is_string($conditionsRaw) 
                            ? json_decode($conditionsRaw, true) 
                            : $conditionsRaw;
                        
                        if (json_last_error() !== JSON_ERROR_NONE || empty($conditions)) {
                            Log::warning('Invalid conditions JSON for conditional child', [
                                'quotation_id' => $quotationRequest->id,
                                'parent_id' => $parentArticle->article_cache_id,
                                'child_id' => $child->id,
                                'json_error' => json_last_error_msg(),
                            ]);
                            continue;
                        }
                        
                        // Check if conditions match
                        $shouldExist = $conditionMatcher->matchConditions($conditions, $quotationRequest);
                        
                        // Find if this child article already exists in quotation
                        $existingChild = $existingChildArticles->firstWhere('article_cache_id', $child->id);
                        
                        if ($shouldExist && !$existingChild) {
                            // Conditions match but article doesn't exist - add it
                            try {
                                $role = $quotationRequest->customer_role;
                                $quantity = (int) ($child->pivot->default_quantity ?? 1);
                                
                                \App\Models\QuotationRequestArticle::create([
                                    'quotation_request_id' => $quotationRequest->id,
                                    'article_cache_id' => $child->id,
                                    'parent_article_id' => $parentArticle->article_cache_id,
                                    'item_type' => 'child',
                                    'quantity' => $quantity,
                                    'unit_type' => $child->pivot->unit_type ?? $child->unit_type ?? 'unit',
                                    'unit_price' => $child->pivot->default_cost_price ?? $child->unit_price,
                                    'selling_price' => $child->getPriceForRole($role ?: 'default'),
                                    'currency' => $child->currency,
                                ]);
                                
                                Log::info('Added conditional child article after re-evaluation', [
                                    'quotation_id' => $quotationRequest->id,
                                    'parent_id' => $parentArticle->article_cache_id,
                                    'child_id' => $child->id,
                                    'child_name' => $child->article_name,
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Failed to add conditional child article after re-evaluation', [
                                    'quotation_id' => $quotationRequest->id,
                                    'parent_id' => $parentArticle->article_cache_id,
                                    'child_id' => $child->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        } elseif (!$shouldExist && $existingChild) {
                            // Conditions don't match but article exists - remove it
                            try {
                                $existingChild->delete();
                                
                                Log::info('Removed conditional child article after re-evaluation', [
                                    'quotation_id' => $quotationRequest->id,
                                    'parent_id' => $parentArticle->article_cache_id,
                                    'child_id' => $child->id,
                                    'child_name' => $child->article_name,
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Failed to remove conditional child article after re-evaluation', [
                                    'quotation_id' => $quotationRequest->id,
                                    'parent_id' => $parentArticle->article_cache_id,
                                    'child_id' => $child->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error re-evaluating conditional child articles for parent', [
                        'quotation_id' => $quotationRequest->id,
                        'parent_article_id' => $parentArticle->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in reevaluateConditionalChildArticles', [
                'quotation_id' => $quotationRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Don't throw - allow quotation to save even if re-evaluation fails
        }
    }

    /**
     * Process hinterland waivers when POD or in_transit_to changes
     * Hinterland waivers are handled by WaiverService using quotation logic
     * (not database attachments like port-specific waivers)
     * 
     * Processes when POD or in_transit_to changes, or when these fields are set
     * (for new quotations where wasChanged might not detect initial values)
     */
    protected function processHinterlandWaivers(QuotationRequest $quotationRequest): void
    {
        $podChanged = $quotationRequest->wasChanged('pod');
        $inTransitToChanged = $quotationRequest->wasChanged('in_transit_to');
        $hasInTransitTo = !empty($quotationRequest->in_transit_to);
        $hasPod = !empty($quotationRequest->pod);
        
        // Process if POD or in_transit_to changed, or if they are set
        // (handles both changes and initial saves with these fields set)
        if (!$podChanged && !$inTransitToChanged && !$hasInTransitTo && !$hasPod) {
            return;
        }
        
        try {
            $this->waiverService->processHinterlandWaivers($quotationRequest);
        } catch (\Exception $e) {
            Log::error('Error processing hinterland waivers', [
                'quotation_id' => $quotationRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Don't throw - allow quotation to save even if waiver processing fails
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
