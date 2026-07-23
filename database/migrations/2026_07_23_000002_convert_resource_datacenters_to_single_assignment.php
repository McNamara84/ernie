<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GFZ_DATACENTER_NAME = 'GFZ German Research Centre for Geosciences';

    public function up(): void
    {
        if (! Schema::hasColumn('resources', 'datacenter_id')) {
            Schema::table('resources', function (Blueprint $table): void {
                $table->foreignId('datacenter_id')
                    ->nullable()
                    ->after('publisher_id')
                    ->constrained('datacenters')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('resource_datacenter')) {
            $this->migrateAssignments();
            Schema::drop('resource_datacenter');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('resource_datacenter')) {
            Schema::create('resource_datacenter', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
                $table->foreignId('datacenter_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['resource_id', 'datacenter_id']);
                $table->index('datacenter_id');
                $table->index('resource_id');
            });
        }

        if (! Schema::hasColumn('resources', 'datacenter_id')) {
            return;
        }

        $now = now();

        DB::table('resources')
            ->select(['id', 'datacenter_id'])
            ->whereNotNull('datacenter_id')
            ->orderBy('id')
            ->chunkById(500, function ($resources) use ($now): void {
                $rows = [];

                foreach ($resources as $resource) {
                    $rows[] = [
                        'resource_id' => (int) $resource->id,
                        'datacenter_id' => (int) $resource->datacenter_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('resource_datacenter')->insert($rows);
                }
            });

        Schema::table('resources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('datacenter_id');
        });
    }

    private function migrateAssignments(): void
    {
        DB::table('resources')
            ->select('resources.id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('resource_datacenter')
                    ->whereColumn('resource_datacenter.resource_id', 'resources.id');
            })
            ->orderBy('resources.id')
            ->chunkById(500, function ($resources): void {
                foreach ($resources as $resource) {
                    $assignments = array_values(DB::table('resource_datacenter')
                        ->join('datacenters', 'datacenters.id', '=', 'resource_datacenter.datacenter_id')
                        ->where('resource_datacenter.resource_id', $resource->id)
                        ->orderBy('resource_datacenter.id')
                        ->get([
                            'resource_datacenter.id as pivot_id',
                            'resource_datacenter.datacenter_id',
                            'datacenters.name as datacenter_name',
                        ])
                        ->map(fn ($row): array => [
                            'pivot_id' => (int) $row->pivot_id,
                            'datacenter_id' => (int) $row->datacenter_id,
                            'datacenter_name' => (string) $row->datacenter_name,
                        ])
                        ->all());

                    $this->persistAssignment((int) $resource->id, $assignments);
                }
            }, 'resources.id', 'id');
    }

    /**
     * @param  list<array{pivot_id: int, datacenter_id: int, datacenter_name: string}>  $assignments
     */
    private function persistAssignment(int $resourceId, array $assignments): void
    {
        if ($assignments === []) {
            return;
        }

        $specializedAssignments = array_values(array_filter(
            $assignments,
            static fn (array $assignment): bool => $assignment['datacenter_name'] !== self::GFZ_DATACENTER_NAME,
        ));

        $selected = ($specializedAssignments !== [] ? $specializedAssignments : $assignments)[0];

        DB::table('resources')
            ->where('id', $resourceId)
            ->update(['datacenter_id' => $selected['datacenter_id']]);

        if (count($assignments) > 1) {
            Log::warning('Reduced multiple resource datacenter assignments to one during migration', [
                'resource_id' => $resourceId,
                'datacenter_ids' => array_column($assignments, 'datacenter_id'),
                'datacenter_names' => array_column($assignments, 'datacenter_name'),
                'selected_datacenter_id' => $selected['datacenter_id'],
                'selection_rule' => $specializedAssignments !== []
                    ? 'oldest-specialized-assignment'
                    : 'oldest-assignment',
            ]);
        }
    }
};
