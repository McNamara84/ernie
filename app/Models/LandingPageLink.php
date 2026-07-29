<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An additional link associated with a landing page.
 *
 * Curators can add extra download links (e.g., GitLab repository, project website)
 * that are displayed below the primary download link on the public landing page.
 *
 * @property int $id
 * @property int $landing_page_id
 * @property string $url
 * @property string $label
 * @property string $kind
 * @property int|null $format_id
 * @property int|null $size_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LandingPage $landingPage
 */
#[Fillable(['landing_page_id', 'url', 'label', 'kind', 'format_id', 'size_id', 'position'])]
class LandingPageLink extends Model
{
    public const KIND_RELATED = 'related';

    public const KIND_DOWNLOAD = 'download';

    public const KIND_REPOSITORY = 'repository';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_RELATED,
        self::KIND_DOWNLOAD,
        self::KIND_REPOSITORY,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Get the landing page that owns this link.
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
