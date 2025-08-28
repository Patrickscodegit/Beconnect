<?php

namespace App\Services\Extraction\Contracts;

interface FieldExtractor
{
    /**
     * Extract specific field data from the document
     * 
     * @param array $data The structured data from the document
     * @param string $content The raw text content
     * @return mixed The extracted field data (can be value object or array with metadata)
     */
    public function extract(array $data, string $content = '');
}
