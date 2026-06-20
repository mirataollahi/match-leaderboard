<?php declare(strict_types=1);

namespace App\Exception;

use Exception;

class ValidationException extends Exception
{
    public function __construct(array $errors)
    {
        $message = "validation failed : " . json_encode($errors);
        parent::__construct($message);
    }
}
