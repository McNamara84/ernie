<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Thesaurus settings for GCMD controlled vocabularies.
 *
 * @property int $id
 * @property string $type
 * @property string $display_name
 * @property bool $is_active
 * @property bool $is_elmo_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ThesaurusSetting extends Model
{
    public const TYPE_SCIENCE_KEYWORDS = 'science_keywords';

    public const TYPE_PLATFORMS = 'platforms';

    public const TYPE_INSTRUMENTS = 'instruments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'display_name',
        'is_active',
        'is_elmo_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_elmo_active' => 'boolean',
        ];
    }

    /**
     * Get the vocabulary JSON file path for this thesaurus.
     */
    public function getFilePath(): string
    {
        return match ($this->type) {
            self::TYPE_SCIENCE_KEYWORDS => 'gcmd-science-keywords.json',
            self::TYPE_PLATFORMS => 'gcmd-platforms.json',
            self::TYPE_INSTRUMENTS => 'gcmd-instruments.json',
            default => throw new \InvalidArgumentException("Unknown thesaurus type: {$this->type}"),
        };
    }

    /**
     * Get the artisan command name for updating this thesaurus.
     */
    public function getArtisanCommand(): string
    {
        return match ($this->type) {
            self::TYPE_SCIENCE_KEYWORDS => 'get-gcmd-science-keywords',
            self::TYPE_PLATFORMS => 'get-gcmd-platforms',
            self::TYPE_INSTRUMENTS => 'get-gcmd-instruments',
            default => throw new \InvalidArgumentException("Unknown thesaurus type: {$this->type}"),
        };
    }

    /**
     * Get the NASA KMS vocabulary type identifier.
     */
    public function getVocabularyType(): string
    {
        return match ($this->type) {
            self::TYPE_SCIENCE_KEYWORDS => 'sciencekeywords',
            self::TYPE_PLATFORMS => 'platforms',
            self::TYPE_INSTRUMENTS => 'instruments',
            default => throw new \InvalidArgumentException("Unknown thesaurus type: {$this->type}"),
        };
    }

    /**
     * Get all valid thesaurus types.
     *
     * @return list<string>
     */
    public static function getValidTypes(): array
    {
        return [
            self::TYPE_SCIENCE_KEYWORDS,
            self::TYPE_PLATFORMS,
            self::TYPE_INSTRUMENTS,
        ];
    }
}
