<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EditorContext;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EditorSettingsChangeService
{
    /** Fields returned by the editor APIs; activation and timestamps are handled separately. */
    private const CATALOGS = [
        'resource_types' => ['id', 'name', 'slug'],
        'title_types' => ['id', 'name', 'slug'],
        'rights' => ['id', 'identifier', 'name', 'uri', 'scheme_uri'],
        'languages' => ['id', 'code', 'name'],
        'date_types' => ['id', 'name', 'slug'],
        'description_types' => ['id', 'name', 'slug'],
        'contributor_types' => ['id', 'name', 'slug', 'category'],
        'relation_types' => ['id', 'name', 'slug', 'description'],
        'identifier_types' => ['id', 'name', 'slug', 'description'],
    ];

    /** @return array<string, string> Normalized fingerprints for each external editor. */
    public function snapshot(): array
    {
        if (! config('elmo.enabled')) {
            return [];
        }

        $editors = [EditorContext::ELMO, EditorContext::ELMO_MSL];
        $data = ['elmo' => [], 'elmo-msl' => []];
        $patterns = DB::table('identifier_type_patterns')->where('is_active', true)
            ->orderBy('identifier_type_id')->orderBy('type')->orderByDesc('priority')->orderBy('id')
            ->get(['identifier_type_id', 'type', 'pattern', 'priority'])->groupBy('identifier_type_id');
        $exclusions = DB::table('right_resource_type_exclusions')
            ->whereIn('editor', ['elmo', 'elmo-msl'])->orderBy('resource_type_id')
            ->get(['right_id', 'resource_type_id', 'editor']);

        foreach (self::CATALOGS as $table => $fields) {
            $language = $table === 'languages';
            $rows = DB::table($table)->orderBy('id')->get([
                ...$fields, ...array_map(static fn (EditorContext $editor): string => $editor->activationColumn($language), $editors),
            ]);
            foreach ($editors as $editor) {
                $visible = [];
                foreach ($rows as $row) {
                    if (! (bool) $row->{$editor->activationColumn($language)}) {
                        continue;
                    }
                    $entry = [];
                    foreach ($fields as $field) {
                        $entry[$field] = $row->{$field} === null ? null : (string) $row->{$field};
                    }
                    if ($table === 'identifier_types') {
                        $entry['patterns'] = $patterns->get($row->id, collect())->map(static fn (stdClass $pattern): array => [
                            'type' => (string) $pattern->type, 'pattern' => (string) $pattern->pattern, 'priority' => (int) $pattern->priority,
                        ])->all();
                    }
                    if ($table === 'rights') {
                        $entry['exclusions'] = $exclusions->where('editor', $editor->value)->where('right_id', $row->id)
                            ->pluck('resource_type_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
                    }
                    $visible[] = $entry;
                }
                $data[$editor->value][$table] = $visible;
            }
        }

        foreach (['thesaurus_settings', 'pid_settings'] as $table) {
            $rows = DB::table($table)->orderBy('type')->get();
            foreach ($editors as $editor) {
                $data[$editor->value][$table] = $rows->map(static function (object $row) use ($table, $editor): array {
                    $active = (bool) $row->{$editor->activationColumn()};

                    return ['type' => (string) $row->type, 'active' => $active,
                        'version' => $table === 'thesaurus_settings' && $active ? $row->version : null];
                })->all();
            }
        }

        return array_map(static fn (array $projection): string => hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR)), $data);
    }

    /**
     * @param  array<string, string>  $before
     * @return list<EditorContext>
     */
    public function changedEditors(array $before): array
    {
        $after = $this->snapshot();

        return array_values(array_filter([EditorContext::ELMO, EditorContext::ELMO_MSL],
            static fn (EditorContext $editor): bool => ($before[$editor->value] ?? null) !== ($after[$editor->value] ?? null)));
    }
}
