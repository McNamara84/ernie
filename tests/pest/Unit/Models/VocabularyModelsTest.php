<?php

declare(strict_types=1);

use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\FunderIdentifierType;
use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Models\ResourceType;
use App\Models\TitleType;

covers(
    DateType::class,
    DescriptionType::class,
    ContributorType::class,
    ResourceType::class,
    TitleType::class,
    RelationType::class,
    IdentifierType::class,
    FunderIdentifierType::class,
);

describe('DateType model', function () {
    it('has correct fillable attributes', function () {
        $model = new DateType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active');
    });

    it('casts is_active to boolean', function () {
        $model = DateType::factory()->create(['is_active' => 1]);

        expect($model->is_active)->toBeBool();
    });

    it('filters active types via scope', function () {
        DateType::factory()->create(['is_active' => true, 'slug' => 'Created_active']);
        DateType::factory()->create(['is_active' => false, 'slug' => 'Collected_inactive']);

        $active = DateType::active()->get();

        expect($active->pluck('slug'))->toContain('Created_active');
        expect($active->pluck('slug'))->not->toContain('Collected_inactive');
    });

    it('orders by name via scope', function () {
        DateType::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
        DateType::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $ordered = DateType::orderByName()->get();

        expect($ordered->first()->name)->toBe('Alpha');
    });

    it('has dates relationship', function () {
        $type = DateType::factory()->create();

        expect($type->dates())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

describe('DescriptionType model', function () {
    it('has correct fillable attributes', function () {
        $model = new DescriptionType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active', 'is_elmo_active');
    });

    it('filters active types via scope', function () {
        DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true]);
        DescriptionType::create(['name' => 'Methods', 'slug' => 'Methods', 'is_active' => false]);

        $active = DescriptionType::active()->pluck('slug');

        expect($active)->toContain('Abstract');
        expect($active)->not->toContain('Methods');
    });

    it('filters ELMO active types via scope', function () {
        DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract_elmo', 'is_active' => true, 'is_elmo_active' => true]);
        DescriptionType::create(['name' => 'Other', 'slug' => 'Other_elmo', 'is_active' => true, 'is_elmo_active' => false]);

        $elmoActive = DescriptionType::elmoActive()->pluck('slug');

        expect($elmoActive)->toContain('Abstract_elmo');
        expect($elmoActive)->not->toContain('Other_elmo');
    });

    it('has descriptions relationship', function () {
        $type = DescriptionType::create(['name' => 'Test', 'slug' => 'test_desc']);

        expect($type->descriptions())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

describe('ResourceType model', function () {
    it('has correct fillable attributes', function () {
        $model = new ResourceType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active', 'is_elmo_active');
    });

    it('filters active types via scope', function () {
        ResourceType::firstOrCreate(
            ['slug' => 'Dataset_active'],
            ['name' => 'Dataset', 'is_active' => true]
        );
        ResourceType::firstOrCreate(
            ['slug' => 'Software_inactive'],
            ['name' => 'Software', 'is_active' => false]
        );

        $active = ResourceType::active()->pluck('slug');

        expect($active)->toContain('Dataset_active');
        expect($active)->not->toContain('Software_inactive');
    });

    it('has resources relationship', function () {
        $type = ResourceType::firstOrCreate(
            ['slug' => 'test_rt'],
            ['name' => 'Test', 'is_active' => true]
        );

        expect($type->resources())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has excludedFromRights relationship', function () {
        $type = ResourceType::firstOrCreate(
            ['slug' => 'test_rt_rights'],
            ['name' => 'Test Rights', 'is_active' => true]
        );

        expect($type->excludedFromRights())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
    });
});

describe('TitleType model', function () {
    it('has correct fillable attributes', function () {
        $model = new TitleType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active');
    });

    it('filters active types via scope', function () {
        TitleType::factory()->create(['slug' => 'main_active', 'is_active' => true]);
        TitleType::factory()->create(['slug' => 'sub_inactive', 'is_active' => false]);

        $active = TitleType::active()->pluck('slug');

        expect($active)->toContain('main_active');
        expect($active)->not->toContain('sub_inactive');
    });
});

describe('RelationType model', function () {
    it('has correct fillable attributes', function () {
        $model = new RelationType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active');
    });

    it('filters active types via scope', function () {
        RelationType::create(['name' => 'Cites', 'slug' => 'Cites_active', 'is_active' => true]);
        RelationType::create(['name' => 'References', 'slug' => 'References_inactive', 'is_active' => false]);

        $active = RelationType::active()->pluck('slug');

        expect($active)->toContain('Cites_active');
        expect($active)->not->toContain('References_inactive');
    });

    it('has relatedIdentifiers relationship', function () {
        $type = RelationType::create(['name' => 'Test', 'slug' => 'test_rel']);

        expect($type->relatedIdentifiers())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

describe('IdentifierType model', function () {
    it('has correct fillable attributes', function () {
        $model = new IdentifierType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active');
    });

    it('filters active types via scope', function () {
        IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI_active', 'is_active' => true]);
        IdentifierType::create(['name' => 'URL', 'slug' => 'URL_inactive', 'is_active' => false]);

        $active = IdentifierType::active()->pluck('slug');

        expect($active)->toContain('DOI_active');
        expect($active)->not->toContain('URL_inactive');
    });

    it('has patterns relationship', function () {
        $type = IdentifierType::create(['name' => 'Test', 'slug' => 'test_id']);

        expect($type->patterns())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has relatedIdentifiers relationship', function () {
        $type = IdentifierType::create(['name' => 'Test2', 'slug' => 'test_id2']);

        expect($type->relatedIdentifiers())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

describe('FunderIdentifierType model', function () {
    it('has correct fillable attributes', function () {
        $model = new FunderIdentifierType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active');
    });

    it('filters active types via scope', function () {
        FunderIdentifierType::create(['name' => 'Crossref', 'slug' => 'Crossref_active', 'is_active' => true]);
        FunderIdentifierType::create(['name' => 'GRID', 'slug' => 'GRID_inactive', 'is_active' => false]);

        $active = FunderIdentifierType::active()->pluck('slug');

        expect($active)->toContain('Crossref_active');
        expect($active)->not->toContain('GRID_inactive');
    });

    it('has fundingReferences relationship', function () {
        $type = FunderIdentifierType::create(['name' => 'Test', 'slug' => 'test_fit']);

        expect($type->fundingReferences())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

describe('ContributorType model', function () {
    it('has correct fillable attributes', function () {
        $model = new ContributorType;

        expect($model->getFillable())->toContain('name', 'slug', 'is_active');
    });

    it('has contributors relationship', function () {
        $type = ContributorType::firstOrCreate(
            ['slug' => 'test_ct'],
            ['name' => 'Test Contributor Type']
        );

        expect($type->contributors())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});
