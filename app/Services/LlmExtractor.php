<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use OpenAI;
use RuntimeException;

class LlmExtractor
{
    /** Hard guard to avoid oversized payloads (approx characters, not tokens). */
    private const MAX_PAYLOAD_CHARS = 200_000;

    /** Basic retry policy for transient errors (network/5xx). */
    private const MAX_RETRIES = 2;

    public function __construct()
    {
    }

    /**
     * @param array $payload Shape: ['intake_id'=>..., 'documents'=>[['name','mime','text'], ...]]
     * @return array{json: array, confidence: float}
     */
    public function extract(array $payload): array
    {
        $promptPath = storage_path('app/prompts/extractor.txt');
        if (!is_file($promptPath)) {
            throw new RuntimeException("Extractor prompt not found at {$promptPath}");
        }
        $prompt = file_get_contents($promptPath) ?: '';

        // Build user content (JSON string of payload)
        $userContent = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($userContent === false) {
            throw new RuntimeException('Failed to JSON-encode extractor payload.');
        }

        // Guard payload size (prevent runaway inputs)
        if (mb_strlen($userContent, '8bit') > self::MAX_PAYLOAD_CHARS) {
            // Truncate the longest document texts to fit
            $payload = $this->truncatePayload($payload, self::MAX_PAYLOAD_CHARS);
            $userContent = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Simple per-app rate limit: 30 extractions per minute
        $hit = RateLimiter::attempt(
            'openai:extract:' . (string) now()->format('YmdHi'),
            $perMinute = 30,
            function () {
                // no-op, just reserve a slot
            }
        );
        if (!$hit) {
            throw new RuntimeException('Rate limit exceeded for LLM extraction (30/min). Try again shortly.');
        }

        $client = OpenAI::client(config('services.openai.key'));
        $model  = config('services.openai.model', 'gpt-4-turbo-preview');
        $timeout = (int) config('services.openai.timeout', 45);

        $attempts = 0;
        $lastError = null;

        while ($attempts <= self::MAX_RETRIES) {
            try {
                $response = $client->chat()->create([
                    'model' => $model,
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user',   'content' => $userContent],
                    ],
                    // OpenAI PHP client uses Guzzle under the hood; timeout is set via env or default
                ]);

                $content = $response->choices[0]->message->content ?? '';
                if (!is_string($content) || $content === '') {
                    throw new RuntimeException('Empty content returned by LLM.');
                }

                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('LLM did not return valid JSON.');
                }

                // Confidence should be a float 0..1, default to 0 if missing
                $confidence = 0.0;
                if (array_key_exists('confidence', $decoded) && is_numeric($decoded['confidence'])) {
                    $confidence = (float) $decoded['confidence'];
                }

                return ['json' => $decoded, 'confidence' => $confidence];
            } catch (\Throwable $e) {
                $lastError = $e;
                $attempts++;

                // Transient retry with small backoff
                if ($attempts <= self::MAX_RETRIES) {
                    usleep(200_000 * $attempts); // 200ms, then 400ms
                    continue;
                }

                Log::warning('LlmExtractor failed', [
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                    'model' => $model,
                ]);
            }
        }

        throw new RuntimeException('LLM extraction failed after retries: ' . ($lastError?->getMessage() ?? 'unknown error'));
    }

    /**
     * Truncate payload texts to keep total JSON size under the guard.
     * Strategy: cap each document text proportionally, keep names/mimes intact.
     */
    private function truncatePayload(array $payload, int $maxChars): array
    {
        $base = [
            'intake_id' => $payload['intake_id'] ?? null,
            'documents' => [],
        ];

        $docs = $payload['documents'] ?? [];
        if (!is_array($docs) || empty($docs)) {
            return $base;
        }

        // Rough budget for texts
        $perDocBudget = (int) floor($maxChars / max(count($docs), 1) * 0.8); // keep 20% headroom

        foreach ($docs as $d) {
            $name = $d['name'] ?? null;
            $mime = $d['mime'] ?? null;
            $text = (string) ($d['text'] ?? '');

            if (mb_strlen($text, '8bit') > $perDocBudget) {
                $text = mb_substr($text, 0, $perDocBudget, 'UTF-8');
                $text .= "\n\n[NOTE] Truncated for size.";
            }

            $base['documents'][] = [
                'name' => $name,
                'mime' => $mime,
                'text' => $text,
            ];
        }

        return $base;
    }
}
