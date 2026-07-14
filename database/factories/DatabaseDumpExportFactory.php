<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DatabaseDumpExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseDumpExport>
 */
class DatabaseDumpExportFactory extends Factory
{
    protected $model = DatabaseDumpExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'target_key' => 'ernie',
            'target_label' => 'ERNIE',
            'connection_name' => 'mysql',
            'database_name' => 'ernie',
            'status' => DatabaseDumpExport::STATUS_PENDING,
            'disk' => 'local',
            'path' => null,
            'filename' => null,
            'size_bytes' => null,
            'sha256' => null,
            'server_version' => null,
            'dump_client' => null,
            'dump_options' => null,
            'requested_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'expires_at' => now()->addDay(),
            'error_message' => null,
            'download_count' => 0,
            'last_downloaded_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DatabaseDumpExport::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DatabaseDumpExport::STATUS_COMPLETED,
            'path' => 'database-dumps/test.sql.gz',
            'filename' => 'ernie-test.sql.gz',
            'size_bytes' => 128,
            'sha256' => str_repeat('a', 64),
            'finished_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->completed()->state(fn (array $attributes): array => [
            'status' => DatabaseDumpExport::STATUS_COMPLETED,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
