<?php

namespace App\Exception;

use Cake\Http\Exception\NotFoundException;

/**
 * Thrown when a user entity cannot be found by ID or username.
 */
class UserNotFoundException extends NotFoundException
{
    public function __construct(mixed $identifier)
    {
        parent::__construct("User not found: {$identifier}");
    }
}
