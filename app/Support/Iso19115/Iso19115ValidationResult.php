<?php

declare(strict_types=1);

namespace App\Support\Iso19115;

final readonly class Iso19115ValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
