<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Rule|string>>
     */
    public function rules(): array
    {
        return [
            'resourceId' => ['nullable', 'integer', Rule::exists('resources', 'id')],
            'doi' => ['nullable', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1000,9999'],
            'resourceType' => ['required', 'integer', Rule::exists('resource_types', 'id')],
            'version' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', Rule::exists('languages', 'code')],
            'titles' => ['required', 'array', 'min:1'],
            'titles.*.title' => ['required', 'string', 'max:255'],
            'titles.*.titleType' => ['required', 'string', Rule::exists('title_types', 'slug')],
            'licenses' => ['required', 'array', 'min:1'],
            'licenses.*' => ['string', 'distinct', Rule::exists('licenses', 'identifier')],
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<int, array<string, mixed>|mixed> $rawTitles */
        $rawTitles = $this->input('titles', []);

        $titles = [];

        foreach ($rawTitles as $title) {
            if (! is_array($title)) {
                $title = [];
            }

            $titles[] = [
                'title' => isset($title['title']) ? trim((string) $title['title']) : null,
                'titleType' => isset($title['titleType']) ? trim((string) $title['titleType']) : null,
            ];
        }

        /** @var array<int, mixed> $rawLicenses */
        $rawLicenses = $this->input('licenses', []);

        $licenses = [];

        foreach ($rawLicenses as $license) {
            $normalized = trim((string) $license);

            if ($normalized === '' || in_array($normalized, $licenses, true)) {
                continue;
            }

            $licenses[] = $normalized;
        }

        $this->merge([
            'doi' => $this->filled('doi') ? trim((string) $this->input('doi')) : null,
            'year' => $this->filled('year') ? (int) $this->input('year') : null,
            'resourceType' => $this->filled('resourceType') ? (int) $this->input('resourceType') : null,
            'version' => $this->filled('version') ? trim((string) $this->input('version')) : null,
            'language' => $this->filled('language') ? trim((string) $this->input('language')) : null,
            'titles' => $titles,
            'licenses' => $licenses,
            'resourceId' => $this->filled('resourceId') ? (int) $this->input('resourceId') : null,
        ]);
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, mixed>|mixed> $candidateTitles */
                $candidateTitles = $this->input('titles', []);

                $hasMainTitle = false;

                foreach ($candidateTitles as $title) {
                    if (! is_array($title)) {
                        continue;
                    }

                    if (($title['titleType'] ?? null) === 'main-title') {
                        $hasMainTitle = true;
                        break;
                    }
                }

                if (! $hasMainTitle) {
                    $validator->errors()->add(
                        'titles',
                        'At least one title must be provided as a Main Title.',
                    );
                }
            },
        ];
    }
}
