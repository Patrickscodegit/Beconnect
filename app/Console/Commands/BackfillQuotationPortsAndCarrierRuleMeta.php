<?php

namespace App\Console\Commands;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Services\Ports\PortResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillQuotationPortsAndCarrierRuleMeta extends Command
{
    protected $signature = 'quotations:backfill-ports-and-carrier-rule-meta {--dry-run} {--chunk=200}';
    protected $description = 'Backfill quotation port IDs and carrier rule linkage columns from legacy string/notes data';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk') ?: 200;
        $resolver = app(PortResolutionService::class);

        $this->info('Backfilling quotation port IDs...');

        QuotationRequest::query()
            ->where(function ($query) {
                $query->whereNull('pol_port_id')
                    ->orWhereNull('pod_port_id');
            })
            ->where(function ($query) {
                $query->whereNotNull('pol')
                    ->orWhereNotNull('pod');
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($quotations) use ($resolver, $dryRun) {
                foreach ($quotations as $quotation) {
                    $mode = ($quotation->simple_service_type === 'AIR') ? 'AIR' : 'SEA';
                    $polPortId = $quotation->pol_port_id;
                    $podPortId = $quotation->pod_port_id;

                    if (!$polPortId && $quotation->pol) {
                        $polPortId = $resolver->resolveOne($quotation->pol, $mode)?->id;
                    }
                    if (!$podPortId && $quotation->pod) {
                        $podPortId = $resolver->resolveOne($quotation->pod, $mode)?->id;
                    }

                    if ($polPortId || $podPortId) {
                        $this->line(sprintf(
                            'Quotation %s: pol_port_id=%s pod_port_id=%s',
                            $quotation->id,
                            $polPortId ?? 'null',
                            $podPortId ?? 'null'
                        ));

                        if (!$dryRun) {
                            $quotation->pol_port_id = $polPortId;
                            $quotation->pod_port_id = $podPortId;
                            $quotation->saveQuietly();
                        }
                    }
                }
            });

        if (!Schema::hasColumn('quotation_request_articles', 'carrier_rule_applied')) {
            $this->warn('Carrier rule columns not found, skipping carrier rule backfill.');
            return Command::SUCCESS;
        }

        $this->info('Backfilling carrier rule linkage columns...');

        QuotationRequestArticle::query()
            ->where(function ($query) {
                $query->whereNull('carrier_rule_applied')
                    ->orWhereNull('carrier_rule_event_code')
                    ->orWhereNull('carrier_rule_commodity_item_id');
            })
            ->where('notes', 'like', '%"carrier_rule_applied":true%')
            ->orderBy('id')
            ->chunkById($chunk, function ($articles) use ($dryRun) {
                foreach ($articles as $article) {
                    $notes = json_decode($article->notes ?? '{}', true);
                    if (!is_array($notes)) {
                        continue;
                    }

                    $carrierRuleApplied = $notes['carrier_rule_applied'] ?? null;
                    $eventCode = $notes['event_code'] ?? null;
                    $commodityItemId = $notes['commodity_item_id'] ?? null;

                    if ($carrierRuleApplied || $eventCode || $commodityItemId) {
                        $this->line(sprintf(
                            'Article %s: carrier_rule_applied=%s event_code=%s commodity_item_id=%s',
                            $article->id,
                            $carrierRuleApplied ? 'true' : 'false',
                            $eventCode ?? 'null',
                            $commodityItemId ?? 'null'
                        ));

                        if (!$dryRun) {
                            $article->carrier_rule_applied = (bool) $carrierRuleApplied;
                            $article->carrier_rule_event_code = $eventCode;
                            $article->carrier_rule_commodity_item_id = $commodityItemId;
                            $article->saveQuietly();
                        }
                    }
                }
            });

        return Command::SUCCESS;
    }
}
