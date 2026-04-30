<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LandingPageTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
 * @property string $template_type Distinguishes resource (DOI) from IGSN templates ('resource'|'igsn')
 * @property string|null $logo_path Storage path for custom logo file
 * @property string|null $logo_filename Original filename of the uploaded logo
 * @property array<int, string> $right_column_order Ordered section keys for right column
 * @property array<int, string> $left_column_order Ordered section keys for left column
 * @property int|null $created_by FK to users table
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $logo_url Full URL for the logo file
 * @property-read User|null $creator The user who created this template
 * @property-read Collection<int, LandingPage> $landingPages
 */
class LandingPageTemplate extends Model
{
    /** @use HasFactory<LandingPageTemplateFactory> */
    use HasFactory;

    public const DEFAULT_TEMPLATE_SLUG = 'default_gfz';

    public const DEFAULT_TEMPLATE_NAME = 'Default GFZ Data Services';

    public const IGSN_DEFAULT_TEMPLATE_SLUG = 'default_gfz_igsn';

    public const IGSN_DEFAULT_TEMPLATE_NAME = 'Default GFZ IGSN';

    public const TEMPLATE_TYPE_RESOURCE = 'resource';

    public const TEMPLATE_TYPE_IGSN = 'igsn';

    /**
     * Allowed values for the {@see self::$template_type template_type} attribute.
     *
     * @var list<string>
     */
    public const TEMPLATE_TYPES = [
        self::TEMPLATE_TYPE_RESOURCE,
        self::TEMPLATE_TYPE_IGSN,
    ];

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
        'general',
        'acquisition',
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
        'template_type',
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

        return asset('storage/'.$this->logo_path);
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCustom(Builder $query): Builder
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
     * Ensure the immutable default template (resource type) exists and has required defaults.
     */
    public static function ensureDefaultTemplateExists(): self
    {
        return self::ensureSystemTemplate(
            self::DEFAULT_TEMPLATE_SLUG,
            self::DEFAULT_TEMPLATE_NAME,
            self::TEMPLATE_TYPE_RESOURCE,
        );
    }

    /**
     * Ensure the immutable default IGSN template exists and has required defaults.
     */
    public static function ensureIgsnDefaultTemplateExists(): self
    {
        return self::ensureSystemTemplate(
            self::IGSN_DEFAULT_TEMPLATE_SLUG,
            self::IGSN_DEFAULT_TEMPLATE_NAME,
            self::TEMPLATE_TYPE_IGSN,
        );
    }

    /**
     * Ensure all system-owned default templates exist (resource + IGSN).
     *
     * @return array{resource: self, igsn: self}
     */
    public static function ensureSystemTemplatesExist(): array
    {
        return [
            self::TEMPLATE_TYPE_RESOURCE => self::ensureDefaultTemplateExists(),
            self::TEMPLATE_TYPE_IGSN => self::ensureIgsnDefaultTemplateExists(),
        ];
    }

    /**
     * Resolve the default template for a given type.
     */
    public static function defaultForType(string $templateType): self
    {
        return match ($templateType) {
            self::TEMPLATE_TYPE_IGSN => self::ensureIgsnDefaultTemplateExists(),
            default => self::ensureDefaultTemplateExists(),
        };
    }

    /**
     * Shared implementation backing {@see self::ensureDefaultTemplateExists()} and
     * {@see self::ensureIgsnDefaultTemplateExists()}.
     */
    private static function ensureSystemTemplate(string $slug, string $preferredName, string $templateType): self
    {
        $template = static::query()->where('slug', $slug)->first();

        if ($template === null) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $template = static::query()->firstOrCreate(
                        ['slug' => $slug],
                        [
                            'name' => self::resolveUniqueSystemTemplateName($preferredName),
                            'is_default' => true,
                            'template_type' => $templateType,
                            'logo_path' => null,
                            'logo_filename' => null,
                            'right_column_order' => self::RIGHT_COLUMN_SECTIONS,
                            'left_column_order' => self::LEFT_COLUMN_SECTIONS,
                            'created_by' => null,
                        ]
                    );

                    break;
                } catch (QueryException $exception) {
                    $template = static::query()->where('slug', $slug)->first();

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
            throw new \RuntimeException(sprintf('Failed to restore the system landing page template "%s".', $slug));
        }

        DB::transaction(function () use (&$template, $templateType): void {
            // Keep exactly one immutable default template per type to avoid accidental locks.
            static::query()
                ->where('is_default', true)
                ->where('template_type', $templateType)
                ->whereKeyNot($template->id)
                ->update(['is_default' => false]);

            // Force-fill all canonical fields to ensure the system template stays clean and immutable.
            $template->forceFill([
                'is_default' => true,
                'template_type' => $templateType,
                'right_column_order' => self::RIGHT_COLUMN_SECTIONS,
                'left_column_order' => self::LEFT_COLUMN_SECTIONS,
                'created_by' => null,           // System-owned, not created by a user
                'logo_path' => null,            // No custom logo
                'logo_filename' => null,        // No custom logo
            ]);

            if ($template->isDirty(['is_default', 'template_type', 'right_column_order', 'left_column_order', 'created_by', 'logo_path', 'logo_filename'])) {
                $template->save();
            }

            $template = $template->fresh() ?? $template;
        });

        return $template;
    }

    /**
     * Resolve a unique template name for restoring a system-owned default template.
     */
    private static function resolveUniqueSystemTemplateName(string $preferred): string
    {
        if (! static::query()->where('name', $preferred)->exists()) {
            return $preferred;
        }

        for ($index = 2; $index <= 1000; $index++) {
            $candidate = $preferred.' '.$index;
            if (! static::query()->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $preferred.' '.Str::upper(Str::random(6));
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000' || $sqlState === '23505' || $driverCode === '1062' || $driverCode === '19';
    }
}
