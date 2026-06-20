<?php declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when a duplicate request_id arrives with a different payload.
 */
class ConflictException extends RuntimeException
{
    public function __construct(string $code = 'REQUEST_ID_CONFLICT', int $httpStatus = 409)
    {
        parent::__construct($code, $httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->getMessage();
    }
}
