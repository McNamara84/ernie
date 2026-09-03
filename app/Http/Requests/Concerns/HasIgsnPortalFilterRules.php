<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait HasIgsnPortalFilterRules
{
    /**
     * @return array<string, list<string>>
     */
    protected function igsnPortalFilterRules(): array
    {
        return [
            'sample_types' => ['sometimes', 'array', 'max:20'],
            'sample_types.*' => ['string', 'max:255'],
            'materials' => ['sometimes', 'array', 'max:20'],
            'materials.*' => ['string', 'max:255'],
            'classifications' => ['sometimes', 'array', 'max:20'],
            'classifications.*' => ['string', 'max:255'],
            'geological_ages' => ['sometimes', 'array', 'max:20'],
            'geological_ages.*' => ['string', 'max:255'],
            'geological_units' => ['sometimes', 'array', 'max:20'],
            'geological_units.*' => ['string', 'max:255'],
        ];
    }
}
