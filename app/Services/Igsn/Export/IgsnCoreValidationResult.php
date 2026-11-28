<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

final readonly class IgsnCoreValidationResult
{
    /** @param list<string> $errors */
    public function __construct(public array $errors = []) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
