<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\Resource;
use App\Services\DateType\DateTypeSchemaorgExtraction;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class DateTypeSuggestionDiscoveryService
{
    // private const = ein Wert der sich nie ändern darf
    // der Unterschied zur Variable = Variable darf verändert werden und Konstante nicht 
    // bei Änderungen müsste man nur diese Zahl ändern und nicht im ganzen Code 
    private const int CHUNK_SIZE = 50;

    public function __construct(
        private readonly DateTypeSchemaorgExtraction $extractService,
    ) {}

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    // die Funktio bekommt vom Assistenten: $assistantId, Closure $storeSuggestion, Closure $onProgress
    public function discover(string $assistantId, Closure $storeSuggestion, Closure $onProgress): int
    {
        // zählt wie viele Suggestion gespeichert werden 
        $count = 0;
        // zählt wie viele Resourcen geprüft wurden 
        $processed = 0;
        // holt Datenbankabfrage für passende Resources
        $query = $this->candidateQuery();
        // clone: query wird kopiert, damit die ursprüngliche query danach weiterverwendet werden kann
        // werden query zusammengezählt 
        $total = (clone $query)->count();

        $query
            // nach id sortieren 
            ->orderBy('id')
            // bedeutet, lade nicht alles gleich, sondern in 50er Blöcke 
            // self::CHUNK_SIZE = nimm diese Konstante aus dieser Klasse; Konstante gehört zur Klasse und nicht zu einem Objekt 
            // function = bekommt Parameter $resources
            // use = nimm diese Variablen von außerhalb und mach sie innerhalb der Closure verfügbar -> es werden Kopien erstellt
            // damit die originale und keine Kopien verändert werden, wird durch das "&" signalisiert -> nimm die Originale 
            ->chunkById(self::CHUNK_SIZE, function ($resources) use ( &$count, &$processed, $total, $assistantId, $storeSuggestion, $onProgress) : void {
               // @var iterable<int, Resource> $resources = Variable $resources enthält mehrere Resource-Objekte
                /** @var iterable<int, Resource> $resources */
                foreach ($resources as $resource) {
                    // ++ = erhöhe den Wert um 1
                    $processed++;
                    // hier wird resource x von beispielsweise 120 Resources sich angeschaut 
                    $onProgress("Checking resource {$processed} of {$total}");
                    // prüft die aktuelle Resource auf Suggestion
                    // die zurückgegebene Anzahl der suggestion wird zur Gesamtzahl addiert
                    $count += $this->discoverForResource($assistantId, $resource, $storeSuggestion);
                }
            });

        return $count;
    }

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     */
    private function discoverForResource(string $assistantId, Resource $resource, Closure $storeSuggestion): int
    {
        $storedCount = 0;
        $suggestions = $this->lookupSchemaorgDates($resource);
        $existingDateTypes = $resource->dates()
            ->with('dateType')
            ->get()
            ->pluck('dateType.slug')
            ->filter()
            ->all();

        $hasCreated = in_array('Created', $existingDateTypes, true);
        $hasIssued = in_array('Issued', $existingDateTypes, true);

        foreach ($suggestions as $suggestion) 
        {
            if (($suggestion['probe_method'] ?? null) === 'SKIP') {
                continue;
            }

            $type = (string) ($suggestion['target_date_type'] ?? '');

            if ($type === 'Created' && $hasCreated ) {
                continue;
            }

            if ($type === 'Issued' && $hasIssued) {
                continue;
            }

            if (! in_array($type, ['Created', 'Issued'], true)) {
                continue;
            }

            $suggestedValue = (string) ($suggestion['normalized_value'] ?? '');
            if ($suggestedValue === '') {
                continue;
            }

            $metadata = $suggestion;

            $stored = $storeSuggestion(
                $resource->id,
                'date_type',
                $resource->id,
                $suggestedValue,
                strtoupper($type).': '.$suggestedValue,
                $this->confidenceToScore($suggestion['confidence'] ?? null),
                $metadata,
            );

            if ($stored) {
                $storedCount++;
            }
        }

        return $storedCount;
    }

    /** @return Builder<Resource> */
    private function candidateQuery(): Builder
    {
        /** @var Builder<Resource> $query */
        $query = Resource::query()
            ->whereNotNull('doi')
            ->whereDoesntHave('igsnMetadata')
            ->whereDoesntHave('resourceType', fn (Builder $query): Builder => $query->where('slug', 'physical-object'))
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('dates', function (Builder $query): void 
                {
                    $query->whereHas('dateType', function (Builder $query): void  
                    {
                        $query->where('slug', 'Created');
                    });
                })
                ->orWhereDoesntHave('dates', function (Builder $query): void 
                {
                    $query->whereHas('dateType', function (Builder $query): void  
                    {
                        $query->where('slug', 'Issued'); 
                    });
                });
            });

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lookupSchemaorgDates(Resource $resource): array
    {
        $doi = trim((string) $resource->doi);

        if ($doi === '') 
        {
            return [];
        }

        return $this->extractService->loadAllowedSchemaorg($doi);
    }

    private function confidenceToScore(mixed $confidence): ?float
    {
        return match ($confidence) {
            'high' => 0.95,
            'medium' => 0.65,
            'low' => 0.35,
            default => null,
        };
    }
}

