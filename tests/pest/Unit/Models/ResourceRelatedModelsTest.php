<?php

declare(strict_types=1);

use App\Models\AlternateIdentifier;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\Right;
use App\Models\Subject;

covers(
    AlternateIdentifier::class,
    RelatedIdentifier::class,
    Right::class,
    Subject::class,
);

describe('AlternateIdentifier model', function () {
    it('has correct fillable attributes', function () {
        $model = new AlternateIdentifier;

        expect($model->getFillable())->toContain('resource_id', 'value', 'type', 'position');
    });

    it('casts position to integer', function () {
        $model = new AlternateIdentifier;

        expect($model->getCasts())->toHaveKey('position');
    });

    it('has resource relationship', function () {
        $model = new AlternateIdentifier;

        expect($model->resource())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

describe('RelatedIdentifier model', function () {
    it('has correct fillable attributes', function () {
        $model = new RelatedIdentifier;

        expect($model->getFillable())->toContain(
            'resource_id',
            'identifier',
            'identifier_type_id',
            'relation_type_id',
            'position',
        );
    });

    it('has bidirectional pairs constant', function () {
        expect(RelatedIdentifier::BIDIRECTIONAL_PAIRS)->toBeArray();
        expect(RelatedIdentifier::BIDIRECTIONAL_PAIRS)->toHaveKey('Cites');
        expect(RelatedIdentifier::BIDIRECTIONAL_PAIRS['Cites'])->toBe('IsCitedBy');
    });

    it('gets opposite relation type for Cites', function () {
        expect(RelatedIdentifier::getOppositeRelationType('Cites'))->toBe('IsCitedBy');
    });

    it('gets opposite relation type for IsCitedBy', function () {
        expect(RelatedIdentifier::getOppositeRelationType('IsCitedBy'))->toBe('Cites');
    });

    it('gets opposite for IsPartOf', function () {
        expect(RelatedIdentifier::getOppositeRelationType('IsPartOf'))->toBe('HasPart');
    });

    it('returns null for unknown relation type', function () {
        expect(RelatedIdentifier::getOppositeRelationType('UnknownType'))->toBeNull();
    });

    it('maps Other to Other', function () {
        expect(RelatedIdentifier::getOppositeRelationType('Other'))->toBe('Other');
    });

    it('has resource relationship', function () {
        $model = new RelatedIdentifier;

        expect($model->resource())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('has identifierType relationship', function () {
        $model = new RelatedIdentifier;

        expect($model->identifierType())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('has relationType relationship', function () {
        $model = new RelatedIdentifier;

        expect($model->relationType())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('covers all bidirectional pairs symmetrically', function () {
        foreach (RelatedIdentifier::BIDIRECTIONAL_PAIRS as $type => $opposite) {
            expect(RelatedIdentifier::BIDIRECTIONAL_PAIRS[$opposite])->toBe($type);
        }
    });
});

describe('Right model', function () {
    it('has correct fillable attributes', function () {
        $model = new Right;

        expect($model->getFillable())->toContain('identifier', 'name', 'uri', 'is_active');
    });

    it('casts is_active to boolean', function () {
        $right = Right::factory()->create(['is_active' => 1]);

        expect($right->is_active)->toBeBool();
    });

    it('casts usage_count to integer', function () {
        $right = Right::factory()->create(['usage_count' => '5']);

        expect($right->usage_count)->toBeInt();
    });

    it('filters active rights via scope', function () {
        Right::factory()->create(['identifier' => 'CC-BY-4.0', 'is_active' => true]);
        Right::factory()->create(['identifier' => 'CC0-1.0', 'is_active' => false]);

        $active = Right::active()->pluck('identifier');

        expect($active)->toContain('CC-BY-4.0');
        expect($active)->not->toContain('CC0-1.0');
    });

    it('orders by usage count descending', function () {
        Right::factory()->create(['identifier' => 'low', 'usage_count' => 1]);
        Right::factory()->create(['identifier' => 'high', 'usage_count' => 100]);

        $ordered = Right::orderByUsageCount()->get();

        expect($ordered->first()->identifier)->toBe('high');
    });

    it('has resources relationship', function () {
        $right = Right::factory()->create();

        expect($right->resources())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
    });
});

describe('Subject model', function () {
    it('has correct fillable attributes', function () {
        $model = new Subject;

        expect($model->getFillable())->toContain('value');
    });

    it('has resource relationship', function () {
        $model = new Subject;

        expect($model->resource())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});
