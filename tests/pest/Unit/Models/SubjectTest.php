<?php

declare(strict_types=1);

use App\Models\Subject;

covers(Subject::class);

describe('fillable', function () {
    it('has correct fillable fields', function () {
        $model = new Subject;

        expect($model->getFillable())->toBe([
            'resource_id',
            'value',
            'subject_scheme',
            'scheme_uri',
            'value_uri',
            'classification_code',
            'language_id',
        ]);
    });
});

describe('relationships', function () {
    it('defines resource relationship', function () {
        $model = new Subject;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('defines language relationship', function () {
        $model = new Subject;

        expect($model->language())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

describe('isControlled', function () {
    it('returns true when subject_scheme is set', function () {
        $model = new Subject(['subject_scheme' => 'GCMD Science Keywords']);

        expect($model->isControlled())->toBeTrue();
    });

    it('returns false when subject_scheme is null', function () {
        $model = new Subject(['subject_scheme' => null]);

        expect($model->isControlled())->toBeFalse();
    });
});

describe('isFreeText', function () {
    it('returns true when subject_scheme is null', function () {
        $model = new Subject(['subject_scheme' => null]);

        expect($model->isFreeText())->toBeTrue();
    });

    it('returns false when subject_scheme is set', function () {
        $model = new Subject(['subject_scheme' => 'GCMD Science Keywords']);

        expect($model->isFreeText())->toBeFalse();
    });
});

describe('isGcmd', function () {
    it('returns true for GCMD Science Keywords scheme', function () {
        $model = new Subject(['subject_scheme' => 'GCMD Science Keywords']);

        expect($model->isGcmd())->toBeTrue();
    });

    it('returns false for other schemes', function () {
        $model = new Subject(['subject_scheme' => 'MSL Vocabulary']);

        expect($model->isGcmd())->toBeFalse();
    });

    it('returns false when scheme is null', function () {
        $model = new Subject(['subject_scheme' => null]);

        expect($model->isGcmd())->toBeFalse();
    });
});

describe('isMsl', function () {
    it('returns true for MSL scheme', function () {
        $model = new Subject(['subject_scheme' => 'MSL Vocabulary']);

        expect($model->isMsl())->toBeTrue();
    });

    it('returns false for non-MSL scheme', function () {
        $model = new Subject(['subject_scheme' => 'GCMD Science Keywords']);

        expect($model->isMsl())->toBeFalse();
    });

    it('returns false when scheme is null', function () {
        $model = new Subject(['subject_scheme' => null]);

        expect($model->isMsl())->toBeFalse();
    });
});
