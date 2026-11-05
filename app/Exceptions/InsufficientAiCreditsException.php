<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientAiCreditsException extends RuntimeException
{
    public function __construct(public readonly array $balance = [])
    {
        parent::__construct('This business has insufficient AI credits for the requested operation.');
    }
}
