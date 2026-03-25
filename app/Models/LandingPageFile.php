<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file entry associated with a landing page.
 *
 * Stores download URLs imported from the legacy metaworks database
 * during DataCite import. Each landing page can have multiple files.
 *
 * @property int $id
 * @property int $landing_page_id
 * @property string $url
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LandingPage $landingPage
 */
#[Fillable(['landing_page_id', 'url', 'position'])]
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
}
