<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\DoiSuggestionService;
use App\Support\ResourceImpactFilter;

trait ResolvesResourceImpactFilter
{
    protected function prepareResourceImpactFilterForValidation(): void
    {
        $doi = $this->input('doi');

        if (is_string($doi)) {
            $doi = app(DoiSuggestionService::class)->normalizeDoi($doi);
        }

        $this->merge([
            'doi' => $doi === '' ? null : $doi,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function resourceImpactFilterRules(): array
    {
        return [
            'doi' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! app(DoiSuggestionService::class)->isValidDoiFormat($value)) {
                        $fail('Enter a valid DOI in the format 10.xxxx/xxxxx or https://doi.org/10.xxxx/xxxxx.');
                    }
                },
            ],
            'datacenter_id' => ['nullable', 'integer', 'min:1', 'exists:datacenters,id'],
        ];
    }

    public function resourceImpactFilter(): ResourceImpactFilter
    {
        $doi = $this->validated('doi');
        $datacenterId = $this->validated('datacenter_id');

        return new ResourceImpactFilter(
            doi: is_string($doi) && $doi !== '' ? $doi : null,
            datacenterId: is_numeric($datacenterId) ? (int) $datacenterId : null,
        );
    }
}
