<?php

namespace App\Exceptions;

use Exception;

class RateLimitException extends Exception
{
    public function __construct(string $message = "Rate limit exceeded", int $code = 429, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function exceeded(int $retryAfter): self
    {
        return new self("Robaws rate limit exceeded. Retry after {$retryAfter} seconds.");
    }
}

