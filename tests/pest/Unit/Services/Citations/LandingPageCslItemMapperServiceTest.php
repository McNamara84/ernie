<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Citations\LandingPageCslItemMapperService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

covers(LandingPageCslItemMapperService::class);

/**
 * @param  list<ResourceCreator>  $creators
 */
function landingPageCslResource(
    array $attributes = [],
    ?ResourceType $resourceType = null,
    array $creators = [],
    ?string $title = null,
    ?Publisher $publisher = null,
    ?Language $language = null,
): Resource {
    $resource = new Resource($attributes);
    $resource->setAttribute('id', $attributes['id'] ?? 42);
    $resource->setRelation('resourceType', $resourceType);
    $resource->setRelation('creators', new EloquentCollection($creators));
    $resource->setRelation('publisher', $publisher);
    $resource->setRelation('language', $language);

    $titles = [];
    if ($title !== null) {
        $titleType = new TitleType([
            'name' => 'Main Title',
            'slug' => 'MainTitle',
        ]);
        $titleType->setAttribute('id', 1);

        $mainTitle = new Title([
            'value' => $title,
            'title_type_id' => 1,
        ]);
        $mainTitle->setRelation('titleType', $titleType);
        $titles[] = $mainTitle;
    }

    $resource->setRelation('titles', new EloquentCollection($titles));

    return $resource;
}

function landingPageCslPersonCreator(
    int $position,
    ?string $given,
    ?string $family,
): ResourceCreator {
    $creator = new ResourceCreator([
        'creatorable_type' => Person::class,
        'creatorable_id' => 1,
        'position' => $position,
    ]);
    $creator->setRelation('creatorable', new Person([
        'given_name' => $given,
        'family_name' => $family,
    ]));

    return $creator;
}

function landingPageCslInstitutionCreator(int $position, string $name): ResourceCreator
{
    $creator = new ResourceCreator([
        'creatorable_type' => Institution::class,
        'creatorable_id' => 1,
        'position' => $position,
    ]);
    $creator->setRelation('creatorable', new Institution(['name' => $name]));

    return $creator;
}

it('maps complete metadata and every creator in position order', function () {
    $resourceType = new ResourceType(['name' => 'Dataset', 'slug' => 'dataset']);
    $publisher = new Publisher(['name' => '  Research Data Publisher  ']);
    $language = new Language(['code' => 'en']);

    $resource = landingPageCslResource(
        attributes: [
            'id' => 123,
            'doi' => ' HTTPS://DOI.ORG/10.5880/TEST.ABC ',
            'publication_year' => 2025,
            'version' => ' 2.1 ',
        ],
        resourceType: $resourceType,
        creators: [
            landingPageCslInstitutionCreator(30, 'GFZ Helmholtz Centre'),
            landingPageCslPersonCreator(20, 'Ada', 'Lovelace'),
            landingPageCslPersonCreator(10, '   ', null),
        ],
        title: '  A deterministic dataset  ',
        publisher: $publisher,
        language: $language,
    );

    expect((new LandingPageCslItemMapperService)->map($resource))->toBe([
        'id' => '10.5880/test.abc',
        'type' => 'dataset',
        'title' => 'A deterministic dataset',
        'author' => [
            ['family' => 'Lovelace', 'given' => 'Ada'],
            ['literal' => 'GFZ Helmholtz Centre'],
        ],
        'issued' => ['date-parts' => [[2025]]],
        'publisher' => 'Research Data Publisher',
        'DOI' => '10.5880/test.abc',
        'URL' => 'https://doi.org/10.5880/test.abc',
        'version' => '2.1',
        'language' => 'en',
    ]);
});

it('omits every empty optional field instead of inventing citation metadata', function () {
    $resource = landingPageCslResource([
        'id' => 77,
        'doi' => '  ',
        'publication_year' => null,
        'version' => ' ',
    ]);

    expect((new LandingPageCslItemMapperService)->map($resource))->toBe([
        'id' => 'ernie-resource-77',
        'type' => 'document',
    ]);
});

it('normalizes bare and prefixed DOI values', function (string $input, string $expected) {
    $resource = landingPageCslResource([
        'id' => 1,
        'doi' => $input,
    ]);

    $item = (new LandingPageCslItemMapperService)->map($resource);

    expect($item)
        ->id->toBe($expected)
        ->DOI->toBe($expected)
        ->URL->toBe('https://doi.org/'.$expected);
})->with([
    'bare DOI' => ['10.5880/GFZ.1', '10.5880/gfz.1'],
    'doi label' => ['doi: 10.1234/ABC', '10.1234/abc'],
    'legacy dx URL' => ['http://dx.doi.org/10.9999/Thing', '10.9999/thing'],
]);

it('maps DataCite resource types to explicit CSL types', function (
    string $slug,
    string $name,
    string $expectedType,
    ?string $expectedGenre,
) {
    $resource = landingPageCslResource(
        resourceType: new ResourceType(['name' => $name, 'slug' => $slug]),
    );

    $item = (new LandingPageCslItemMapperService)->map($resource);

    expect($item['type'])->toBe($expectedType);

    if ($expectedGenre === null) {
        expect($item)->not->toHaveKey('genre');
    } else {
        expect($item['genre'])->toBe($expectedGenre);
    }
})->with([
    'dataset' => ['dataset', 'Dataset', 'dataset', null],
    'software' => ['software', 'Software', 'software', null],
    'book chapter' => ['book-chapter', 'Book chapter', 'chapter', null],
    'conference paper' => ['conference-paper', 'Conference paper', 'paper-conference', null],
    'data paper' => ['data-paper', 'Data paper', 'article-journal', null],
    'dissertation' => ['dissertation', 'Dissertation', 'thesis', null],
    'image' => ['image', 'Image', 'graphic', null],
    'journal article' => ['journal-article', 'Journal article', 'article-journal', null],
    'preprint' => ['preprint', 'Preprint', 'manuscript', null],
    'standard' => ['standard', 'Standard', 'standard', null],
    'workflow' => ['workflow', 'Workflow', 'software', null],
    'physical object' => ['physical-object', 'Physical Object', 'document', 'Physical object'],
    'model' => ['model', 'Model', 'document', 'Model'],
    'study registration' => ['study-registration', 'Study registration', 'document', 'Study registration'],
]);

it('falls unknown resource types back to document while retaining their name', function () {
    $resource = landingPageCslResource(
        resourceType: new ResourceType([
            'name' => 'Curated rock sample',
            'slug' => 'curated-rock-sample',
        ]),
    );

    expect((new LandingPageCslItemMapperService)->map($resource))
        ->type->toBe('document')
        ->genre->toBe('Curated rock sample');
});

it('maps partial personal names and discards unsupported creator models', function () {
    $givenOnly = landingPageCslPersonCreator(1, 'Cher', null);
    $familyOnly = landingPageCslPersonCreator(2, null, 'Plato');

    $unsupported = new ResourceCreator([
        'creatorable_type' => Resource::class,
        'creatorable_id' => 9,
        'position' => 3,
    ]);
    $unsupported->setRelation('creatorable', landingPageCslResource(['id' => 9]));

    $resource = landingPageCslResource(
        creators: [$unsupported, $familyOnly, $givenOnly],
    );

    expect((new LandingPageCslItemMapperService)->map($resource)['author'])->toBe([
        ['given' => 'Cher'],
        ['family' => 'Plato'],
    ]);
});
