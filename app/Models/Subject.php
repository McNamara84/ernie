<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subject Model (DataCite #6)
 *
 * Stores subjects/keywords for a Resource. Supports both free-text and controlled vocabularies.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $value
 * @property string|null $subject_scheme
 * @property string|null $scheme_uri
 * @property string|null $value_uri
 * @property string|null $classification_code
 * @property int|null $language_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Resource $resource
 * @property-read Language|null $language
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/subject/
 */
class Subject extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'value',
        'subject_scheme',
        'scheme_uri',
        'value_uri',
        'classification_code',
        'language_id',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<Language, static> */
    public function language(): BelongsTo
    {
        /** @var BelongsTo<Language, static> $relation */
        $relation = $this->belongsTo(Language::class);

        return $relation;
    }

    /**
     * Check if this is a controlled vocabulary subject.
     */
    public function isControlled(): bool
    {
        return $this->subject_scheme !== null;
    }

    /**
     * Check if this is a free-text subject.
     */
    public function isFreeText(): bool
    {
        return $this->subject_scheme === null;
    }

    /**
     * Check if this is a GCMD Science Keyword.
     */
    public function isGcmd(): bool
    {
        return $this->subject_scheme === 'GCMD Science Keywords';
    }

    /**
     * Check if this is an MSL vocabulary term.
     */
    public function isMsl(): bool
    {
        return str_starts_with($this->subject_scheme ?? '', 'MSL');
    }
}
