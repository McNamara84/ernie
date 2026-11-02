<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $typeId = DB::table('identifier_types')->where('slug', 'SWHID')->value('id');
        if ($typeId === null) {
            return;
        }
        /** @var list<array{type: string, previous: string, previous_priority: int, patterns: list<array{pattern: string, priority: int}>}> $groups */
        $groups = require database_path('data/identifier-patterns/2026-10-30-swhid.php');
        DB::transaction(function () use ($typeId, $groups): void {
            foreach ($groups as $group) {
                $previous = DB::table('identifier_type_patterns')->where('identifier_type_id', $typeId)
                    ->where('type', $group['type'])->where('pattern', $group['previous'])->first();
                // Never reinterpret a customized pattern, including collation-only differences.
                if ($previous === null || $previous->pattern !== $group['previous']) {
                    continue;
                }
                foreach ($group['patterns'] as $replacement) {
                    $target = DB::table('identifier_type_patterns')->where('identifier_type_id', $typeId)
                        ->where('type', $group['type'])->where('pattern', $replacement['pattern'])->first();
                    if ($target !== null && $target->pattern !== $replacement['pattern']) {
                        continue 2;
                    }
                }
                foreach ($group['patterns'] as $replacement) {
                    $target = ['identifier_type_id' => $typeId, 'type' => $group['type'], 'pattern' => $replacement['pattern']];
                    if (! DB::table('identifier_type_patterns')->where($target)->exists()) {
                        DB::table('identifier_type_patterns')->insert([
                            ...$target,
                            'is_active' => $previous->is_active,
                            'priority' => (int) $previous->priority === $group['previous_priority'] ? $replacement['priority'] : $previous->priority,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
                if ($previous->is_active) {
                    DB::table('identifier_type_patterns')->where('id', $previous->id)->update(['is_active' => false, 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Retain the correction: a rollback cannot safely restore later user activation decisions.
    }
};
