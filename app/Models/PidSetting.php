<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PID (Persistent Identifier) settings for instrument registries.
 *
 * @property int $id
 * @property string $type
 * @property string $display_name
 * @property bool $is_active
 * @property bool $is_elmo_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PidSetting extends Model
{
    public const TYPE_PID4INST = 'pid4inst';

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
     * Get the vocabulary JSON file path for this PID type.
     */
    public function getFilePath(): string
    {
        return match ($this->type) {
            self::TYPE_PID4INST => 'pid4inst-instruments.json',
            default => throw new \InvalidArgumentException("Unknown PID type: {$this->type}"),
        };
    }

    /**
     * Get the artisan command name for updating this PID data.
     */
    public function getArtisanCommand(): string
    {
        return match ($this->type) {
            self::TYPE_PID4INST => 'get-pid4inst-instruments',
            default => throw new \InvalidArgumentException("Unknown PID type: {$this->type}"),
        };
    }

    /**
     * Get all valid PID types.
     *
     * @return list<string>
     */
    public static function getValidTypes(): array
    {
        return [
            self::TYPE_PID4INST,
        ];
    }
}
