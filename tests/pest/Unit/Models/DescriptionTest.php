<?php

declare(strict_types=1);

use App\Models\Description;

covers(Description::class);

describe('fillable', function () {
    it('has correct fillable fields', function () {
        $model = new Description;

        expect($model->getFillable())->toBe([
            'resource_id',
            'value',
            'description_type_id',
            'language',
        ]);
    });
});

describe('relationships', function () {
    it('defines resource relationship', function () {
        $model = new Description;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('defines descriptionType relationship', function () {
        $model = new Description;

        expect($model->descriptionType())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});
