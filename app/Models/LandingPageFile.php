<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file entry associated with a landing page.
 *
 * Stores download URLs imported from the legacy metaworks database
 * during DataCite import. Each landing page can have multiple files.
 *
 * @property int $id
 * @property int $landing_page_id
 * @property string $url
 * @property string|null $label
 * @property int|null $format_id
 * @property int|null $size_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LandingPage $landingPage
 */
#[Fillable(['landing_page_id', 'url', 'label', 'format_id', 'size_id', 'position'])]
class LandingPageFile extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Get the landing page that owns this file.
     *
     * @return BelongsTo<LandingPage, $this>
     */
    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    /** @return BelongsTo<Format, $this> */
    public function format(): BelongsTo
    {
        return $this->belongsTo(Format::class);
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }
}
