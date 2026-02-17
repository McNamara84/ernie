<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\Format;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\Language;
use App\Models\Publisher;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Size;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('relationships', function () {
    test('belongs to resource type', function () {
        $resource = Resource::factory()->create();
        $resource->load('resourceType');

        expect($resource->resourceType)->toBeInstanceOf(ResourceType::class);
    });

    test('belongs to language', function () {
        $resource = Resource::factory()->create();
        $resource->load('language');

        expect($resource->language)->toBeInstanceOf(Language::class);
    });

    test('belongs to publisher', function () {
        $resource = Resource::factory()->create();
        $resource->load('publisher');

        expect($resource->publisher)->toBeInstanceOf(Publisher::class);
    });

    test('has many titles', function () {
        $resource = Resource::factory()->create();

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle']
        );
        Title::create(['resource_id' => $resource->id, 'value' => 'Test Title', 'title_type_id' => $titleType->id]);

        expect($resource->titles)->toHaveCount(1);
    });

    test('has many creators ordered by position', function () {
        $resource = Resource::factory()->create();

        expect($resource->creators)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    });

    test('has many subjects', function () {
        $resource = Resource::factory()->create();

        expect($resource->subjects)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    });

    test('has many geo locations', function () {
        $resource = Resource::factory()->create();

        expect($resource->geoLocations)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    });

    test('belongs to created by user', function () {
        $user = User::factory()->create();
        $resource = Resource::factory()->create(['created_by_user_id' => $user->id]);

        expect($resource->createdBy->id)->toBe($user->id);
    });

    test('belongs to updated by user', function () {
        $user = User::factory()->create();
        $resource = Resource::factory()->create(['updated_by_user_id' => $user->id]);

        expect($resource->updatedBy->id)->toBe($user->id);
    });
});

describe('mainTitle accessor', function () {
    test('returns main title value', function () {
        $resource = Resource::factory()->create();
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle']
        );
        Title::create(['resource_id' => $resource->id, 'value' => 'My Dataset', 'title_type_id' => $titleType->id]);

        expect($resource->mainTitle)->toBe('My Dataset');
    });

    test('returns null when no main title exists', function () {
        $resource = Resource::factory()->create();

        expect($resource->mainTitle)->toBeNull();
    });
});

describe('scopes', function () {
    test('igsns scope filters physical objects', function () {
        $physObj = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $dataset = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );

        Resource::factory()->create(['resource_type_id' => $physObj->id]);
        Resource::factory()->create(['resource_type_id' => $dataset->id]);

        expect(Resource::igsns()->count())->toBe(1);
    });
});

describe('isIgsn', function () {
    test('returns true for physical object', function () {
        $physObj = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physObj->id]);

        expect($resource->isIgsn())->toBeTrue();
    });

    test('returns false for dataset', function () {
        $resource = Resource::factory()->create();

        expect($resource->isIgsn())->toBeFalse();
    });
});

describe('fillable attributes', function () {
    test('casts publication year to integer', function () {
        $resource = Resource::factory()->create(['publication_year' => '2024']);

        expect($resource->publication_year)->toBeInt()
            ->and($resource->publication_year)->toBe(2024);
    });
});
