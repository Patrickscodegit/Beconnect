<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\Extraction\Results\ExtractionResult;

interface ExtractionStrategy
{
    /**
     * Get the strategy name
     */
    public function getName(): string;

    /**
     * Check if this strategy supports the given document
     */
    public function supports(Document $document): bool;

    /**
     * Extract data from the document
     */
    public function extract(Document $document): ExtractionResult;

    /**
     * Get the priority of this strategy (higher = more preferred)
     */
    public function getPriority(): int;
}
