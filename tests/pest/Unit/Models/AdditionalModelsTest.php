<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Models\ContributorType;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\TitleType;
use Database\Factories\ContributorTypeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// ContributorType Model
// ---------------------------------------------------------------------------
describe('ContributorType', function () {
    it('casts category to ContributorCategory enum', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->contactPerson()->create();

        expect($type->category)->toBeInstanceOf(ContributorCategory::class)
            ->and($type->category)->toBe(ContributorCategory::PERSON);
    });

    it('casts boolean fields correctly', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->create(['is_active' => true, 'is_elmo_active' => false]);

        expect($type->is_active)->toBeTrue()
            ->and($type->is_elmo_active)->toBeFalse();
    });

    it('filters active types via scope', function () {
        ContributorTypeFactory::new()->create(['is_active' => true, 'slug' => 'Active-' . uniqid()]);
        ContributorTypeFactory::new()->inactive()->create(['slug' => 'Inactive-' . uniqid()]);

        $active = ContributorType::active()->get();

        expect($active->every(fn ($t) => $t->is_active === true))->toBeTrue();
    });

    it('filters elmo active types via scope', function () {
        ContributorTypeFactory::new()->create(['is_elmo_active' => true, 'slug' => 'ElmoActive-' . uniqid()]);
        ContributorTypeFactory::new()->elmoInactive()->create(['slug' => 'ElmoInactive-' . uniqid()]);

        $elmo = ContributorType::elmoActive()->get();

        expect($elmo->every(fn ($t) => $t->is_elmo_active === true))->toBeTrue();
    });

    it('filters types for persons via scope', function () {
        ContributorTypeFactory::new()->create([
            'category' => ContributorCategory::PERSON,
            'slug' => 'PersonType-' . uniqid(),
        ]);
        ContributorTypeFactory::new()->institution()->create(['slug' => 'InstType-' . uniqid()]);

        $personTypes = ContributorType::forPersons()->get();

        expect($personTypes->every(fn ($t) => in_array($t->category, [ContributorCategory::PERSON, ContributorCategory::BOTH])))->toBeTrue();
    });

    it('filters types for institutions via scope', function () {
        ContributorTypeFactory::new()->institution()->create(['slug' => 'InstScope-' . uniqid()]);

        $instTypes = ContributorType::forInstitutions()->get();

        expect($instTypes->every(fn ($t) => in_array($t->category, [ContributorCategory::INSTITUTION, ContributorCategory::BOTH])))->toBeTrue();
    });

    it('has contributors relationship', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->create(['slug' => 'RelTest-' . uniqid()]);

        expect($type->contributors())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

// ---------------------------------------------------------------------------
// FunderIdentifierType Model
// ---------------------------------------------------------------------------
describe('FunderIdentifierType', function () {
    it('creates with fillable attributes', function () {
        $type = FunderIdentifierType::create([
            'name' => 'Crossref Funder ID',
            'slug' => 'crossref-funder-' . uniqid(),
            'is_active' => true,
        ]);

        expect($type->name)->toBe('Crossref Funder ID')
            ->and($type->is_active)->toBeTrue();
    });

    it('casts is_active to boolean', function () {
        $type = FunderIdentifierType::create([
            'name' => 'ROR',
            'slug' => 'ror-funder-' . uniqid(),
            'is_active' => false,
        ]);

        expect($type->is_active)->toBeFalse();
    });

    it('filters active types via scope', function () {
        FunderIdentifierType::create(['name' => 'Active', 'slug' => 'active-fit-' . uniqid(), 'is_active' => true]);
        FunderIdentifierType::create(['name' => 'Inactive', 'slug' => 'inactive-fit-' . uniqid(), 'is_active' => false]);

        $active = FunderIdentifierType::active()->get();

        expect($active->every(fn ($t) => $t->is_active === true))->toBeTrue();
    });

    it('has fundingReferences relationship', function () {
        $type = FunderIdentifierType::create([
            'name' => 'Test Type',
            'slug' => 'test-funder-rel-' . uniqid(),
            'is_active' => true,
        ]);

        expect($type->fundingReferences())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

// ---------------------------------------------------------------------------
// TitleType Model
// ---------------------------------------------------------------------------
describe('TitleType', function () {
    it('creates with fillable attributes', function () {
        $type = TitleType::factory()->create();

        expect($type->name)->toBeString()
            ->and($type->slug)->toBeString();
    });

    it('casts boolean fields correctly', function () {
        $type = TitleType::factory()->create(['is_active' => false, 'is_elmo_active' => true]);

        expect($type->is_active)->toBeFalse()
            ->and($type->is_elmo_active)->toBeTrue();
    });

    it('filters active types via scope', function () {
        TitleType::factory()->create(['is_active' => true, 'slug' => 'ActiveTT-' . uniqid()]);
        TitleType::factory()->create(['is_active' => false, 'slug' => 'InactiveTT-' . uniqid()]);

        $active = TitleType::active()->get();

        expect($active->every(fn ($t) => $t->is_active === true))->toBeTrue();
    });

    it('filters elmo active types via scope', function () {
        TitleType::factory()->create(['is_elmo_active' => true, 'slug' => 'ElmoTT-' . uniqid()]);
        TitleType::factory()->create(['is_elmo_active' => false, 'slug' => 'NoElmoTT-' . uniqid()]);

        $elmo = TitleType::elmoActive()->get();

        expect($elmo->every(fn ($t) => $t->is_elmo_active === true))->toBeTrue();
    });

    it('orders by name via scope', function () {
        TitleType::factory()->create(['name' => 'Zebra', 'slug' => 'zebra-tt-' . uniqid()]);
        TitleType::factory()->create(['name' => 'Alpha', 'slug' => 'alpha-tt-' . uniqid()]);

        $ordered = TitleType::orderByName()->pluck('name')->toArray();
        $sorted = $ordered;
        sort($sorted);

        expect($ordered)->toBe($sorted);
    });
});

// ---------------------------------------------------------------------------
// IgsnClassification Model
// ---------------------------------------------------------------------------
describe('IgsnClassification', function () {
    it('creates with fillable attributes', function () {
        $resource = Resource::factory()->create();
        $classification = IgsnClassification::create([
            'resource_id' => $resource->id,
            'value' => 'Igneous',
            'position' => 1,
        ]);

        expect($classification->value)->toBe('Igneous')
            ->and($classification->position)->toBe(1);
    });

    it('casts position to integer', function () {
        $resource = Resource::factory()->create();
        $classification = IgsnClassification::create([
            'resource_id' => $resource->id,
            'value' => 'Sedimentary',
            'position' => 2,
        ]);

        expect($classification->position)->toBeInt();
    });

    it('belongs to a resource', function () {
        $resource = Resource::factory()->create();
        $classification = IgsnClassification::create([
            'resource_id' => $resource->id,
            'value' => 'Metamorphic',
            'position' => 0,
        ]);

        expect($classification->resource)->toBeInstanceOf(Resource::class)
            ->and($classification->resource->id)->toBe($resource->id);
    });
});

// ---------------------------------------------------------------------------
// IgsnGeologicalAge Model
// ---------------------------------------------------------------------------
describe('IgsnGeologicalAge', function () {
    it('creates with fillable attributes', function () {
        $resource = Resource::factory()->create();
        $age = IgsnGeologicalAge::create([
            'resource_id' => $resource->id,
            'value' => 'Jurassic',
            'position' => 0,
        ]);

        expect($age->value)->toBe('Jurassic')
            ->and($age->position)->toBe(0);
    });

    it('casts position to integer', function () {
        $resource = Resource::factory()->create();
        $age = IgsnGeologicalAge::create([
            'resource_id' => $resource->id,
            'value' => 'Cretaceous',
            'position' => 1,
        ]);

        expect($age->position)->toBeInt();
    });

    it('belongs to a resource', function () {
        $resource = Resource::factory()->create();
        $age = IgsnGeologicalAge::create([
            'resource_id' => $resource->id,
            'value' => 'Quaternary',
            'position' => 0,
        ]);

        expect($age->resource)->toBeInstanceOf(Resource::class)
            ->and($age->resource->id)->toBe($resource->id);
    });
});

// ---------------------------------------------------------------------------
// IgsnGeologicalUnit Model
// ---------------------------------------------------------------------------
describe('IgsnGeologicalUnit', function () {
    it('creates with fillable attributes', function () {
        $resource = Resource::factory()->create();
        $unit = IgsnGeologicalUnit::create([
            'resource_id' => $resource->id,
            'value' => 'Permian',
            'position' => 0,
        ]);

        expect($unit->value)->toBe('Permian')
            ->and($unit->position)->toBe(0);
    });

    it('casts position to integer', function () {
        $resource = Resource::factory()->create();
        $unit = IgsnGeologicalUnit::create([
            'resource_id' => $resource->id,
            'value' => 'Triassic',
            'position' => 1,
        ]);

        expect($unit->position)->toBeInt();
    });

    it('belongs to a resource', function () {
        $resource = Resource::factory()->create();
        $unit = IgsnGeologicalUnit::create([
            'resource_id' => $resource->id,
            'value' => 'Carboniferous',
            'position' => 0,
        ]);

        expect($unit->resource)->toBeInstanceOf(Resource::class)
            ->and($unit->resource->id)->toBe($resource->id);
    });
});
