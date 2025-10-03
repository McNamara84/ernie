<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
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
        $titles = collect($this->input('titles', []))
            ->map(function ($title) {
                return [
                    'title' => isset($title['title']) ? trim((string) $title['title']) : null,
                    'titleType' => isset($title['titleType']) ? trim((string) $title['titleType']) : null,
                ];
            })
            ->all();

        $licenses = collect($this->input('licenses', []))
            ->map(fn ($license) => trim((string) $license))
            ->filter(fn ($license) => $license !== '')
            ->unique()
            ->values()
            ->all();

        $this->merge([
            'doi' => $this->filled('doi') ? trim((string) $this->input('doi')) : null,
            'year' => $this->filled('year') ? (int) $this->input('year') : null,
            'resourceType' => $this->filled('resourceType') ? (int) $this->input('resourceType') : null,
            'version' => $this->filled('version') ? trim((string) $this->input('version')) : null,
            'language' => $this->filled('language') ? trim((string) $this->input('language')) : null,
            'titles' => $titles,
            'licenses' => $licenses,
        ]);
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $titles = collect($this->input('titles', []));

                $hasMainTitle = $titles->contains(function ($title) {
                    return ($title['titleType'] ?? null) === 'main-title';
                });

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
