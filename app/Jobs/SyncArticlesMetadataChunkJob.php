<?php

namespace App\Jobs;

use App\Services\Robaws\RobawsArticleProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncArticlesMetadataChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public $tries = 2;
    public $timeout = 300;

    /**
     * @param array<int> $articleIds
     */
    public function __construct(
        public array $articleIds,
        public bool $useApi = false
    ) {}

    public function handle(RobawsArticleProvider $provider): void
    {
        Log::info('Syncing metadata chunk', [
            'count' => count($this->articleIds),
            'use_api' => $this->useApi,
        ]);

        foreach ($this->articleIds as $articleId) {
            try {
                $provider->syncArticleMetadata($articleId, useApi: $this->useApi);
            } catch (\Exception $e) {
                Log::warning('Failed to sync metadata in chunk', [
                    'article_id' => $articleId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
