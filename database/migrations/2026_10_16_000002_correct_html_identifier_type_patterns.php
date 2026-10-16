<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /** @var array<string, list<array{type: string, previous: string, previous_priority: int, pattern: string, priority: int}>> $corrections */
        $corrections = require database_path('data/identifier-patterns/2026-10-16-html-patterns.php');

        DB::transaction(function () use ($corrections): void {
            $now = now();

            foreach ($corrections as $slug => $patterns) {
                $identifierTypeId = DB::table('identifier_types')->where('slug', $slug)->value('id');

                if ($identifierTypeId === null) {
                    continue;
                }

                // A previous pattern can expand into several complete forms.
                $groups = collect($patterns)->groupBy(fn (array $pattern): string => $pattern['type']."\0".$pattern['previous']);

                foreach ($groups as $replacements) {
                    $first = $replacements->firstOrFail();
                    $previous = DB::table('identifier_type_patterns')
                        ->where('identifier_type_id', $identifierTypeId)
                        ->where('type', $first['type'])
                        ->where('pattern', $first['previous'])
                        ->first();

                    // Customized patterns and fresh databases need no backfill.
                    if ($previous === null || $previous->pattern !== $first['previous']) {
                        continue;
                    }

                    // The unique index uses the database collation. Preflight
                    // every alternative before changing this group: a custom
                    // regex differing only in case (e.g. \s versus \S) must
                    // neither replace the original nor cause a duplicate key.
                    foreach ($replacements as $replacement) {
                        $target = DB::table('identifier_type_patterns')
                            ->where('identifier_type_id', $identifierTypeId)
                            ->where('type', $replacement['type'])
                            ->where('pattern', $replacement['pattern'])
                            ->first();

                        if ($target !== null && $target->pattern !== $replacement['pattern']) {
                            continue 2;
                        }
                    }

                    $reusedPrevious = false;

                    foreach ($replacements as $replacement) {
                        $target = [
                            'identifier_type_id' => $identifierTypeId,
                            'type' => $replacement['type'],
                            'pattern' => $replacement['pattern'],
                        ];

                        // Respect an existing target's own activation and priority.
                        if (DB::table('identifier_type_patterns')->where($target)->exists()) {
                            continue;
                        }

                        $values = [
                            ...$target,
                            'is_active' => $previous->is_active,
                            'priority' => (int) $previous->priority === $replacement['previous_priority']
                                ? $replacement['priority']
                                : $previous->priority,
                            'updated_at' => $now,
                        ];

                        if (! $reusedPrevious) {
                            DB::table('identifier_type_patterns')->where('id', $previous->id)->update($values);
                            $reusedPrevious = true;
                        } else {
                            DB::table('identifier_type_patterns')->insert([...$values, 'created_at' => $now]);
                        }
                    }

                    if (! $reusedPrevious) {
                        DB::table('identifier_type_patterns')->where('id', $previous->id)->delete();
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // A data correction is intentionally retained: reverting cannot safely
        // distinguish subsequently customized patterns or activation decisions.
    }
};
