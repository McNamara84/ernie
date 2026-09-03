<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PortalMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $stringOrArray = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) && ! is_array($value)) {
                $fail("The {$attribute} field must be a string or an array.");
            }

            if (is_string($value) && ! in_array($value, ['doi', 'igsn'], true)) {
                $fail("The selected {$attribute} is invalid.");
            }

            if (is_array($value) && count($value) > 20) {
                $fail("The {$attribute} field must not contain more than 20 items.");
            }
        };

        return [
            'viewport' => ['required', 'array'],
            'viewport.north' => ['required', 'numeric', 'between:-90,90'],
            'viewport.south' => ['required', 'numeric', 'between:-90,90'],
            'viewport.east' => ['required', 'numeric', 'between:-180,180'],
            'viewport.west' => ['required', 'numeric', 'between:-180,180'],
            'viewport.width' => ['required', 'integer', 'between:1,4096'],
            'viewport.height' => ['required', 'integer', 'between:1,4096'],
            'zoom' => ['required', 'integer', 'between:0,18'],
            'include_extent' => ['sometimes', 'boolean'],
            'north' => ['nullable', 'numeric', 'between:-90,90'],
            'south' => ['nullable', 'numeric', 'between:-90,90'],
            'east' => ['nullable', 'numeric', 'between:-180,180'],
            'west' => ['nullable', 'numeric', 'between:-180,180'],
            'q' => ['nullable', 'string', 'max:500'],
            'type' => ['sometimes', $stringOrArray],
            'type.*' => ['string', 'max:255'],
            'keywords' => ['sometimes', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:255'],
            'free_keywords' => ['sometimes', 'array', 'max:20'],
            'free_keywords.*' => ['string', 'max:255'],
            'thesaurus_keywords' => ['sometimes', 'array', 'max:20'],
            'thesaurus_keywords.*' => ['string', 'max:2048'],
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
            'datacenter' => ['sometimes', 'array', 'max:20'],
            'datacenter.*' => ['string', 'max:255'],
            'date_type' => ['nullable', 'string', 'in:Created,Collected,Coverage'],
            'year_from' => ['nullable', 'integer', 'between:1900,'.((int) date('Y') + 1)],
            'year_to' => ['nullable', 'integer', 'between:1900,'.((int) date('Y') + 1)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $north = $this->input('viewport.north');
            $south = $this->input('viewport.south');

            if (is_numeric($north) && is_numeric($south) && (float) $north < (float) $south) {
                $validator->errors()->add('viewport.north', 'The viewport north value must be greater than or equal to south.');
            }

            $filterBounds = collect(['north', 'south', 'east', 'west'])
                ->mapWithKeys(fn (string $key): array => [$key => $this->input($key)]);

            if ($filterBounds->filter(fn (mixed $value): bool => $value !== null)->isNotEmpty()
                && $filterBounds->contains(fn (mixed $value): bool => $value === null)) {
                $validator->errors()->add('north', 'All four spatial filter bounds must be provided together.');
            }

            if (is_numeric($filterBounds['north']) && is_numeric($filterBounds['south'])
                && (float) $filterBounds['north'] < (float) $filterBounds['south']) {
                $validator->errors()->add('north', 'The north filter value must be greater than or equal to south.');
            }

            $yearFrom = $this->input('year_from');
            $yearTo = $this->input('year_to');

            if (is_numeric($yearFrom) && is_numeric($yearTo) && (int) $yearFrom > (int) $yearTo) {
                $validator->errors()->add('year_from', 'The start year must be before or equal to the end year.');
            }
        });
    }

    /**
     * @return array{north: float, south: float, east: float, west: float, width: int, height: int}
     */
    public function viewport(): array
    {
        /** @var array<string, mixed> $viewport */
        $viewport = $this->validated('viewport');

        return [
            'north' => (float) $viewport['north'],
            'south' => (float) $viewport['south'],
            'east' => (float) $viewport['east'],
            'west' => (float) $viewport['west'],
            'width' => (int) $viewport['width'],
            'height' => (int) $viewport['height'],
        ];
    }

    public function zoom(): int
    {
        return (int) $this->validated('zoom');
    }

    public function includeExtent(): bool
    {
        return $this->boolean('include_extent');
    }
}
