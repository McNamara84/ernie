<?php

declare(strict_types=1);

namespace App\Http\Requests\RelatedItem;

use App\Models\RelatedItem;
use App\Rules\HasMainTitle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRelatedItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by ResourcePolicy@update in the controller.
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currentYear = (int) date('Y');

        return [
            'related_item_type' => ['required', 'string', Rule::exists('resource_types', 'slug')],
            'relation_type_id' => ['required', 'integer', Rule::exists('relation_types', 'id')],

            'titles' => ['required', 'array', 'min:1', new HasMainTitle()],
            'titles.*.title' => ['required', 'string', 'max:512'],
            'titles.*.title_type' => ['required', Rule::in(RelatedItem::TITLE_TYPES)],
            'titles.*.language' => ['nullable', 'string', 'max:8'],

            'publication_year' => ['nullable', 'integer', 'between:1000,' . ($currentYear + 5)],
            'volume' => ['nullable', 'string', 'max:64'],
            'issue' => ['nullable', 'string', 'max:64'],
            'number' => ['nullable', 'string', 'max:64'],
            'number_type' => ['nullable', Rule::in(RelatedItem::NUMBER_TYPES)],
            'first_page' => ['nullable', 'string', 'max:32'],
            'last_page' => ['nullable', 'string', 'max:32'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'edition' => ['nullable', 'string', 'max:64'],

            'identifier' => ['nullable', 'string', 'max:2183'],
            'identifier_type' => ['nullable', Rule::in(RelatedItem::IDENTIFIER_TYPES)],

            'creators' => ['nullable', 'array'],
            'creators.*.name_type' => ['required_with:creators', Rule::in(RelatedItem::NAME_TYPES)],
            'creators.*.name' => ['required_with:creators', 'string', 'max:255'],
            'creators.*.given_name' => ['nullable', 'string', 'max:255'],
            'creators.*.family_name' => ['nullable', 'string', 'max:255'],
            'creators.*.name_identifier' => ['nullable', 'string', 'max:255'],
            'creators.*.name_identifier_scheme' => ['nullable', Rule::in(RelatedItem::NAME_IDENTIFIER_SCHEMES)],
            'creators.*.affiliations' => ['nullable', 'array'],
            'creators.*.affiliations.*.name' => ['required_with:creators.*.affiliations', 'string', 'max:255'],
            'creators.*.affiliations.*.affiliation_identifier' => ['nullable', 'string', 'max:255'],
            'creators.*.affiliations.*.scheme' => ['nullable', 'string', 'max:32'],

            'contributors' => ['nullable', 'array'],
            'contributors.*.contributor_type' => ['required_with:contributors', 'string', 'max:64'],
            'contributors.*.name_type' => ['required_with:contributors', Rule::in(RelatedItem::NAME_TYPES)],
            'contributors.*.name' => ['required_with:contributors', 'string', 'max:255'],
            'contributors.*.given_name' => ['nullable', 'string', 'max:255'],
            'contributors.*.family_name' => ['nullable', 'string', 'max:255'],
            'contributors.*.name_identifier' => ['nullable', 'string', 'max:255'],
            'contributors.*.name_identifier_scheme' => ['nullable', Rule::in(RelatedItem::NAME_IDENTIFIER_SCHEMES)],
            'contributors.*.affiliations' => ['nullable', 'array'],
            'contributors.*.affiliations.*.name' => ['required_with:contributors.*.affiliations', 'string', 'max:255'],
        ];
    }
}
