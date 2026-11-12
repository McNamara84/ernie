<?php

declare(strict_types=1);

namespace App\Support;

final readonly class DataCiteRegistrationEvidence
{
    public function __construct(
        public string $doi,
        public string $state,
        public string $repository,
        public string $endpoint,
        public bool $testMode,
        public ?string $url,
    ) {}

    public function isRegistered(): bool
    {
        return in_array($this->state, ['registered', 'findable'], true);
    }
}
