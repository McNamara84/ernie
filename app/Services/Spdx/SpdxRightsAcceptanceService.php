<?php

declare(strict_types=1);

namespace App\Services\Spdx;

use App\Models\AssistantSuggestion;
use App\Models\ResourceRight;
use App\Models\Right;
use Illuminate\Support\Facades\DB;

/**
 * Applies accepted SPDX rights suggestions.
 *
 * The important design rule is: accepting a suggestion links one rights
 * statement to one shared SPDX catalog right. It does not rewrite the raw
 * imported statement and it does not overwrite curator-maintained catalog
 * fields that already contain values.
 *
 * If the resource already has the same catalog right selected, acceptance
 * folds empty raw context into that linked row and removes the redundant
 * unresolved row. This keeps the database's one-catalog-link-per-resource
 * invariant intact.
 */
final class SpdxRightsAcceptanceService
{
    private const string TARGET_TYPE = 'resource_right';

    /**
     * @return array{success: bool, message: string}
     */
    public function accept(AssistantSuggestion $suggestion): array
    {
        $validation = $this->validatedPayload($suggestion);

        if ($validation['success'] === false) {
            return $validation;
        }

        /** @var array{identifier: string, name: string, rights_uri: string|null, scheme_uri: string, language: string|null} $payload */
        $payload = $validation['payload'];

        return DB::transaction(function () use ($suggestion, $payload): array {
            $resourceRight = ResourceRight::query()
                ->whereKey($suggestion->target_id)
                ->where('resource_id', $suggestion->resource_id)
                ->lockForUpdate()
                ->first();

            if (! $resourceRight instanceof ResourceRight) {
                return [
                    'success' => false,
                    'message' => 'The rights statement for this SPDX suggestion no longer exists.',
                ];
            }

            $right = $this->findOrCreateSpdxRight($payload);

            if ($resourceRight->rights_id === $right->id) {
                $this->fillStatementLanguage($resourceRight, $payload);

                return [
                    'success' => true,
                    'message' => "Rights statement is already linked to SPDX license {$right->identifier}.",
                ];
            }

            if ($resourceRight->rights_id !== null) {
                return [
                    'success' => false,
                    'message' => 'This rights statement is already linked to a different catalog right. Please refresh the assistant list.',
                ];
            }

            /** @var ResourceRight|null $existingLinkedStatement */
            $existingLinkedStatement = ResourceRight::query()
                ->where('resource_id', $resourceRight->resource_id)
                ->where('rights_id', $right->id)
                ->where('id', '!=', $resourceRight->id)
                ->lockForUpdate()
                ->first();

            if ($existingLinkedStatement instanceof ResourceRight) {
                $this->mergeRawStatement($resourceRight, $existingLinkedStatement, $payload);
                $resourceRight->delete();

                return [
                    'success' => true,
                    'message' => "Merged imported rights statement with existing SPDX license {$right->identifier}.",
                ];
            }

            // Only the link and optional statement-level language are accepted
            // here. The raw imported text/URI remain as audit context and as a
            // teaching example for how assistants can preserve source evidence.
            $resourceRight->rights_id = $right->id;

            $this->fillStatementLanguage($resourceRight, $payload);

            $resourceRight->save();

            return [
                'success' => true,
                'message' => "Linked rights statement to SPDX license {$right->identifier}.",
            ];
        });
    }

    /**
     * Validate the suggestion payload before touching the database.
     *
     * @return array{success: false, message: string}|array{success: true, payload: array{identifier: string, name: string, rights_uri: string|null, scheme_uri: string, language: string|null}}
     */
    private function validatedPayload(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type !== self::TARGET_TYPE) {
            return [
                'success' => false,
                'message' => 'This SPDX suggestion targets an unsupported entity type.',
            ];
        }

        $metadata = $suggestion->metadata ?? [];
        $proposed = is_array($metadata['proposed'] ?? null) ? $metadata['proposed'] : [];

        if (($metadata['action'] ?? null) !== 'link_right' || ($metadata['source'] ?? null) !== 'spdx') {
            return [
                'success' => false,
                'message' => 'This suggestion is missing trusted SPDX acceptance metadata.',
            ];
        }

        $identifier = $this->filledString($proposed['rights_identifier'] ?? $suggestion->suggested_value);
        $name = $this->filledString($proposed['rights'] ?? $suggestion->suggested_label);
        $rightsIdentifierScheme = $this->filledString($proposed['rights_identifier_scheme'] ?? null);
        $schemeUri = $this->filledString($proposed['scheme_uri'] ?? null) ?? SpdxLicenseLookup::SCHEME_URI;

        if ($identifier === null || $name === null) {
            return [
                'success' => false,
                'message' => 'This suggestion does not contain a complete SPDX license identifier and name.',
            ];
        }

        if ($rightsIdentifierScheme !== SpdxLicenseLookup::RIGHTS_IDENTIFIER_SCHEME || $schemeUri !== SpdxLicenseLookup::SCHEME_URI) {
            return [
                'success' => false,
                'message' => 'Only SPDX rights suggestions can be accepted by this assistant.',
            ];
        }

        if ($identifier !== $suggestion->suggested_value) {
            return [
                'success' => false,
                'message' => 'The suggestion value and proposed SPDX identifier do not match.',
            ];
        }

        return [
            'success' => true,
            'payload' => [
                'identifier' => $identifier,
                'name' => $name,
                'rights_uri' => $this->filledString($proposed['rights_uri'] ?? null),
                'scheme_uri' => $schemeUri,
                'language' => $this->filledString($proposed['language'] ?? null),
            ],
        ];
    }

    /**
     * @param  array{identifier: string, name: string, rights_uri: string|null, scheme_uri: string, language: string|null}  $payload
     */
    private function findOrCreateSpdxRight(array $payload): Right
    {
        /** @var Right|null $right */
        $right = Right::query()
            ->where('identifier', $payload['identifier'])
            ->lockForUpdate()
            ->first();

        if (! $right instanceof Right) {
            /** @var Right $created */
            $created = Right::query()->create([
                'identifier' => $payload['identifier'],
                'name' => $payload['name'],
                'uri' => $payload['rights_uri'],
                'scheme_uri' => $payload['scheme_uri'],
                'is_active' => true,
                'is_elmo_active' => true,
            ]);

            return $created;
        }

        $dirty = false;

        foreach ([
            'name' => $payload['name'],
            'uri' => $payload['rights_uri'],
            'scheme_uri' => $payload['scheme_uri'],
        ] as $column => $value) {
            if ($value === null) {
                continue;
            }

            // Existing catalog values are treated as curator-maintained. We
            // fill gaps only, which prevents one resource-level acceptance from
            // silently changing exports for every resource using that catalog row.
            if ($this->filledString($right->{$column}) === null) {
                $right->{$column} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $right->save();
        }

        return $right;
    }

    /**
     * @param  array{identifier: string, name: string, rights_uri: string|null, scheme_uri: string, language: string|null}  $payload
     */
    private function fillStatementLanguage(ResourceRight $resourceRight, array $payload): void
    {
        if ($payload['language'] === null) {
            return;
        }

        $resourceRight->language = $payload['language'];

        if ($resourceRight->isDirty()) {
            $resourceRight->save();
        }
    }

    /**
     * @param  array{identifier: string, name: string, rights_uri: string|null, scheme_uri: string, language: string|null}  $payload
     */
    private function mergeRawStatement(ResourceRight $source, ResourceRight $target, array $payload): void
    {
        foreach ([
            'rights_text',
            'rights_uri',
            'rights_identifier',
            'rights_identifier_scheme',
            'scheme_uri',
            'source',
        ] as $column) {
            $value = $this->filledString($source->{$column});

            if ($value !== null && $this->filledString($target->{$column}) === null) {
                $target->{$column} = $value;
            }
        }

        if ($this->filledString($target->language) === null) {
            $target->language = $this->filledString($source->language) ?? $payload['language'];
        }

        if ($target->isDirty()) {
            $target->save();
        }
    }

    private function filledString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
