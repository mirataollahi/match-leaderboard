<?php

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when a client exceeds the configured request rate limit.
 */
class RateLimitExceededException extends RuntimeException
{
    public function __construct(string $userId)
    {
        parent::__construct("Rate limit exceeded for user: {$userId}");
    }
}
