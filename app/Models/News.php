<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsKind;
use Carbon\CarbonImmutable;
use Database\Factories\NewsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $body_markdown
 * @property NewsKind $kind
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $is_scheduled
 * @property CarbonImmutable|null $archived_at
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int $revision
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class News extends Model
{
    /** @use HasFactory<NewsFactory> */
    use HasFactory;

    protected $table = 'news';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => NewsKind::class, 'is_scheduled' => 'boolean', 'revision' => 'integer',
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    public function status(int $activeId, CarbonImmutable $now): string
    {
        return match (true) {
            $this->archived_at !== null => 'Archived',
            $this->id === $activeId => 'Active regular',
            ! $this->is_scheduled => 'Draft',
            $this->ends_at <= $now => 'Expired',
            $this->starts_at > $now => 'Scheduled',
            default => 'Currently displayed',
        };
    }
}
