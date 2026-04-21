<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Landing page template configuration for custom landing page layouts.
 *
 * Templates define the visual layout of landing pages:
 * - Custom header logo
 * - Section ordering for left and right columns
 *
 * The default template (is_default=true) is immutable and serves as the
 * base for cloning new custom templates.
 *
 * @property int $id
 * @property string $name Human-readable template name
 * @property string $slug URL-friendly unique identifier
 * @property bool $is_default Whether this is the immutable default template
 * @property string|null $logo_path Storage path for custom logo file
 * @property string|null $logo_filename Original filename of the uploaded logo
 * @property array<int, string> $right_column_order Ordered section keys for right column
 * @property array<int, string> $left_column_order Ordered section keys for left column
 * @property int|null $created_by FK to users table
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|null $logo_url Full URL for the logo file
 * @property-read User|null $creator The user who created this template
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LandingPage> $landingPages
 */
class LandingPageTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\LandingPageTemplateFactory> */
    use HasFactory;

    public const DEFAULT_TEMPLATE_SLUG = 'default_gfz';

    public const DEFAULT_TEMPLATE_NAME = 'Default GFZ Data Services';

    /**
     * Valid section keys for the right column.
     *
     * @var list<string>
     */
    public const RIGHT_COLUMN_SECTIONS = [
        'descriptions',
        'creators',
        'contributors',
        'funders',
        'keywords',
        'metadata_download',
        'location',
    ];

    /**
     * Valid section keys for the left column.
     *
     * @var list<string>
     */
    public const LEFT_COLUMN_SECTIONS = [
        'files',
        'contact',
        'model_description',
        'related_work',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'is_default',
        'logo_path',
        'logo_filename',
        'right_column_order',
        'left_column_order',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
        'right_column_order' => 'array',
        'left_column_order' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'logo_url',
    ];

    /**
     * Get the full URL for the logo file.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return asset('storage/' . $this->logo_path);
    }

    /**
     * Get the user who created this template.
     *
     * @return BelongsTo<User, static>
     */
    public function creator(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'created_by');

        return $relation;
    }

    /**
     * Get the landing pages using this template.
     *
     * @return HasMany<LandingPage, $this>
     */
    public function landingPages(): HasMany
    {
        return $this->hasMany(LandingPage::class);
    }

    /**
     * Check if this is the immutable default template.
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * Check if this template is currently in use by any landing pages.
     */
    public function isInUse(): bool
    {
        return $this->landingPages()->exists();
    }

    /**
     * Get the number of landing pages using this template.
     */
    public function getUsageCount(): int
    {
        return $this->landingPages()->count();
    }

    /**
     * Scope to only custom (non-default) templates.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeCustom(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_default', false);
    }

    /**
     * Validate that a section order array contains exactly the valid keys.
     *
     * @param  array<int, string>  $order
     * @param  list<string>  $validSections
     */
    public static function isValidSectionOrder(array $order, array $validSections): bool
    {
        if (count($order) !== count($validSections)) {
            return false;
        }

        $sorted = $order;
        $validSorted = $validSections;
        sort($sorted);
        sort($validSorted);

        return $sorted === $validSorted;
    }

    /**
     * Ensure the immutable default template exists and has required defaults.
     */
    public static function ensureDefaultTemplateExists(): self
    {
        $template = static::query()->where('slug', self::DEFAULT_TEMPLATE_SLUG)->first();

        if ($template === null) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $template = static::query()->firstOrCreate(
                        ['slug' => self::DEFAULT_TEMPLATE_SLUG],
                        [
                            'name' => self::resolveUniqueDefaultTemplateName(),
                            'is_default' => true,
                            'logo_path' => null,
                            'logo_filename' => null,
                            'right_column_order' => self::RIGHT_COLUMN_SECTIONS,
                            'left_column_order' => self::LEFT_COLUMN_SECTIONS,
                            'created_by' => null,
                        ]
                    );

                    break;
                } catch (QueryException $exception) {
                    $template = static::query()->where('slug', self::DEFAULT_TEMPLATE_SLUG)->first();

                    if ($template !== null) {
                        break;
                    }

                    if (! self::isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }
                }
            }
        }

        if ($template === null) {
            throw new \RuntimeException('Failed to restore the default landing page template.');
        }

        DB::transaction(function () use (&$template): void {
            // Keep exactly one immutable default template marker to avoid accidental locks.
            static::query()
                ->where('is_default', true)
                ->whereKeyNot($template->id)
                ->update(['is_default' => false]);

            $template->forceFill([
                'is_default' => true,
                'right_column_order' => self::RIGHT_COLUMN_SECTIONS,
                'left_column_order' => self::LEFT_COLUMN_SECTIONS,
            ]);

            if ($template->isDirty(['is_default', 'right_column_order', 'left_column_order'])) {
                $template->save();
            }

            $template = $template->fresh() ?? $template;
        });

        return $template;
    }

    /**
     * Resolve a unique template name for restoring the default template.
     */
    private static function resolveUniqueDefaultTemplateName(): string
    {
        if (! static::query()->where('name', self::DEFAULT_TEMPLATE_NAME)->exists()) {
            return self::DEFAULT_TEMPLATE_NAME;
        }

        for ($index = 2; $index <= 1000; $index++) {
            $candidate = self::DEFAULT_TEMPLATE_NAME . ' ' . $index;
            if (! static::query()->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return self::DEFAULT_TEMPLATE_NAME . ' ' . Str::upper(Str::random(6));
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000' || $sqlState === '23505' || $driverCode === '1062' || $driverCode === '19';
    }
}
