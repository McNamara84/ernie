<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IdentifierType;
use App\Models\RelationType;

class RelatedIdentifierTypeResolverService
{
    /**
     * @var array<int, array{name: string, slug: string}>
     */
    private const DEFAULT_IDENTIFIER_TYPES = [
        ['name' => 'ARK', 'slug' => 'ARK'],
        ['name' => 'arXiv', 'slug' => 'arXiv'],
        ['name' => 'bibcode', 'slug' => 'bibcode'],
        ['name' => 'CSTR', 'slug' => 'CSTR'],
        ['name' => 'DOI', 'slug' => 'DOI'],
        ['name' => 'EAN13', 'slug' => 'EAN13'],
        ['name' => 'EISSN', 'slug' => 'EISSN'],
        ['name' => 'Handle', 'slug' => 'Handle'],
        ['name' => 'IGSN', 'slug' => 'IGSN'],
        ['name' => 'ISBN', 'slug' => 'ISBN'],
        ['name' => 'ISSN', 'slug' => 'ISSN'],
        ['name' => 'ISTC', 'slug' => 'ISTC'],
        ['name' => 'LISSN', 'slug' => 'LISSN'],
        ['name' => 'LSID', 'slug' => 'LSID'],
        ['name' => 'PMID', 'slug' => 'PMID'],
        ['name' => 'PURL', 'slug' => 'PURL'],
        ['name' => 'RAiD', 'slug' => 'RAiD'],
        ['name' => 'RRID', 'slug' => 'RRID'],
        ['name' => 'SWHID', 'slug' => 'SWHID'],
        ['name' => 'UPC', 'slug' => 'UPC'],
        ['name' => 'URL', 'slug' => 'URL'],
        ['name' => 'URN', 'slug' => 'URN'],
        ['name' => 'w3id', 'slug' => 'w3id'],
    ];

    /**
     * @var array<int, array{name: string, slug: string}>
     */
    private const DEFAULT_RELATION_TYPES = [
        ['name' => 'Is Cited By', 'slug' => 'IsCitedBy'],
        ['name' => 'Cites', 'slug' => 'Cites'],
        ['name' => 'Is Supplement To', 'slug' => 'IsSupplementTo'],
        ['name' => 'Is Supplemented By', 'slug' => 'IsSupplementedBy'],
        ['name' => 'Is Translation Of', 'slug' => 'IsTranslationOf'],
        ['name' => 'Has Translation', 'slug' => 'HasTranslation'],
        ['name' => 'Is Continued By', 'slug' => 'IsContinuedBy'],
        ['name' => 'Continues', 'slug' => 'Continues'],
        ['name' => 'Is Described By', 'slug' => 'IsDescribedBy'],
        ['name' => 'Describes', 'slug' => 'Describes'],
        ['name' => 'Has Metadata', 'slug' => 'HasMetadata'],
        ['name' => 'Is Metadata For', 'slug' => 'IsMetadataFor'],
        ['name' => 'Has Version', 'slug' => 'HasVersion'],
        ['name' => 'Is Version Of', 'slug' => 'IsVersionOf'],
        ['name' => 'Is New Version Of', 'slug' => 'IsNewVersionOf'],
        ['name' => 'Is Previous Version Of', 'slug' => 'IsPreviousVersionOf'],
        ['name' => 'Is Part Of', 'slug' => 'IsPartOf'],
        ['name' => 'Has Part', 'slug' => 'HasPart'],
        ['name' => 'Is Published In', 'slug' => 'IsPublishedIn'],
        ['name' => 'Is Referenced By', 'slug' => 'IsReferencedBy'],
        ['name' => 'References', 'slug' => 'References'],
        ['name' => 'Is Documented By', 'slug' => 'IsDocumentedBy'],
        ['name' => 'Documents', 'slug' => 'Documents'],
        ['name' => 'Is Compiled By', 'slug' => 'IsCompiledBy'],
        ['name' => 'Compiles', 'slug' => 'Compiles'],
        ['name' => 'Is Variant Form Of', 'slug' => 'IsVariantFormOf'],
        ['name' => 'Is Original Form Of', 'slug' => 'IsOriginalFormOf'],
        ['name' => 'Is Identical To', 'slug' => 'IsIdenticalTo'],
        ['name' => 'Is Reviewed By', 'slug' => 'IsReviewedBy'],
        ['name' => 'Reviews', 'slug' => 'Reviews'],
        ['name' => 'Is Derived From', 'slug' => 'IsDerivedFrom'],
        ['name' => 'Is Source Of', 'slug' => 'IsSourceOf'],
        ['name' => 'Is Required By', 'slug' => 'IsRequiredBy'],
        ['name' => 'Requires', 'slug' => 'Requires'],
        ['name' => 'Is Obsoleted By', 'slug' => 'IsObsoletedBy'],
        ['name' => 'Obsoletes', 'slug' => 'Obsoletes'],
        ['name' => 'Is Collected By', 'slug' => 'IsCollectedBy'],
        ['name' => 'Collects', 'slug' => 'Collects'],
        ['name' => 'Other', 'slug' => 'Other'],
    ];

    /** @var array<string, string>|null */
    private ?array $identifierTypeLookup = null;

    /** @var array<string, string>|null */
    private ?array $relationTypeLookup = null;

    public function resolveIdentifierType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $this->identifierTypeLookup()[$this->normalizeKey($value)] ?? null;
    }

    public function resolveRelationType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $this->relationTypeLookup()[$this->normalizeKey($value)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function identifierTypeLookup(): array
    {
        if ($this->identifierTypeLookup !== null) {
            return $this->identifierTypeLookup;
        }

        /** @var array<int, array{name: string, slug: string}> $databaseValues */
        $databaseValues = IdentifierType::query()
            ->get(['name', 'slug'])
            ->map(fn (IdentifierType $type): array => [
                'name' => (string) $type->name,
                'slug' => (string) $type->slug,
            ])
            ->all();

        return $this->identifierTypeLookup = $this->buildLookup(
            array_merge(self::DEFAULT_IDENTIFIER_TYPES, $databaseValues)
        );
    }

    /**
     * @return array<string, string>
     */
    private function relationTypeLookup(): array
    {
        if ($this->relationTypeLookup !== null) {
            return $this->relationTypeLookup;
        }

        /** @var array<int, array{name: string, slug: string}> $databaseValues */
        $databaseValues = RelationType::query()
            ->get(['name', 'slug'])
            ->map(fn (RelationType $type): array => [
                'name' => (string) $type->name,
                'slug' => (string) $type->slug,
            ])
            ->all();

        return $this->relationTypeLookup = $this->buildLookup(
            array_merge(self::DEFAULT_RELATION_TYPES, $databaseValues)
        );
    }

    /**
     * @param  array<int, array{name: string, slug: string}>  $values
     * @return array<string, string>
     */
    private function buildLookup(array $values): array
    {
        $lookup = [];

        foreach ($values as $value) {
            $canonical = trim($value['slug']);

            if ($canonical === '') {
                continue;
            }

            $this->addLookupValue($lookup, $value['slug'], $canonical);
            $this->addLookupValue($lookup, $value['name'], $canonical);
        }

        return $lookup;
    }

    /**
     * @param  array<string, string>  $lookup
     */
    private function addLookupValue(array &$lookup, string $rawValue, string $canonical): void
    {
        $key = $this->normalizeKey($rawValue);

        if ($key === '') {
            return;
        }

        $lookup[$key] = $canonical;
    }

    private function normalizeKey(string $value): string
    {
        $normalized = preg_replace('/[^[:alnum:]]+/u', '', trim($value)) ?? '';

        return mb_strtolower($normalized);
    }
}