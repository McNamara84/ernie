<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sampleLimit = 25;
$summary = [
    'resources' => Resource::query()->count(),
    'creators' => emptyPartySummary(),
    'contributors' => emptyPartySummary(),
    'intermagnet_10_5880_intermagnet_1991_2020' => intermagnetSummary($sampleLimit),
];

auditParties(ResourceCreator::class, 'creatorable', 'creators', $summary, $sampleLimit);
auditParties(ResourceContributor::class, 'contributorable', 'contributors', $summary, $sampleLimit);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

/**
 * @return array<string, mixed>
 */
function emptyPartySummary(): array
{
    return [
        'audited' => 0,
        'persons' => 0,
        'institutions' => 0,
        'missing_party' => 0,
        'person_that_looks_organizational' => 0,
        'institution_that_looks_personal' => 0,
        'person_that_looks_organizational_samples' => [],
        'institution_that_looks_personal_samples' => [],
    ];
}

/**
 * @param  class-string<ResourceCreator|ResourceContributor>  $modelClass
 * @param  array<string, mixed>  $summary
 */
function auditParties(string $modelClass, string $relation, string $summaryKey, array &$summary, int $sampleLimit): void
{
    $modelClass::query()
        ->with(['resource:id,doi', $relation])
        ->orderBy('id')
        ->chunkById(1000, function ($rows) use ($relation, $summaryKey, &$summary, $sampleLimit): void {
            foreach ($rows as $row) {
                $summary[$summaryKey]['audited']++;

                $party = $row->{$relation};
                if (! $party instanceof Model) {
                    $summary[$summaryKey]['missing_party']++;

                    continue;
                }

                if ($party instanceof Person) {
                    $summary[$summaryKey]['persons']++;

                    if (! hasScheme($party, 'ORCID') && personLooksOrganizational($party)) {
                        recordFlag($summary[$summaryKey], 'person_that_looks_organizational', $row, personNames($party)[0] ?? '', $sampleLimit);
                    }

                    continue;
                }

                if ($party instanceof Institution) {
                    $summary[$summaryKey]['institutions']++;

                    if (! hasScheme($party, 'ROR') && looksLikePersonName($party->name ?? '')) {
                        recordFlag($summary[$summaryKey], 'institution_that_looks_personal', $row, $party->name ?? '', $sampleLimit);
                    }
                }
            }
        });
}

/**
 * @param  array<string, mixed>  $partySummary
 */
function recordFlag(array &$partySummary, string $counter, mixed $row, string $name, int $sampleLimit): void
{
    $partySummary[$counter]++;

    $samplesKey = "{$counter}_samples";
    if (count($partySummary[$samplesKey]) >= $sampleLimit) {
        return;
    }

    $partySummary[$samplesKey][] = [
        'row_id' => $row->id,
        'resource_id' => $row->resource_id,
        'doi' => $row->resource?->doi,
        'position' => $row->position,
        'name' => $name,
    ];
}

function hasScheme(Person|Institution $party, string $scheme): bool
{
    return strtoupper((string) $party->name_identifier_scheme) === $scheme && filled($party->name_identifier);
}

function personLooksOrganizational(Person $person): bool
{
    foreach (personNames($person) as $name) {
        if (looksLikeOrganization($name, false)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function personNames(Person $person): array
{
    $given = trim((string) $person->given_name);
    $family = trim((string) $person->family_name);

    return array_values(array_unique(array_filter([
        trim("{$given} {$family}"),
        trim("{$family} {$given}"),
        $given !== '' && $family !== '' ? "{$family}, {$given}" : '',
        $family,
        $given,
    ], fn (string $name): bool => $name !== '')));
}

function looksLikeOrganization(string $name, bool $includeWeakShapeSignals = true): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }

    $keywords = [
        'academy',
        'administration',
        'agency',
        'association',
        'authority',
        'branch',
        'bureau',
        'center',
        'centre',
        'college',
        'commission',
        'committee',
        'consortium',
        'corporation',
        'council',
        'department',
        'directorate',
        'earthquake',
        'ecole',
        'facility',
        'federal',
        'foundation',
        'geological',
        'geology',
        'geomagnetic',
        'geoforschungszentrum',
        'geophysical',
        'geoscience',
        'gmbh',
        'government',
        'group',
        'institute',
        'institution',
        'institut',
        'instituto',
        'intermagnet',
        'laboratory',
        'lab',
        'meteorological',
        'ministry',
        'national',
        'observatory',
        'observatorio',
        'office',
        'organisation',
        'organization',
        'project',
        'research',
        'resources',
        'royal',
        'school',
        'secretariat',
        'seismology',
        'service',
        'society',
        'space',
        'survey',
        'university',
        'universidad',
        'universit',
        'universitat',
        'universitaet',
    ];

    if (preg_match('/\b('.implode('|', array_map('preg_quote', $keywords)).')\b/i', $name) === 1) {
        return true;
    }

    if (preg_match('/^[A-Z0-9&.\- ]{3,}$/', $name) === 1 && preg_match('/[A-Z]/', $name) === 1) {
        return true;
    }

    if (! str_contains($name, ',') && preg_match('/\([A-Za-z][A-Za-z .&-]{2,}\)\s*$/', $name) === 1) {
        return true;
    }

    if (! $includeWeakShapeSignals) {
        return false;
    }

    return wordCount($name) >= 4
        && preg_match('/(?:\/|&|\b(?:of|for|and|de|del|du|des|der|la|le|fuer|et)\b)/i', $name) === 1;
}

function looksLikePersonName(string $name): bool
{
    $name = trim($name);
    if ($name === '' || looksLikeOrganization($name)) {
        return false;
    }

    $word = "\\p{Lu}\\p{Ll}[\\p{L}'-]*";
    $givenPart = "(?:{$word}|\\p{Lu}\\.)(?:\\s+(?:{$word}|\\p{Lu}\\.))*";

    return preg_match("/^{$word},\\s*{$givenPart}$/u", $name) === 1
        || preg_match("/^{$word}\\s+{$word}$/u", $name) === 1;
}

function wordCount(string $name): int
{
    if (preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches) === false) {
        return 0;
    }

    return count($matches[0]);
}

/**
 * @return array<string, mixed>
 */
function intermagnetSummary(int $sampleLimit): array
{
    $resource = Resource::query()
        ->where('doi', '10.5880/intermagnet.1991.2020')
        ->with(['creators.creatorable', 'contributors.contributorable'])
        ->first();

    if ($resource === null) {
        return ['found' => false];
    }

    return [
        'found' => true,
        'resource_id' => $resource->id,
        'creator_type_counts' => typeCounts($resource->creators, 'creatorable'),
        'contributor_type_counts' => typeCounts($resource->contributors, 'contributorable'),
        'creator_samples' => partySamples($resource->creators, 'creatorable', $sampleLimit),
        'contributor_samples' => partySamples($resource->contributors, 'contributorable', $sampleLimit),
    ];
}

function typeLabel(mixed $party): string
{
    return match (true) {
        $party instanceof Person => 'Person',
        $party instanceof Institution => 'Institution',
        default => 'Missing',
    };
}

function partyName(mixed $party): string
{
    if ($party instanceof Person) {
        return $party->full_name;
    }

    if ($party instanceof Institution) {
        return $party->name;
    }

    return '';
}

/**
 * @return array<string, int>
 */
function typeCounts($rows, string $relation): array
{
    $counts = ['Person' => 0, 'Institution' => 0, 'Missing' => 0];

    foreach ($rows as $row) {
        $counts[typeLabel($row->{$relation})]++;
    }

    return $counts;
}

/**
 * @return list<array<string, mixed>>
 */
function partySamples($rows, string $relation, int $sampleLimit): array
{
    $samples = [];

    foreach ($rows as $row) {
        if (count($samples) >= $sampleLimit) {
            break;
        }

        $samples[] = [
            'position' => $row->position,
            'type' => typeLabel($row->{$relation}),
            'name' => partyName($row->{$relation}),
        ];
    }

    return $samples;
}
