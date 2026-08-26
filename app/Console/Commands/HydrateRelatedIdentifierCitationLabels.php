<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Hydrate missing citation labels for existing DOI and URL related identifiers.')]
#[Signature('related-identifiers:hydrate-citation-labels
                            {--limit=0 : Maximum number of missing related identifiers to process (0 = all)}')]
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

        $baseQuery = RelatedIdentifier::query()
            ->with('identifierType')
            ->whereIn('identifier_type_id', $identifierTypeIds->values()->all())
            ->where(function ($query): void {
                $query->whereNull('citation_label')
                    ->orWhere('citation_label', '');
            })
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '');

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

        $this->info("Hydrating missing citation labels for {$total} DOI or URL related identifier(s)...");

        $query->orderBy('id')->chunkById(100, function ($relatedIdentifiers) use (&$processed, &$unresolved, &$updated): void {
            $storagePayload = [];

            foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
                $processed++;
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

                $relatedIdentifier->refresh();

                if (is_string($relatedIdentifier->citation_label) && trim($relatedIdentifier->citation_label) !== '') {
                    continue;
                }

                $relatedIdentifier->forceFill([
                    'citation_label' => $citationLabel,
                ])->save();

                $updated++;
            }
        });

        $this->info("Processed {$processed} missing DOI or URL related identifier(s).");
        $this->info("Hydrated {$updated} citation label(s).");

        if ($unresolved > 0) {
            $this->warn("{$unresolved} related identifier(s) remain without a citation label.");
        }

        return self::SUCCESS;
    }
}
