<?php

namespace App\Services;

final class ConsentDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {}
}
