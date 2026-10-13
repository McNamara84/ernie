<?php

declare(strict_types=1);

namespace App\Services\RelationTypeCorrection;

use App\Models\RelatedIdentifier;

/** DataCite 4.7 vocabulary and deliberately conservative conflict rules. */
final class RelationTypeRules
{
    public const VERSION = 'datacite-4.7-v1';

    /** @var list<string> These relationships describe asymmetric structural roles. */
    public const DIRECTIONAL = [
        'IsPartOf', 'HasPart', 'IsNewVersionOf', 'IsPreviousVersionOf', 'IsVersionOf', 'HasVersion',
        'IsDerivedFrom', 'IsSourceOf', 'IsVariantFormOf', 'IsOriginalFormOf', 'Obsoletes', 'IsObsoletedBy',
        'Continues', 'IsContinuedBy', 'Compiles', 'IsCompiledBy', 'Collects', 'IsCollectedBy',
    ];

    /** @return list<string> */
    public static function vocabulary(): array
    {
        return array_values(array_unique([...array_keys(RelatedIdentifier::BIDIRECTIONAL_PAIRS),
            'IsIdenticalTo', 'IsPublishedIn', 'HasTranslation', 'IsTranslationOf']));
    }

    public static function inverse(string $slug): ?string
    {
        return match ($slug) {
            'IsIdenticalTo' => 'IsIdenticalTo',
            'HasTranslation' => 'IsTranslationOf',
            'IsTranslationOf' => 'HasTranslation',
            'Other', 'IsPublishedIn' => null,
            default => RelatedIdentifier::BIDIRECTIONAL_PAIRS[$slug] ?? null,
        };
    }

    public static function dataCite(string $slug): ?string
    {
        return in_array($slug, self::vocabulary(), true) ? $slug : null;
    }

    public static function crossref(string $name): ?string
    {
        // Expressions, manifestations, preprints, based-on and Crossmark updates
        // have different semantics and intentionally have no approximate mapping.
        return match ($name) {
            'has-derivation' => 'IsSourceOf',
            'is-derived-from' => 'IsDerivedFrom',
            'has-review' => 'IsReviewedBy',
            'is-review-of' => 'Reviews',
            'has-part' => 'HasPart',
            'is-part-of' => 'IsPartOf',
            'has-version' => 'HasVersion',
            'is-version-of' => 'IsVersionOf',
            'is-identical-to' => 'IsIdenticalTo',
            'is-translation-of' => 'IsTranslationOf',
            'has-translation' => 'HasTranslation',
            'is-supplement-to' => 'IsSupplementTo',
            'is-supplemented-by' => 'IsSupplementedBy',
            'is-documented-by' => 'IsDocumentedBy',
            'documents' => 'Documents',
            default => null,
        };
    }

    /** @return array{id: string, conflict_kind: string, rationale: string}|null */
    public static function conflict(string $current, string $proposed, bool $hasInformation): ?array
    {
        if ($current === $proposed || $proposed === 'Other') {
            return null;
        }
        if ($current === 'Other' && ! $hasInformation) {
            return ['id' => 'explicit-relation', 'conflict_kind' => 'unspecified',
                'rationale' => 'A current primary metadata record explicitly identifies this directed relationship. The existing Other relation has no qualifying information.'];
        }
        if (in_array($current, self::DIRECTIONAL, true) && self::inverse($current) === $proposed && ! $hasInformation) {
            return ['id' => 'reversed-structural-role', 'conflict_kind' => 'direction',
                'rationale' => 'Current primary metadata assigns the opposite structural role to this exact identifier pair. No competing role assertion was found.'];
        }

        // Mutual citations, translations and arbitrary additional relationships
        // are legitimate. An additional assertion does not disprove an old type.
        return null;
    }

    public static function doi(string $identifier): ?string
    {
        $value = preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i', '', trim($identifier));
        if (! is_string($value) || preg_match('#^10\.\d{4,9}/\S+$#D', $value) !== 1) {
            return null;
        }

        return mb_strtolower($value);
    }

    /** @param array<mixed> $value */
    public static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }
        if (array_is_list($value)) {
            usort($value, static fn (mixed $a, mixed $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        } else {
            ksort($value);
        }

        return $value;
    }
}
