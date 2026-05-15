<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Hydrate missing citation labels for existing DOI related identifiers.')]
#[Signature('related-identifiers:hydrate-citation-labels
                            {--limit=0 : Maximum number of missing DOI related identifiers to process (0 = all)}')]
class HydrateRelatedIdentifierCitationLabels extends Command
{
    public function __construct(
        private readonly RelatedIdentifierCitationLabelService $citationLabelService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $doiTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');

        if (! is_int($doiTypeId)) {
            $this->error('DOI identifier type not found. Seed identifier types before running this command.');

            return self::FAILURE;
        }

        $baseQuery = RelatedIdentifier::query()
            ->where('identifier_type_id', $doiTypeId)
            ->where(function ($query): void {
                $query->whereNull('citation_label')
                    ->orWhere('citation_label', '');
            })
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '');

        $limit = max(0, (int) $this->option('limit'));

        $query = clone $baseQuery;

        if ($limit > 0) {
            $query = RelatedIdentifier::query()->whereKey(
                (clone $baseQuery)
                    ->orderBy('id')
                    ->limit($limit)
                    ->pluck('id'),
            );
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No missing DOI citation labels found.');

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;

        $this->info("Hydrating missing citation labels for {$total} DOI related identifier(s)...");

        $query->orderBy('id')->chunkById(100, function ($relatedIdentifiers) use (&$processed, &$updated): void {
            foreach ($relatedIdentifiers as $relatedIdentifier) {
                $processed++;

                $citationLabel = $this->citationLabelService->resolve($relatedIdentifier->identifier, 'DOI');

                if (! is_string($citationLabel) || trim($citationLabel) === '') {
                    continue;
                }

                $relatedIdentifier->forceFill([
                    'citation_label' => trim($citationLabel),
                ])->save();

                $updated++;
            }
        });

        $this->info("Processed {$processed} missing DOI related identifier(s).");
        $this->info("Hydrated {$updated} citation label(s).");

        if ($updated < $processed) {
            $remaining = $processed - $updated;
            $this->warn("{$remaining} DOI related identifier(s) remain without a citation label.");
        }

        return self::SUCCESS;
    }
}