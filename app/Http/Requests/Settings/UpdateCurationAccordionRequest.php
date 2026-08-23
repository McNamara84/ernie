<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurationAccordionRequest extends FormRequest
{
    private const LEGACY_NON_COLLAPSIBLE_ITEM = 'resource-info';

    /**
     * Keep in sync with resources/js/lib/curation-accordion.ts.
     * CurationAccordionPreferenceTest compares the backend list with the frontend constants.
     */
    public const ALLOWED_OPEN_ITEMS = [
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

            return;
        }

        $openItems = $this->input('open_items');
        if (! is_array($openItems)) {
            return;
        }

        $this->merge([
            'open_items' => array_values(array_filter(
                $openItems,
                static fn (mixed $item): bool => $item !== self::LEGACY_NON_COLLAPSIBLE_ITEM,
            )),
        ]);
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
            'revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
