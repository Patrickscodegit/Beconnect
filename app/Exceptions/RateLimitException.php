<?php

namespace App\Exceptions;

use Exception;

class RateLimitException extends Exception
{
    protected ?int $retryAfter;

    public function __construct(
        string $message = "Rate limit exceeded",
        int $code = 429,
        ?Exception $previous = null,
        ?int $retryAfter = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->retryAfter = $retryAfter;
    }

    public static function exceeded(int $retryAfter): self
    {
        return new self(
            "Robaws rate limit exceeded. Retry after {$retryAfter} seconds.",
            429,
            null,
            $retryAfter
        );
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

