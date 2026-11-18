<?php

namespace App\Exceptions;

use RuntimeException;

/** Describes an order POST that Salla may have received but did not confirm. */
class SallaOrderSubmissionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isDefinitiveRejection(): bool
    {
        return $this->httpStatus !== null && $this->httpStatus >= 400 && $this->httpStatus < 500;
    }
}
