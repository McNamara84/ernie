<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurationAccordionRequest extends FormRequest
{
    /**
     * Keep in sync with resources/js/lib/curation-accordion.ts.
     * CurationAccordionPreferenceTest compares the backend list with the frontend constants.
     */
    public const ALLOWED_OPEN_ITEMS = [
        'resource-info',
        'licenses-rights',
        'authors',
        'contributors',
        'descriptions',
        'controlled-vocabularies',
        'free-keywords',
        'msl-laboratories',
        'spatial-temporal-coverage',
        'dates',
        'related-work',
        'citations',
        'used-instruments',
        'funding-references',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('open_items')) {
            $this->merge(['open_items' => []]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'open_items' => ['present', 'array'],
            'open_items.*' => ['string', 'distinct', Rule::in(self::ALLOWED_OPEN_ITEMS)],
        ];
    }
}
