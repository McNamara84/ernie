<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ContributorCategory;
use App\Models\PidSetting;
use App\Models\ThesaurusSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $categoryValues = array_column(ContributorCategory::cases(), 'value');

        return [
            'resourceTypes' => ['required', 'array'],
            'resourceTypes.*.id' => ['required', 'integer', 'exists:resource_types,id'],
            'resourceTypes.*.name' => ['required', 'string'],
            'resourceTypes.*.active' => ['required', 'boolean'],
            'resourceTypes.*.elmo_active' => ['required', 'boolean'],
            'titleTypes' => ['required', 'array'],
            'titleTypes.*.id' => ['required', 'integer', 'exists:title_types,id'],
            'titleTypes.*.name' => ['required', 'string'],
            'titleTypes.*.slug' => ['required', 'string'],
            'titleTypes.*.active' => ['required', 'boolean'],
            'titleTypes.*.elmo_active' => ['required', 'boolean'],
            'licenses' => ['required', 'array'],
            'licenses.*.id' => ['required', 'integer', 'exists:rights,id'],
            'licenses.*.active' => ['required', 'boolean'],
            'licenses.*.elmo_active' => ['required', 'boolean'],
            'licenses.*.excluded_resource_type_ids' => ['present', 'array'],
            'licenses.*.excluded_resource_type_ids.*' => ['integer', 'exists:resource_types,id'],
            'languages' => ['required', 'array'],
            'languages.*.id' => ['required', 'integer', 'exists:languages,id'],
            'languages.*.active' => ['required', 'boolean'],
            'languages.*.elmo_active' => ['required', 'boolean'],
            'dateTypes' => ['required', 'array'],
            'dateTypes.*.id' => ['required', 'integer', 'exists:date_types,id'],
            'dateTypes.*.active' => ['required', 'boolean'],
            'descriptionTypes' => ['required', 'array'],
            'descriptionTypes.*.id' => ['required', 'integer', 'exists:description_types,id'],
            'descriptionTypes.*.active' => ['required', 'boolean'],
            'descriptionTypes.*.elmo_active' => ['required', 'boolean'],
            'maxTitles' => ['required', 'integer', 'min:1'],
            'maxLicenses' => ['required', 'integer', 'min:1'],
            // Thesaurus settings (optional - only sent when thesauri card is present)
            'thesauri' => ['sometimes', 'array'],
            'thesauri.*.type' => ['required', 'string', Rule::in(ThesaurusSetting::getValidTypes())],
            'thesauri.*.isActive' => ['required', 'boolean'],
            'thesauri.*.isElmoActive' => ['required', 'boolean'],
            // PID settings (optional - only sent when PID settings card is present)
            'pidSettings' => ['sometimes', 'array'],
            'pidSettings.*.type' => ['required', 'string', Rule::in(PidSetting::getValidTypes())],
            'pidSettings.*.isActive' => ['required', 'boolean'],
            'pidSettings.*.isElmoActive' => ['required', 'boolean'],
            // Contributor roles (optional - only sent when contributor role cards are present)
            'contributorPersonRoles' => ['sometimes', 'array'],
            'contributorPersonRoles.*.id' => ['required', 'integer', 'exists:contributor_types,id'],
            'contributorPersonRoles.*.active' => ['required', 'boolean'],
            'contributorPersonRoles.*.elmo_active' => ['required', 'boolean'],
            'contributorPersonRoles.*.category' => ['required', 'string', Rule::in($categoryValues)],
            'contributorInstitutionRoles' => ['sometimes', 'array'],
            'contributorInstitutionRoles.*.id' => ['required', 'integer', 'exists:contributor_types,id'],
            'contributorInstitutionRoles.*.active' => ['required', 'boolean'],
            'contributorInstitutionRoles.*.elmo_active' => ['required', 'boolean'],
            'contributorInstitutionRoles.*.category' => ['required', 'string', Rule::in($categoryValues)],
            'contributorBothRoles' => ['sometimes', 'array'],
            'contributorBothRoles.*.id' => ['required', 'integer', 'exists:contributor_types,id'],
            'contributorBothRoles.*.active' => ['required', 'boolean'],
            'contributorBothRoles.*.elmo_active' => ['required', 'boolean'],
            'contributorBothRoles.*.category' => ['required', 'string', Rule::in($categoryValues)],
            // Relation types (optional)
            'relationTypes' => ['sometimes', 'array'],
            'relationTypes.*.id' => ['required', 'integer', 'exists:relation_types,id'],
            'relationTypes.*.active' => ['required', 'boolean'],
            'relationTypes.*.elmo_active' => ['required', 'boolean'],
            // Identifier types with patterns (optional)
            'identifierTypes' => ['sometimes', 'array'],
            'identifierTypes.*.id' => ['required', 'integer', 'exists:identifier_types,id'],
            'identifierTypes.*.active' => ['required', 'boolean'],
            'identifierTypes.*.elmo_active' => ['required', 'boolean'],
            'identifierTypes.*.patterns' => ['sometimes', 'array'],
            'identifierTypes.*.patterns.*.id' => ['required', 'integer', 'exists:identifier_type_patterns,id'],
            'identifierTypes.*.patterns.*.pattern' => ['required', 'string', 'max:500'],
            'identifierTypes.*.patterns.*.is_active' => ['required', 'boolean'],
            'identifierTypes.*.patterns.*.priority' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateUniquePatterns($validator);
        });
    }

    private function validateUniquePatterns(Validator $validator): void
    {
        /** @var array<int, array{id: int, patterns?: array<int, array{id: int, pattern: string}>}> $identifierTypes */
        $identifierTypes = $this->input('identifierTypes', []);

        $allPatternIds = [];
        foreach ($identifierTypes as $identifierType) {
            foreach ($identifierType['patterns'] ?? [] as $pattern) {
                $allPatternIds[] = $pattern['id'];
            }
        }

        if ($allPatternIds === []) {
            return;
        }

        $patternRows = DB::table('identifier_type_patterns')
            ->whereIn('id', $allPatternIds)
            ->get(['id', 'identifier_type_id', 'type'])
            ->keyBy('id');

        $identifierTypeIds = array_unique(array_column($identifierTypes, 'id'));
        $existingPatterns = DB::table('identifier_type_patterns')
            ->whereIn('identifier_type_id', $identifierTypeIds)
            ->get(['id', 'identifier_type_id', 'type', 'pattern']);

        /** @var array<string, int> $existingKeyToId */
        $existingKeyToId = [];
        foreach ($existingPatterns as $ep) {
            $key = $ep->identifier_type_id . '|' . $ep->type . '|' . $ep->pattern;
            $existingKeyToId[$key] = (int) $ep->id;
        }

        foreach ($identifierTypes as $itIndex => $identifierType) {
            /** @var array<string, true> $seen */
            $seen = [];
            foreach ($identifierType['patterns'] ?? [] as $pIndex => $pattern) {
                $row = $patternRows[$pattern['id']] ?? null;
                if ($row === null) {
                    continue;
                }

                if ((int) $row->identifier_type_id !== (int) $identifierType['id']) {
                    $validator->errors()->add(
                        "identifierTypes.{$itIndex}.patterns.{$pIndex}.id",
                        'This pattern does not belong to the given identifier type.'
                    );
                    continue;
                }

                $key = $row->type . '|' . $pattern['pattern'];
                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "identifierTypes.{$itIndex}.patterns.{$pIndex}.pattern",
                        'This pattern already exists for this identifier type and type.'
                    );
                } else {
                    $dbKey = $identifierType['id'] . '|' . $row->type . '|' . $pattern['pattern'];
                    $existingId = $existingKeyToId[$dbKey] ?? null;

                    if ($existingId !== null && $existingId !== (int) $pattern['id']) {
                        $validator->errors()->add(
                            "identifierTypes.{$itIndex}.patterns.{$pIndex}.pattern",
                            'This pattern already exists for this identifier type and type.'
                        );
                    }
                }
                $seen[$key] = true;
            }
        }
    }
}
