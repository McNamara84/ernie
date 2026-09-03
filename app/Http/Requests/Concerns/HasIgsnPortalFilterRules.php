<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\PortalScope;

trait HasIgsnPortalFilterRules
{
    /**
     * @return array<string, list<string>>
     */
    protected function igsnPortalFilterRules(): array
    {
        if ($this->route('portalScope') !== PortalScope::IGSN->value) {
            return [];
        }

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
