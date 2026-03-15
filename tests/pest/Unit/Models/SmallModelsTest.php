<?php

declare(strict_types=1);

use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\Format;
use App\Models\FunderIdentifierType;
use App\Models\IdentifierType;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\RelationType;

covers(
    AlternateIdentifier::class,
    ContributorType::class,
    DateType::class,
    DescriptionType::class,
    Format::class,
    FunderIdentifierType::class,
    IdentifierType::class,
    IgsnClassification::class,
    IgsnGeologicalAge::class,
    IgsnGeologicalUnit::class,
    RelationType::class,
);

// =========================================================================
// AlternateIdentifier
// =========================================================================

describe('AlternateIdentifier', function () {
    it('has correct fillable fields', function () {
        $model = new AlternateIdentifier;

        expect($model->getFillable())->toBe([
            'resource_id',
            'value',
            'type',
            'position',
        ]);
    });

    it('casts position to integer', function () {
        $model = new AlternateIdentifier(['position' => '3']);

        expect($model->position)->toBeInt();
    });

    it('defines resource relationship', function () {
        $model = new AlternateIdentifier;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

// =========================================================================
// ContributorType
// =========================================================================

describe('ContributorType', function () {
    it('has correct fillable fields', function () {
        $model = new ContributorType;

        expect($model->getFillable())->toBe(['name', 'slug', 'category', 'is_active', 'is_elmo_active']);
    });

    it('casts is_active to boolean', function () {
        $model = new ContributorType(['is_active' => 1]);

        expect($model->is_active)->toBeBool();
    });

    it('casts is_elmo_active to boolean', function () {
        $model = new ContributorType(['is_elmo_active' => 1]);

        expect($model->is_elmo_active)->toBeBool();
    });

    it('casts category to ContributorCategory enum', function () {
        $model = new ContributorType(['category' => 'person']);

        expect($model->category)->toBe(\App\Enums\ContributorCategory::PERSON);
    });

    it('defines contributors relationship', function () {
        $model = new ContributorType;

        expect($model->contributors())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has active scope', function () {
        $model = new ContributorType;
        $builder = $model->newQuery();

        expect($model->scopeActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    it('has elmoActive scope', function () {
        $model = new ContributorType;
        $builder = $model->newQuery();

        expect($model->scopeElmoActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    it('has forPersons scope', function () {
        $model = new ContributorType;
        $builder = $model->newQuery();

        expect($model->scopeForPersons($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    it('has forInstitutions scope', function () {
        $model = new ContributorType;
        $builder = $model->newQuery();

        expect($model->scopeForInstitutions($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });
});

// =========================================================================
// DescriptionType
// =========================================================================

describe('DescriptionType', function () {
    it('has correct fillable fields', function () {
        $model = new DescriptionType;

        expect($model->getFillable())->toBe(['name', 'slug', 'is_active', 'is_elmo_active']);
    });

    it('casts is_active to boolean', function () {
        $model = new DescriptionType(['is_active' => 1]);

        expect($model->is_active)->toBeBool();
    });

    it('casts is_elmo_active to boolean', function () {
        $model = new DescriptionType(['is_elmo_active' => 1]);

        expect($model->is_elmo_active)->toBeBool();
    });

    it('has elmoActive scope', function () {
        $model = new DescriptionType;
        $builder = $model->newQuery();

        expect($model->scopeElmoActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    it('defines descriptions relationship', function () {
        $model = new DescriptionType;

        expect($model->descriptions())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has active scope', function () {
        $model = new DescriptionType;
        $builder = $model->newQuery();

        expect($model->scopeActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });
});

// =========================================================================
// Format
// =========================================================================

describe('Format', function () {
    it('has correct fillable fields', function () {
        $model = new Format;

        expect($model->getFillable())->toBe(['resource_id', 'value']);
    });

    it('defines resource relationship', function () {
        $model = new Format;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

// =========================================================================
// FunderIdentifierType
// =========================================================================

describe('FunderIdentifierType', function () {
    it('has correct fillable fields', function () {
        $model = new FunderIdentifierType;

        expect($model->getFillable())->toBe(['name', 'slug', 'is_active']);
    });

    it('casts is_active to boolean', function () {
        $model = new FunderIdentifierType(['is_active' => 1]);

        expect($model->is_active)->toBeBool();
    });

    it('defines fundingReferences relationship', function () {
        $model = new FunderIdentifierType;

        expect($model->fundingReferences())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has active scope', function () {
        $model = new FunderIdentifierType;
        $builder = $model->newQuery();

        expect($model->scopeActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });
});

// =========================================================================
// IdentifierType
// =========================================================================

describe('IdentifierType', function () {
    it('has correct fillable fields', function () {
        $model = new IdentifierType;

        expect($model->getFillable())->toBe(['name', 'slug', 'is_active']);
    });

    it('casts is_active to boolean', function () {
        $model = new IdentifierType(['is_active' => 1]);

        expect($model->is_active)->toBeBool();
    });

    it('defines relatedIdentifiers relationship', function () {
        $model = new IdentifierType;

        expect($model->relatedIdentifiers())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has active scope', function () {
        $model = new IdentifierType;
        $builder = $model->newQuery();

        expect($model->scopeActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });
});

// =========================================================================
// RelationType
// =========================================================================

describe('RelationType', function () {
    it('has correct fillable fields', function () {
        $model = new RelationType;

        expect($model->getFillable())->toBe(['name', 'slug', 'is_active']);
    });

    it('casts is_active to boolean', function () {
        $model = new RelationType(['is_active' => 1]);

        expect($model->is_active)->toBeBool();
    });

    it('defines relatedIdentifiers relationship', function () {
        $model = new RelationType;

        expect($model->relatedIdentifiers())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has active scope', function () {
        $model = new RelationType;
        $builder = $model->newQuery();

        expect($model->scopeActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });
});

// =========================================================================
// DateType
// =========================================================================

describe('DateType', function () {
    it('has correct fillable fields', function () {
        $model = new DateType;

        expect($model->getFillable())->toBe(['name', 'slug', 'is_active']);
    });

    it('casts is_active to boolean', function () {
        $model = new DateType(['is_active' => 1]);

        expect($model->is_active)->toBeBool();
    });

    it('defines dates relationship', function () {
        $model = new DateType;

        expect($model->dates())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has active scope', function () {
        $model = new DateType;
        $builder = $model->newQuery();

        expect($model->scopeActive($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    it('has orderByName scope', function () {
        $model = new DateType;
        $builder = $model->newQuery();

        expect($model->scopeOrderByName($builder))->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });
});

// =========================================================================
// IGSN Models (similar structure)
// =========================================================================

describe('IgsnClassification', function () {
    it('has correct fillable fields', function () {
        $model = new IgsnClassification;

        expect($model->getFillable())->toBe(['resource_id', 'value', 'position']);
    });

    it('uses igsn_classifications table', function () {
        expect((new IgsnClassification)->getTable())->toBe('igsn_classifications');
    });

    it('casts position to integer', function () {
        $model = new IgsnClassification(['position' => '2']);

        expect($model->position)->toBeInt();
    });

    it('defines resource relationship', function () {
        $model = new IgsnClassification;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

describe('IgsnGeologicalAge', function () {
    it('has correct fillable fields', function () {
        $model = new IgsnGeologicalAge;

        expect($model->getFillable())->toBe(['resource_id', 'value', 'position']);
    });

    it('uses igsn_geological_ages table', function () {
        expect((new IgsnGeologicalAge)->getTable())->toBe('igsn_geological_ages');
    });

    it('casts position to integer', function () {
        $model = new IgsnGeologicalAge(['position' => '1']);

        expect($model->position)->toBeInt();
    });

    it('defines resource relationship', function () {
        $model = new IgsnGeologicalAge;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

describe('IgsnGeologicalUnit', function () {
    it('has correct fillable fields', function () {
        $model = new IgsnGeologicalUnit;

        expect($model->getFillable())->toBe(['resource_id', 'value', 'position']);
    });

    it('uses igsn_geological_units table', function () {
        expect((new IgsnGeologicalUnit)->getTable())->toBe('igsn_geological_units');
    });

    it('casts position to integer', function () {
        $model = new IgsnGeologicalUnit(['position' => '5']);

        expect($model->position)->toBeInt();
    });

    it('defines resource relationship', function () {
        $model = new IgsnGeologicalUnit;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});
