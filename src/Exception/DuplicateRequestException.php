<?php

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when a score_log with the same request_id already exists.
 * Signals the caller to return the idempotent (cached) result.
 */
class DuplicateRequestException extends RuntimeException {}
