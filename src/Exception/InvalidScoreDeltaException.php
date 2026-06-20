<?php

namespace App\Exception;

use Cake\Http\Exception\BadRequestException;

/**
 * Thrown when a score delta value is invalid (e.g. zero, non-integer).
 */
class InvalidScoreDeltaException extends BadRequestException
{
    public function __construct(int|float $delta)
    {
        parent::__construct("Invalid score delta: {$delta}. Must be a non-zero integer.");
    }
}
