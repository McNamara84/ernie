<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Business activity, deliberately separate from technical diagnostics. */
final class UserActivityService
{
    /** @return array{id: int, name: string}|null */
    public function actor(User|int|null $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $id = $user instanceof User ? $user->id : $user;
        $name = $user instanceof User ? $user->name : User::find($id)?->name;

        return ['id' => $id, 'name' => $this->text($name ?? "User #{$id}")];
    }

    /** @return array{kind: string, id: int, title: string, doi: ?string} */
    public function subject(Resource $resource): array
    {
        $resource->loadMissing(['titles.titleType', 'igsnMetadata', 'resourceType']);
        $kind = $resource->igsnMetadata !== null || $resource->isIgsn() ? 'IGSN' : 'resource';

        return [
            'kind' => $kind,
            'id' => $resource->id,
            'title' => $this->text($resource->main_title ?: ($resource->titles->isNotEmpty() ? $resource->titles->first()->value : "{$kind} #{$resource->id}")),
            'doi' => $resource->doi,
        ];
    }

    /**
     * Snapshot values never enter the log: only the names of changed groups do.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Resource $resource, bool $landingPageOnly = false): array
    {
        $resource = $resource->fresh();
        if ($resource === null) {
            return [];
        }
        if ($landingPageOnly) {
            $page = $resource->landingPage()->with(['files', 'links'])->first();
            if ($page === null) {
                return [];
            }
            $fields = array_intersect_key($page->attributesToArray(), array_flip([
                'template', 'landing_page_template_id', 'slug', 'is_published',
                'external_domain_id', 'external_path', 'ftp_url', 'primary_download_label',
                'ftp_format_id', 'ftp_size_id', 'downloads_unavailable',
                'is_tombstone', 'tombstone_reason', 'tombstone_statement',
            ]));
            $fields['files'] = $page->files->toArray();
            $fields['links'] = $page->links->toArray();

            return $this->normalize($fields);
        }

        $resource->load([
            ...array_diff(Resource::DATACITE_EXPORT_RELATIONS, ['landingPage', 'rights']),
            'resourceRights.right', 'instruments', 'igsnClassifications', 'igsnGeologicalAges',
            'igsnGeologicalUnits', 'igsnOperators', 'igsnMethods', 'igsnMeasurements', 'igsnMetadataValues',
        ]);
        $fields = array_intersect_key($resource->attributesToArray(), array_flip([
            'doi', 'publication_year', 'datacenter_id',
            'version', 'access_level', 'workflow_status_override', 'force_review_status',
        ]));
        foreach ($resource->getRelations() as $key => $relation) {
            $fields[$key === 'resourceRights' ? 'rights' : Str::snake($key)] = $relation?->toArray();
        }

        return $this->normalize($fields);
    }

    /**
     * Compare only metadata owned by editor persistence. Lookup IDs suffice for
     * catalog selections; related items are preserved unless explicitly submitted.
     * Reuse the relations loaded by storage and retain only normalized hashes.
     *
     * @return array<string, string>
     */
    public function editorSnapshot(Resource $resource, bool $includeRelatedItems = false): array
    {
        $relations = [
            'titles', 'resourceRights', 'creators.creatorable', 'creators.affiliations',
            'contributors.contributorable', 'contributors.contributorTypes', 'contributors.affiliations',
            'descriptions', 'dates.dateType', 'subjects', 'geoLocations',
            'relatedIdentifiers', 'fundingReferences', 'instruments',
        ];
        if ($includeRelatedItems) {
            $relations = [...$relations, 'relatedItems.titles', 'relatedItems.creators.affiliations', 'relatedItems.contributors.affiliations'];
        }
        $resource->loadMissing($relations);
        $fields = array_intersect_key($resource->attributesToArray(), array_flip([
            'doi', 'publication_year', 'datacenter_id', 'version', 'access_level',
            'workflow_status_override', 'force_review_status',
        ]));
        $fields['resource_type'] = $resource->resource_type_id;
        $fields['language'] = $resource->language_id;
        $fields['publisher'] = $resource->publisher_id;
        $fingerprint = fn (mixed $value): string => hash('sha256', json_encode($this->normalize(['value' => $value]), JSON_THROW_ON_ERROR));
        $fields = array_map($fingerprint, $fields);
        foreach (array_unique(array_map(static fn (string $relation): string => explode('.', $relation)[0], $relations)) as $relation) {
            $fields[$relation === 'resourceRights' ? 'rights' : Str::snake($relation)] = $fingerprint($resource->getRelation($relation)?->toArray());
        }

        return $fields;
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public function changedFields(array $before, array $after): array
    {
        return array_values(array_filter(array_unique([...array_keys($before), ...array_keys($after)]),
            static fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null)));
    }

    /** @param array<string, mixed> $before */
    public function landingChange(?User $user, Resource $resource, array $before, string $action): void
    {
        $after = $this->snapshot($resource, true);
        $fields = $this->changedFields($before, $after);
        if ($fields === []) {
            return;
        }
        $verb = match ($action) {
            'landing-page.store' => 'created the landing page for',
            'landing-page.tombstone.activate' => 'created a tombstone landing page for',
            'landing-page.tombstone.update' => 'updated the tombstone landing page of',
            'landing-page.tombstone.restore' => 'restored the landing page from its tombstone state for',
            default => match (true) {
                ($before['template'] ?? null) !== 'external' && ($after['template'] ?? null) === 'external' => 'switched to an external landing page for',
                in_array('is_published', $fields, true) && ($after['is_published'] ?? false) => 'published the landing page of',
                $fields === ['ftp_url'] => 'changed the FTP URL for',
                default => 'updated the landing page of',
            },
        };
        $this->record($this->actor($user), $action, $verb, $this->subject($resource), $fields);
    }

    /** @param array{id: int, name: string}|null $actor
     * @param  array<string, mixed>|null  $subject
     * @param  list<string>  $fields
     */
    public function record(?array $actor, string $action, string $verb, ?array $subject = null, array $fields = [], ?string $operationId = null, bool $testMode = false): void
    {
        if ($actor === null) {
            return;
        }
        $message = "User {$actor['name']} (ID {$actor['id']}) {$verb}";
        if ($subject !== null) {
            $message .= " {$subject['kind']} #{$subject['id']}, \"{$subject['title']}\"";
        }
        if ($testMode) {
            $message .= ' in DataCite test mode';
        }
        $message .= '.';
        if ($fields !== []) {
            $message .= ' Changed fields: '.implode(', ', array_map($this->fieldLabel(...), $fields)).'.';
        }
        $activity = [
            'schema_version' => 1, 'event_id' => (string) Str::uuid(), 'action' => $action,
            'actor' => $actor, 'subject' => $subject, 'changed_fields' => $fields,
            'operation_id' => $operationId, 'test_mode' => $testMode,
        ];
        DB::afterCommit(static function () use ($message, $activity): void {
            try {
                Log::info($message, ['activity' => $activity]);
            } catch (\Throwable) {
                // A failed log sink must not turn an already committed action into a failure.
                error_log('Unable to write user activity '.$activity['event_id'].' ('.$activity['action'].').');
            }
        });
    }

    /** @param array{id: int, name: string}|null $actor
     * @param  array<string, int>  $counts
     */
    public function summary(?array $actor, string $operation, string $operationId, array $counts, bool $testMode = false): void
    {
        $description = implode(', ', array_map(static fn (string $key, int $count): string => "{$count} {$key}", array_keys($counts), array_values($counts)));
        $this->record($actor, 'batch.completed', "completed {$operation}: {$description} (operation {$operationId})", operationId: $operationId, testMode: $testMode);
    }

    /** @param array<string, mixed> $progress */
    public function importSummary(string $operationId, array $progress): void
    {
        $actor = $progress['activity_actor'] ?? null;
        $imported = (int) ($progress['imported'] ?? 0);
        $enriched = (int) ($progress['enriched'] ?? 0);
        $status = $progress['status'] ?? null;
        if (! is_array($actor) || ! is_int($actor['id'] ?? null) || ! is_string($actor['name'] ?? null)
            || $imported + $enriched === 0 || ! in_array($status, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }
        $actor = ['id' => $actor['id'], 'name' => $actor['name']];
        DB::afterCommit(function () use ($operationId, $progress, $actor, $status, $imported, $enriched): void {
            if (Cache::add(CacheKey::USER_ACTIVITY_IMPORT_SUMMARY->key($operationId), true, CacheKey::USER_ACTIVITY_IMPORT_SUMMARY->ttl())) {
                $this->summary($actor, 'import ('.$status.')', $operationId, [
                    'imported' => $imported, 'enriched' => $enriched,
                    'skipped' => (int) ($progress['skipped'] ?? 0), 'failed' => (int) ($progress['failed'] ?? 0),
                ]);
            }
        });
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'doi' => 'DOI', 'ftp_url' => 'FTP URL', 'template', 'landing_page_template_id' => 'Landing page template',
            'workflow_status_override', 'force_review_status' => 'Workflow status',
            default => Str::ucfirst(str_replace('_', ' ', $field)),
        };
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function normalize(array $values, bool $preserveOrder = false): array
    {
        $list = array_is_list($values);
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && (in_array($key, [
                'id', 'resource_id', 'landing_page_id', 'related_item_id', 'resource_creator_id',
                'resource_contributor_id', 'creatorable_id', 'contributorable_id', 'pivot',
                'affiliatable_id', 'related_item_creator_id', 'related_item_contributor_id',
                'created_at', 'updated_at', 'created_by_user_id', 'updated_by_user_id',
            ], true) || str_ends_with($key, '_normalized_at'))) {
                continue;
            }
            // Created/Updated system dates are generated on every save.
            if (is_array($value) && isset($value['date_type']['slug']) && in_array($value['date_type']['slug'], ['Created', 'Updated'], true)) {
                continue;
            }
            $normalized[$key] = is_array($value) ? $this->normalize($value, $preserveOrder || in_array($key, ['polygon_points', 'coordinates'], true)) : ($value === '' ? null : $value);
        }
        if ($list) {
            $normalized = array_values($normalized);
            if (! $preserveOrder) {
                usort($normalized, static fn (mixed $a, mixed $b): int => json_encode($a) <=> json_encode($b));
            }
        } else {
            ksort($normalized);
        }

        return $normalized;
    }
}
