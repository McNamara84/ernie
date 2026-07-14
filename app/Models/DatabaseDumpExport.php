<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DatabaseDumpExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $target_key
 * @property string $target_label
 * @property string $connection_name
 * @property string $database_name
 * @property string $status
 * @property string $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $server_version
 * @property string|null $dump_client
 * @property array<string, mixed>|null $dump_options
 * @property Carbon|null $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property string|null $error_message
 * @property int $download_count
 * @property Carbon|null $last_downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, DatabaseDumpDownload> $downloads
 */
class DatabaseDumpExport extends Model
{
    /** @use HasFactory<DatabaseDumpExportFactory> */
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'target_key',
        'target_label',
        'connection_name',
        'database_name',
        'status',
        'disk',
        'path',
        'filename',
        'size_bytes',
        'sha256',
        'server_version',
        'dump_client',
        'dump_options',
        'requested_at',
        'started_at',
        'finished_at',
        'expires_at',
        'error_message',
        'download_count',
        'last_downloaded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dump_options' => 'array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'size_bytes' => 'integer',
            'download_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, static>
     */
    public function user(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class);

        return $relation;
    }

    /**
     * @return HasMany<DatabaseDumpDownload, static>
     */
    public function downloads(): HasMany
    {
        /** @var HasMany<DatabaseDumpDownload, static> $relation */
        $relation = $this->hasMany(DatabaseDumpDownload::class, 'database_dump_export_id');

        return $relation;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveForUser(Builder $query, int $userId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->path !== null
            && $this->filename !== null
            && ! $this->isExpired();
    }
}
