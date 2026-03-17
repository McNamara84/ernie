<?php

declare(strict_types=1);

use App\Models\FundingReference;

covers(FundingReference::class);

describe('fillable', function () {
    it('has correct fillable fields', function () {
        $model = new FundingReference;

        expect($model->getFillable())->toBe([
            'resource_id',
            'funder_name',
            'funder_identifier',
            'funder_identifier_type_id',
            'scheme_uri',
            'award_number',
            'award_uri',
            'award_title',
        ]);
    });
});

describe('relationships', function () {
    it('defines resource relationship', function () {
        $model = new FundingReference;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('defines funderIdentifierType relationship', function () {
        $model = new FundingReference;

        expect($model->funderIdentifierType())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

describe('hasFunderIdentifier', function () {
    it('returns true when funder_identifier is set', function () {
        $model = new FundingReference(['funder_identifier' => 'https://doi.org/10.13039/501100001659']);

        expect($model->hasFunderIdentifier())->toBeTrue();
    });

    it('returns false when funder_identifier is null', function () {
        $model = new FundingReference(['funder_identifier' => null]);

        expect($model->hasFunderIdentifier())->toBeFalse();
    });
});

describe('hasAward', function () {
    it('returns true when award_number is set', function () {
        $model = new FundingReference(['award_number' => 'ABC-123']);

        expect($model->hasAward())->toBeTrue();
    });

    it('returns true when award_title is set', function () {
        $model = new FundingReference(['award_title' => 'Research Grant']);

        expect($model->hasAward())->toBeTrue();
    });

    it('returns true when both are set', function () {
        $model = new FundingReference([
            'award_number' => 'ABC-123',
            'award_title' => 'Research Grant',
        ]);

        expect($model->hasAward())->toBeTrue();
    });

    it('returns false when neither is set', function () {
        $model = new FundingReference([
            'award_number' => null,
            'award_title' => null,
        ]);

        expect($model->hasAward())->toBeFalse();
    });
});
