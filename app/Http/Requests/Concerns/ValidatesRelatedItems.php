<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\RelatedItem;
use App\Models\ResourceType;
use App\Rules\HasMainTitle;
use Illuminate\Validation\Rule;

trait ValidatesRelatedItems
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function relatedItemRules(): array
    {
        return [
            // Related Item Manager: inline <relatedItem> metadata (DataCite 4.7).
            'relatedItems' => ['nullable', 'array'],
            // Canonical DataCite `resourceTypeGeneral` enum (PascalCase, no
            // spaces -- e.g. `JournalArticle`); kept in sync with
            // `StoreRelatedItemRequest` and the vocabularies endpoint via
            // `ResourceType::slugToDataciteResourceTypeGeneral()` (and the
            // matching instance helper `dataciteResourceTypeGeneral()`).
            'relatedItems.*.related_item_type' => ['required_with:relatedItems', 'string', Rule::in(ResourceType::activeDataciteResourceTypesGeneral())],
            'relatedItems.*.relation_type_slug' => ['required_with:relatedItems', 'string', Rule::exists('relation_types', 'slug')],
            'relatedItems.*.titles' => ['required_with:relatedItems', 'array', 'min:1', new HasMainTitle],
            'relatedItems.*.titles.*.title' => ['required', 'string', 'max:512'],
            'relatedItems.*.titles.*.title_type' => ['required', Rule::in(RelatedItem::TITLE_TYPES)],
            'relatedItems.*.titles.*.language' => ['nullable', 'string', 'max:8'],
            // Mirror `StoreRelatedItemRequest`: allow up to current year + 5
            // for forthcoming publications. Using a fixed `9999` upper bound
            // here would let resources save a year that subsequent edits via
            // the related-items REST endpoints would reject.
            'relatedItems.*.publication_year' => ['nullable', 'integer', 'between:1000,'.((int) date('Y') + 5)],
            'relatedItems.*.volume' => ['nullable', 'string', 'max:64'],
            'relatedItems.*.issue' => ['nullable', 'string', 'max:64'],
            'relatedItems.*.number' => ['nullable', 'string', 'max:64'],
            'relatedItems.*.number_type' => ['nullable', Rule::in(RelatedItem::NUMBER_TYPES)],
            'relatedItems.*.first_page' => ['nullable', 'string', 'max:32'],
            'relatedItems.*.last_page' => ['nullable', 'string', 'max:32'],
            'relatedItems.*.publisher' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.edition' => ['nullable', 'string', 'max:64'],
            // Keep identifier and identifier_type symmetrically required so a
            // related item cannot be persisted with a value but no type (which
            // would make landing-page link resolution and DataCite XML export
            // ambiguous). Mirrors `StoreRelatedItemRequest`.
            'relatedItems.*.identifier' => ['nullable', 'required_with:relatedItems.*.identifier_type', 'string', 'max:2183'],
            'relatedItems.*.identifier_type' => ['nullable', 'required_with:relatedItems.*.identifier', Rule::in(RelatedItem::IDENTIFIER_TYPES)],
            'relatedItems.*.related_metadata_scheme' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.relation_type_information' => ['nullable', 'string'],
            'relatedItems.*.scheme_uri' => ['nullable', 'string', 'max:512'],
            'relatedItems.*.scheme_type' => ['nullable', 'string', 'max:64'],
            'relatedItems.*.position' => ['nullable', 'integer', 'min:0'],
            'relatedItems.*.creators' => ['nullable', 'array'],
            'relatedItems.*.creators.*.name_type' => ['required_with:relatedItems.*.creators', Rule::in(RelatedItem::NAME_TYPES)],
            'relatedItems.*.creators.*.name' => ['required_with:relatedItems.*.creators', 'string', 'max:255'],
            'relatedItems.*.creators.*.given_name' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.creators.*.family_name' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.creators.*.name_identifier' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.creators.*.name_identifier_scheme' => ['nullable', Rule::in(RelatedItem::NAME_IDENTIFIER_SCHEMES)],
            'relatedItems.*.creators.*.scheme_uri' => ['nullable', 'string', 'max:512'],
            'relatedItems.*.creators.*.affiliations' => ['nullable', 'array'],
            'relatedItems.*.creators.*.affiliations.*.name' => ['required_with:relatedItems.*.creators.*.affiliations', 'string', 'max:255'],
            'relatedItems.*.creators.*.affiliations.*.affiliation_identifier' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.creators.*.affiliations.*.scheme' => ['nullable', 'string', 'max:32'],
            'relatedItems.*.creators.*.affiliations.*.scheme_uri' => ['nullable', 'string', 'max:512'],
            'relatedItems.*.contributors' => ['nullable', 'array'],
            'relatedItems.*.contributors.*.contributor_type' => ['required_with:relatedItems.*.contributors', 'string', 'max:64'],
            'relatedItems.*.contributors.*.name_type' => ['required_with:relatedItems.*.contributors', Rule::in(RelatedItem::NAME_TYPES)],
            'relatedItems.*.contributors.*.name' => ['required_with:relatedItems.*.contributors', 'string', 'max:255'],
            'relatedItems.*.contributors.*.given_name' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.contributors.*.family_name' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.contributors.*.name_identifier' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.contributors.*.name_identifier_scheme' => ['nullable', Rule::in(RelatedItem::NAME_IDENTIFIER_SCHEMES)],
            'relatedItems.*.contributors.*.scheme_uri' => ['nullable', 'string', 'max:512'],
            'relatedItems.*.contributors.*.affiliations' => ['nullable', 'array'],
            'relatedItems.*.contributors.*.affiliations.*.name' => ['required_with:relatedItems.*.contributors.*.affiliations', 'string', 'max:255'],
            'relatedItems.*.contributors.*.affiliations.*.affiliation_identifier' => ['nullable', 'string', 'max:255'],
            'relatedItems.*.contributors.*.affiliations.*.scheme' => ['nullable', 'string', 'max:32'],
            'relatedItems.*.contributors.*.affiliations.*.scheme_uri' => ['nullable', 'string', 'max:512'],
        ];
    }
}
