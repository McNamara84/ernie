<?php

declare(strict_types=1);

namespace App\Services\RelationTypeCorrection;

use App\Enums\CacheKey;
use App\Models\AssistantSuggestion;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RelationCorrectionDiscoveryService
{
    public const ID = 'relation-type-correction';

    /** @var array<string, int> */
    private array $details = [];

    public function __construct(private readonly RelationMetadataClientService $client, private readonly RelationCorrectionCandidateService $candidate) {}

    /** @return array<string, int> */
    public function details(): array
    {
        return $this->details;
    }

    /** @param Closure(int, string, int, string, string, ?float, ?array<string, mixed>): bool $store
     * @param  Closure(string): void  $progress
     */
    public function discover(Closure $store, Closure $progress): int
    {
        $this->details = ['checked_identifiers' => 0, 'incomplete_or_failed_identifiers' => 0, 'stale_suggestions_removed' => 0];
        $created = 0;
        // Remove orphans without relying on every editor/import path to know this module.
        $this->details['stale_suggestions_removed'] += AssistantSuggestion::where('assistant_id', self::ID)
            ->where(function ($query): void {
                $query->where('target_type', '!=', 'related_identifier')->orWhereNotExists(function ($live): void {
                    $live->selectRaw('1')->from('related_identifiers')
                        ->whereColumn('related_identifiers.id', 'assistant_suggestions.target_id')
                        ->whereColumn('related_identifiers.resource_id', 'assistant_suggestions.resource_id');
                });
            })->delete();
        RelatedIdentifier::with(['resource', 'identifierType', 'relationType'])->chunkById(100, function ($targets) use ($store, $progress, &$created): void {
            foreach ($targets as $target) {
                $this->details['checked_identifiers']++;
                $before = $this->candidate->snapshot($target);
                $own = RelationTypeRules::doi($target->resource->doi ?? '');
                $other = RelationTypeRules::doi($target->identifier);
                $metadata = null;
                if ($own !== null && $other !== null && $own !== $other && $target->identifierType->slug === 'DOI'
                    && in_array($target->relationType->slug, [...RelationTypeRules::DIRECTIONAL, 'Other'], true)
                    && trim($target->relation_type_information ?? '') === '') {
                    $result = $this->client->forPair($own, $other);
                    if (! $result['complete']) {
                        $this->details['incomplete_or_failed_identifiers']++;

                        // A transient source failure is not evidence of disappearance.
                        continue;
                    }
                    $metadata = $this->candidate->build($target, $result['evidence'], $result['sources']);
                }
                DB::transaction(function () use ($target, $before, $metadata, $store, &$created): void {
                    Resource::whereKey($target->resource_id)->lockForUpdate()->first();
                    $live = RelatedIdentifier::with(['resource', 'identifierType', 'relationType'])->whereKey($target->id)->lockForUpdate()->first();
                    if ($live === null || $this->candidate->snapshot($live) !== $before) {
                        return;
                    }
                    $pending = AssistantSuggestion::where('assistant_id', self::ID)->where('target_type', 'related_identifier')->where('target_id', $live->id);
                    $value = $metadata === null ? null : $metadata['proposed']['slug'].':'.$metadata['context_fingerprint'];
                    $this->details['stale_suggestions_removed'] += (clone $pending)->when($value !== null, fn ($query) => $query->where('suggested_value', '!=', $value))->delete();
                    if ($metadata !== null && $value !== null && $store($live->resource_id, 'related_identifier', $live->id, $value,
                        $metadata['proposed']['name'], $metadata['confidence']['score'], $metadata)) {
                        $created++;
                    }
                });
            }
            $progress('Checked '.$this->details['checked_identifiers'].' related identifiers.');
        });
        if ($created > 0 || $this->details['stale_suggestions_removed'] > 0) {
            Cache::forget(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->key());
            Cache::forget(CacheKey::ASSISTANCE_DATACENTER_OPTIONS->key());
        }

        return $created;
    }
}
