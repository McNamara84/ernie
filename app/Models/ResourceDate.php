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
 * @property string|null $date_value
 * @property string|null $start_date
 * @property string|null $end_date
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
        'date_value',
        'start_date',
        'end_date',
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
     * Check if this is a closed date range (both start and end dates set).
     *
     * Note: This only returns true for ranges with BOTH start_date AND end_date.
     * For open-ended ranges (only start_date), use isOpenEndedRange().
     */
    public function isRange(): bool
    {
        return $this->start_date !== null && $this->end_date !== null;
    }

    /**
     * Check if this is an open-ended date range (start_date set, but no end_date).
     *
     * Open-ended ranges represent "from date X onwards" without a defined end.
     * In DataCite export, these are typically treated as single dates.
     */
    public function isOpenEndedRange(): bool
    {
        return $this->start_date !== null && $this->end_date === null && $this->date_value === null;
    }

    /**
     * Check if this date has any range component (closed or open-ended).
     */
    public function hasRangeStart(): bool
    {
        return $this->start_date !== null;
    }

    /**
     * Get the start date for a range.
     */
    public function getStartDate(): ?string
    {
        if ($this->start_date !== null) {
            return $this->start_date;
        }

        return $this->date_value;
    }

    /**
     * Get the end date for a range.
     */
    public function getEndDate(): ?string
    {
        return $this->end_date;
    }

    /**
     * Check if this is a collected date (temporal coverage).
     */
    public function isCollected(): bool
    {
        return $this->dateType->slug === 'Collected';
    }
}
