<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\OaiPmh\OaiPmhDatestampService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Description('Hydrate missing citation labels for existing DOI and URL related identifiers.')]
#[Signature('related-identifiers:hydrate-citation-labels
                            {--limit=0 : Maximum number of missing related identifiers to process (0 = all)}
                            {--after-id=0 : Process only related identifiers with an ID greater than this cursor}')]
class HydrateRelatedIdentifierCitationLabels extends Command
{
    public function __construct(
        private readonly RelatedIdentifierCitationLabelService $citationLabelService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $identifierTypeIds = IdentifierType::query()
            ->whereIn('slug', ['DOI', 'URL'])
            ->pluck('id', 'slug');

        if (! $identifierTypeIds->has('DOI') || ! $identifierTypeIds->has('URL')) {
            $this->error('DOI and URL identifier types not found. Seed identifier types before running this command.');

            return self::FAILURE;
        }

        $afterId = max(0, (int) $this->option('after-id'));

        $baseQuery = RelatedIdentifier::query()
            ->with('identifierType')
            ->whereIn('identifier_type_id', $identifierTypeIds->values()->all())
            ->where(function ($query): void {
                $query->whereNull('citation_label')
                    ->orWhere('citation_label', '');
            })
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '')
            ->where('id', '>', $afterId);

        $limit = max(0, (int) $this->option('limit'));

        $query = clone $baseQuery;

        if ($limit > 0) {
            $query = RelatedIdentifier::query()
                ->with('identifierType')
                ->whereKey(
                    (clone $baseQuery)
                        ->orderBy('id')
                        ->limit($limit)
                        ->pluck('id'),
                );
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No missing DOI or URL citation labels found.');

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $unresolved = 0;
        $lastProcessedId = $afterId;

        $this->info("Hydrating missing citation labels for {$total} DOI or URL related identifier(s)...");

        $query->orderBy('id')->chunkById(100, function ($relatedIdentifiers) use (&$lastProcessedId, &$processed, &$unresolved, &$updated): void {
            $storagePayload = [];

            foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
                $processed++;
                $lastProcessedId = max($lastProcessedId, (int) $relatedIdentifier->getKey());
                $storagePayload[$index] = [
                    'identifier' => $relatedIdentifier->identifier,
                    'identifierType' => $relatedIdentifier->identifierType->slug,
                ];
            }

            $resolvedPayload = $this->citationLabelService
                ->resolveExhaustiveForStorage($storagePayload);

            foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
                $citationLabel = isset($resolvedPayload[$index]['citationLabel'])
                    ? trim((string) $resolvedPayload[$index]['citationLabel'])
                    : '';

                if ($citationLabel === '') {
                    $unresolved++;

                    continue;
                }

                $updated += DB::transaction(function () use ($relatedIdentifier, $resolvedPayload, $index, $citationLabel): int {
                    $resource = Resource::query()->lockForUpdate()->find($relatedIdentifier->resource_id);
                    $current = RelatedIdentifier::query()->lockForUpdate()->find($relatedIdentifier->id);
                    if ($resource === null || $current === null || $current->resource_id !== $resource->id || $current->identifier !== $relatedIdentifier->identifier
                        || $current->identifier_type_id !== $relatedIdentifier->identifier_type_id || trim((string) $current->citation_label) !== '') {
                        return 0;
                    }

                    $current->update([
                        'citation_label' => $citationLabel,
                        'citation_label_parts' => $resolvedPayload[$index]['citationLabelParts'] ?? null,
                    ]);

                    app(OaiPmhDatestampService::class)->touchResource($resource->id);
                    app(ResourceListingProjectionRefreshService::class)->schedule($resource->id);
                    if (($page = $resource->landingPage) !== null) {
                        DB::afterCommit(fn () => app(LandingPageRenderDataCacheService::class)->forgetById($page->id));
                    }

                    return 1;
                });
            }
        });

        $this->info("Processed {$processed} missing DOI or URL related identifier(s).");
        $this->info("Hydrated {$updated} citation label(s).");

        if ($limit > 0 && (clone $baseQuery)->where('id', '>', $lastProcessedId)->exists()) {
            $this->line(
                "Continue with: php artisan related-identifiers:hydrate-citation-labels --limit={$limit} --after-id={$lastProcessedId}",
            );
        }

        if ($unresolved > 0) {
            $this->warn("{$unresolved} related identifier(s) remain without a citation label.");
        }

        return self::SUCCESS;
    }
}
