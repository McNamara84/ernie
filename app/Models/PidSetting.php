<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PID (Persistent Identifier) settings for configured registries.
 *
 * @property int $id
 * @property string $type
 * @property string $display_name
 * @property bool $is_active
 * @property bool $is_elmo_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'display_name', 'is_active', 'is_elmo_active'])]
class PidSetting extends Model
{
    public const TYPE_PID4INST = 'pid4inst';

    public const TYPE_ROR = 'ror';

    public const TYPE_RAID = 'raid';

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
            self::TYPE_ROR => 'ror/ror-affiliations.json',
            self::TYPE_RAID => 'raid/raid-projects.json',
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
            self::TYPE_ROR => 'get-ror-ids',
            self::TYPE_RAID => 'get-raid-projects',
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
            self::TYPE_ROR,
            self::TYPE_RAID,
        ];
    }
}
