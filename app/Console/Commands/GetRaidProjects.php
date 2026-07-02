<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch public RAiD project records from DataCite and store them locally.
 */
#[Description('Fetch public RAiD project records from DataCite and store locally')]
#[Signature('get-raid-projects {--output= : Override the output file path}')]
class GetRaidProjects extends Command
{
    private const OUTPUT_RELATIVE_PATH = 'raid/raid-projects.json';

    public function handle(): int
    {
        $endpoint = rtrim((string) config('raid.datacite_endpoint'), '/');
        $query = (string) config('raid.search_query');
        $pageSize = max(1, (int) config('raid.page_size', 1000));
        $page = 1;
        $total = 0;
        $totalPages = 1;

        /** @var list<array<string, mixed>> $projects */
        $projects = [];

        $this->info('Fetching public RAiD projects from DataCite...');

        do {
            $response = Http::timeout(60)
                ->acceptJson()
                ->get("{$endpoint}/dois", [
                    'query' => $query,
                    'page[size]' => $pageSize,
                    'page[number]' => $page,
                ]);

            if (! $response->successful()) {
                $this->error("Failed to fetch RAiD projects page {$page}: HTTP {$response->status()}");

                return self::FAILURE;
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->json();
            /** @var mixed $rawItems */
            $rawItems = Arr::get($payload, 'data', []);
            /** @var list<array<string, mixed>> $items */
            $items = is_array($rawItems)
                ? array_values(array_filter($rawItems, static fn (mixed $item): bool => is_array($item)))
                : [];
            $total = (int) Arr::get($payload, 'meta.total', count($items));
            $totalPages = max(1, (int) Arr::get($payload, 'meta.totalPages', $total > 0 ? (int) ceil($total / $pageSize) : 1));

            foreach ($items as $item) {
                $projects[] = $this->transformRecord($item);
            }

            $this->info('Page '.$page.': fetched '.count($items).' records (total so far: '.count($projects)."/{$total})");
            $page++;
        } while ($page <= $totalPages && count($items) > 0);

        if ($total > 0 && count($projects) === 0) {
            $this->error('DataCite reported RAiD records, but no records could be transformed.');

            return self::FAILURE;
        }

        $json = json_encode([
            'lastUpdated' => now()->toIso8601String(),
            'total' => count($projects),
            'data' => $projects,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Failed to encode RAiD projects as JSON.');

            return self::FAILURE;
        }

        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $json);
        } else {
            Storage::put(self::OUTPUT_RELATIVE_PATH, $json);
        }

        Artisan::call('cache:clear-app', ['category' => 'vocabularies']);

        $this->info('Successfully stored '.count($projects).' RAiD projects.');

        return self::SUCCESS;
    }

    /**
     * Transform a DataCite DOI record into a normalized RAiD project structure.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function transformRecord(array $record): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = Arr::get($record, 'attributes', []);
        $doi = (string) Arr::get($attributes, 'doi', '');
        $raidId = $doi !== '' ? "https://raid.org/{$doi}" : null;
        $url = $this->uriOrNull(Arr::get($attributes, 'url')) ?? $raidId;
        $title = $this->firstTextValue(Arr::get($attributes, 'titles', []), 'title');
        $description = $this->firstTextValue(Arr::get($attributes, 'descriptions', []), 'description');
        $contributors = $this->mapParties(Arr::get($attributes, 'creators', []), 'Creator');
        $organisations = $this->mapOrganisations(Arr::get($attributes, 'contributors', []));

        return [
            'id' => (string) Arr::get($record, 'id', $doi),
            'doi' => $doi,
            'raidId' => $raidId,
            'title' => $title,
            'titles' => $this->mapSimpleList(Arr::get($attributes, 'titles', []), ['title', 'lang']),
            'description' => $description,
            'descriptions' => $this->mapSimpleList(Arr::get($attributes, 'descriptions', []), ['description', 'descriptionType', 'lang']),
            'url' => $url,
            'downloadUrl' => $doi !== '' ? "https://static.prod.raid.org.au/raids/{$doi}.download/" : null,
            'publicationYear' => Arr::get($attributes, 'publicationYear'),
            'publisher' => Arr::get($attributes, 'publisher'),
            'dates' => $this->mapSimpleList(Arr::get($attributes, 'dates', []), ['date', 'dateType']),
            'contributors' => $contributors,
            'organisations' => $organisations,
            'relatedIdentifiers' => $this->mapSimpleList(Arr::get($attributes, 'relatedIdentifiers', []), ['relatedIdentifier', 'relatedIdentifierType', 'relationType', 'resourceTypeGeneral']),
            'subjects' => $this->mapSimpleList(Arr::get($attributes, 'subjects', []), ['subject', 'subjectScheme', 'schemeUri', 'valueUri']),
            'registered' => Arr::get($attributes, 'registered'),
            'updated' => Arr::get($attributes, 'updated'),
            'searchTerms' => $this->buildSearchTerms($title, $description, $contributors, $organisations),
        ];
    }

    private function firstTextValue(mixed $items, string $key): string
    {
        if (! is_array($items)) {
            return '';
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = Arr::get($item, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function uriOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $uri = trim($value);

        return $uri !== '' && filter_var($uri, FILTER_VALIDATE_URL) !== false
            ? $uri
            : null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function mapSimpleList(mixed $items, array $keys): array
    {
        if (! is_array($items)) {
            return [];
        }

        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $entry = [];

            foreach ($keys as $key) {
                $entry[$key] = Arr::get($item, $key);
            }

            $mapped[] = $entry;
        }

        return $mapped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapParties(mixed $parties, string $defaultRole): array
    {
        if (! is_array($parties)) {
            return [];
        }

        $mapped = [];

        foreach ($parties as $party) {
            if (! is_array($party)) {
                continue;
            }

            $mapped[] = [
                'name' => Arr::get($party, 'name'),
                'nameType' => Arr::get($party, 'nameType'),
                'role' => Arr::get($party, 'contributorType', $defaultRole),
                'nameIdentifiers' => $this->mapSimpleList(Arr::get($party, 'nameIdentifiers', []), ['nameIdentifier', 'nameIdentifierScheme', 'schemeUri']),
                'affiliations' => $this->mapSimpleList(Arr::get($party, 'affiliation', []), ['name', 'affiliationIdentifier', 'affiliationIdentifierScheme', 'schemeUri']),
            ];
        }

        return $mapped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapOrganisations(mixed $parties): array
    {
        return array_values(array_filter(
            $this->mapParties($parties, 'Contributor'),
            fn (array $party): bool => ($party['nameType'] ?? null) === 'Organizational'
                || $this->hasRorIdentifier($party['nameIdentifiers'] ?? [])
        ));
    }

    private function hasRorIdentifier(mixed $identifiers): bool
    {
        if (! is_array($identifiers)) {
            return false;
        }

        foreach ($identifiers as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }

            if (($identifier['nameIdentifierScheme'] ?? null) === 'ROR') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $contributors
     * @param  list<array<string, mixed>>  $organisations
     * @return list<string>
     */
    private function buildSearchTerms(string $title, string $description, array $contributors, array $organisations): array
    {
        $terms = [$title, $description];

        foreach (array_merge($contributors, $organisations) as $party) {
            if (is_string($party['name'] ?? null)) {
                $terms[] = $party['name'];
            }
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $terms),
            fn (string $term): bool => trim($term) !== ''
        )));
    }
}
