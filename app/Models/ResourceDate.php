<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResourceDate Model (DataCite #8)
 *
 * Stores dates for a Resource with type and optional information.
 * Note: Named ResourceDate to avoid conflict with PHP's DateTime.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $date
 * @property int $date_type_id
 * @property string|null $date_information
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Resource $resource
 * @property-read DateType $dateType
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/date/
 */
class ResourceDate extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'dates';

    protected $fillable = [
        'resource_id',
        'date',
        'date_type_id',
        'date_information',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<DateType, static> */
    public function dateType(): BelongsTo
    {
        /** @var BelongsTo<DateType, static> $relation */
        $relation = $this->belongsTo(DateType::class);

        return $relation;
    }

    /**
     * Check if this is a date range (RKMS-ISO8601 format with /).
     */
    public function isRange(): bool
    {
        return str_contains($this->date, '/');
    }

    /**
     * Get the start date for a range.
     */
    public function getStartDate(): ?string
    {
        if (! $this->isRange()) {
            return $this->date;
        }

        $parts = explode('/', $this->date);

        return $parts[0] ?: null;
    }

    /**
     * Get the end date for a range.
     */
    public function getEndDate(): ?string
    {
        if (! $this->isRange()) {
            return null;
        }

        $parts = explode('/', $this->date);

        return $parts[1] ?? null;
    }

    /**
     * Check if this is a collected date (temporal coverage).
     */
    public function isCollected(): bool
    {
        return $this->dateType->slug === 'Collected';
    }
}
