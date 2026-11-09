<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Resource;
use App\Models\Subject;
use Illuminate\Support\Facades\Storage;

final class SubjectDuplicateFixtures
{
    public const AQUIFER = 'https://gcmd.earthdata.nasa.gov/kms/concept/a957363b-2f2c-4169-a656-c2f24933eb72';

    public const INSTRUMENT = 'https://gcmd.earthdata.nasa.gov/kms/concept/ff564c87-78f2-47eb-a857-a4bdc0a71ae5';

    public const PATH = 'EARTH SCIENCE > TERRESTRIAL HYDROSPHERE > GROUND WATER > GROUND WATER FEATURES > AQUIFERS';

    public const OLD_PATH = 'EARTH SCIENCE > TERRESTRIAL HYDROSPHERE > GROUND WATER > AQUIFERS';

    public static function fake(): void
    {
        Storage::fake('local');
        foreach (['science-keywords' => ['Science Keywords', self::PATH, self::AQUIFER, 'sciencekeywords'],
            'instruments' => ['Instruments', 'In Situ/Laboratory Instruments', self::INSTRUMENT, 'instruments']] as $file => [$scheme, $path, $uri, $suffix]) {
            $children = [];
            foreach (array_reverse(explode(' > ', $path)) as $index => $label) {
                $children = [['id' => $index === 0 ? $uri : $uri.'/parent-'.$index, 'text' => $label,
                    'scheme' => $scheme, 'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/'.$suffix,
                    'language' => 'en', 'children' => $children]];
            }
            Storage::disk('local')->put('gcmd-'.$file.'.json', json_encode(['lastUpdated' => '2026-11-06', 'data' => $children], JSON_THROW_ON_ERROR));
        }
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $case = 'aquifers'): array
    {
        $aquifers = $case === 'aquifers';
        $base = ['value' => $aquifers ? self::OLD_PATH : 'In Situ/Laboratory Instruments', 'language' => 'en',
            'subject_scheme' => $aquifers ? 'Science Keywords' : 'GCMD Instruments',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/'.($aquifers ? 'sciencekeywords' : 'instruments'),
            'value_uri' => $aquifers ? self::AQUIFER : null, 'classification_code' => null,
            'breadcrumb_path' => $aquifers ? self::PATH : 'In Situ/Laboratory Instruments'];

        return [$base, array_replace($base, ['value' => $aquifers ? self::PATH : $base['value'],
            'subject_scheme' => $aquifers ? 'Science Keywords' : 'Instruments', 'value_uri' => $aquifers ? self::AQUIFER : self::INSTRUMENT])];
    }

    /** @return list<array<string, mixed>> */
    public static function keywords(string $case = 'aquifers'): array
    {
        return array_map(static fn (array $row): array => ['id' => $row['value_uri'] ?? 'legacy:instrument', 'text' => $row['value'],
            'path' => $row['breadcrumb_path'], 'scheme' => $row['subject_scheme'], 'schemeURI' => $row['scheme_uri'], 'language' => $row['language']], self::rows($case));
    }

    public static function resource(string $case = 'aquifers'): Resource
    {
        $resource = Resource::factory()->create();
        Subject::withoutEvents(fn () => $resource->subjects()->createMany(self::rows($case)));

        return $resource;
    }
}
