<?php

namespace App\Exceptions;

use RuntimeException;

class AiProviderRequestException extends RuntimeException
{
    public function __construct(string $provider, int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "{$provider} AI request failed (HTTP {$status}).", $status);
    }

    /** An explicit client response means the provider did not accept billable generation. */
    public function isDefinitelyUnbilled(): bool
    {
        return $this->getCode() >= 400 && $this->getCode() < 500 && $this->getCode() !== 408;
    }

    public function isRetryableUnbilled(): bool
    {
        return in_array($this->getCode(), [404, 429], true);
    }
}
