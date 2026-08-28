<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string|null $breadcrumb_path
 * @property string $language
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resource $resource
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/
 */
#[Fillable(['resource_id', 'value', 'language', 'subject_scheme', 'scheme_uri', 'value_uri', 'classification_code', 'breadcrumb_path'])]
class Subject extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /** @return Attribute<string, string> */
    protected function language(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : 'en',
        );
    }

    /**
     * Scope free-text subjects, treating NULL and an empty scheme as equivalent.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFreeText(Builder $query): Builder
    {
        return $query->where(function (Builder $subjectQuery): void {
            $subjectQuery->whereNull('subject_scheme')
                ->orWhere('subject_scheme', '');
        });
    }

    /**
     * Scope controlled-vocabulary subjects.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeControlled(Builder $query): Builder
    {
        return $query->whereNotNull('subject_scheme')
            ->where('subject_scheme', '!=', '');
    }

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /**
     * Check if this is a controlled vocabulary subject.
     */
    public function isControlled(): bool
    {
        return $this->subject_scheme !== null && $this->subject_scheme !== '';
    }

    /**
     * Check if this is a free-text subject.
     */
    public function isFreeText(): bool
    {
        return $this->subject_scheme === null || $this->subject_scheme === '';
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
