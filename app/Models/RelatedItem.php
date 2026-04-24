<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RelatedItem Model (DataCite 4.7 #20).
 *
 * Unlike {@see RelatedIdentifier}, a RelatedItem carries its own inline
 * metadata (titles, creators, publisher, volume, issue, pages, …).
 * It is the correct mechanism for grey literature, older publications
 * with unreliable Crossref/DataCite records, or any reference whose
 * identifier cannot be resolved to usable metadata.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $related_item_type
 * @property int $relation_type_id
 * @property int|null $publication_year
 * @property string|null $volume
 * @property string|null $issue
 * @property string|null $number
 * @property string|null $number_type
 * @property string|null $first_page
 * @property string|null $last_page
 * @property string|null $publisher
 * @property string|null $edition
 * @property string|null $identifier
 * @property string|null $identifier_type
 * @property string|null $related_metadata_scheme
 * @property string|null $scheme_uri
 * @property string|null $scheme_type
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read RelationType $relationType
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RelatedItemTitle> $titles
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RelatedItemCreator> $creators
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RelatedItemContributor> $contributors
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relateditem/
 */
#[Fillable([
    'resource_id',
    'related_item_type',
    'relation_type_id',
    'publication_year',
    'volume',
    'issue',
    'number',
    'number_type',
    'first_page',
    'last_page',
    'publisher',
    'edition',
    'identifier',
    'identifier_type',
    'related_metadata_scheme',
    'scheme_uri',
    'scheme_type',
    'position',
])]
class RelatedItem extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'publication_year' => 'integer',
        'position' => 'integer',
    ];

    public const TITLE_TYPE_MAIN = 'MainTitle';

    public const TITLE_TYPES = [
        'MainTitle',
        'Subtitle',
        'TranslatedTitle',
        'AlternativeTitle',
    ];

    public const NUMBER_TYPES = [
        'Article',
        'Chapter',
        'Report',
        'Other',
    ];

    public const NAME_TYPES = [
        'Personal',
        'Organizational',
    ];

    public const IDENTIFIER_TYPES = [
        'ARK',
        'arXiv',
        'bibcode',
        'DOI',
        'EAN13',
        'EISSN',
        'Handle',
        'IGSN',
        'ISBN',
        'ISSN',
        'ISTC',
        'LISSN',
        'LSID',
        'PMID',
        'PURL',
        'UPC',
        'URL',
        'URN',
        'w3id',
    ];

    public const NAME_IDENTIFIER_SCHEMES = [
        'ORCID',
        'ROR',
        'ISNI',
        'GND',
    ];

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /** @return BelongsTo<RelationType, $this> */
    public function relationType(): BelongsTo
    {
        return $this->belongsTo(RelationType::class);
    }

    /** @return HasMany<RelatedItemTitle, $this> */
    public function titles(): HasMany
    {
        return $this->hasMany(RelatedItemTitle::class)->orderBy('position');
    }

    /** @return HasMany<RelatedItemCreator, $this> */
    public function creators(): HasMany
    {
        return $this->hasMany(RelatedItemCreator::class)->orderBy('position');
    }

    /** @return HasMany<RelatedItemContributor, $this> */
    public function contributors(): HasMany
    {
        return $this->hasMany(RelatedItemContributor::class)->orderBy('position');
    }

    /**
     * Resolve the main title text, if any.
     */
    public function mainTitle(): ?string
    {
        $title = $this->titles->firstWhere('title_type', self::TITLE_TYPE_MAIN);

        return $title?->title;
    }
}
