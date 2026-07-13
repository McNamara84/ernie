<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $database_dump_export_id
 * @property int $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseDumpExport $export
 * @property-read User $user
 */
#[Fillable(['database_dump_export_id', 'user_id', 'ip_address', 'user_agent', 'downloaded_at'])]
class DatabaseDumpDownload extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DatabaseDumpExport, static>
     */
    public function export(): BelongsTo
    {
        /** @var BelongsTo<DatabaseDumpExport, static> $relation */
        $relation = $this->belongsTo(DatabaseDumpExport::class, 'database_dump_export_id');

        return $relation;
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
}
