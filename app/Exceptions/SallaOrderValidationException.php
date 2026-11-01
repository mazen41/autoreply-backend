<?php

namespace App\Exceptions;

/**
 * Thrown when Salla's POST /orders (or a related order-creation) call fails
 * with a 422 validation response. Carries the raw field-level errors Salla
 * returned so the caller can determine exactly which ship_to/receiver fields
 * THIS store's checkout actually requires, instead of guessing upfront.
 */
class SallaOrderValidationException extends \Exception
{
    /** @var array<string, mixed> Raw field => error(s) map as returned by Salla. */
    public array $fields;

    public function __construct(string $message, array $fields = [])
    {
        parent::__construct($message);
        $this->fields = $fields;
    }
}
