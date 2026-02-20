<?php

declare(strict_types=1);

use App\Models\Affiliation;

covers(Affiliation::class);

describe('fillable', function () {
    it('has correct fillable fields', function () {
        $model = new Affiliation;

        expect($model->getFillable())->toBe([
            'affiliatable_type',
            'affiliatable_id',
            'name',
            'identifier',
            'identifier_scheme',
            'scheme_uri',
        ]);
    });
});

describe('relationships', function () {
    it('defines affiliatable morphTo relationship', function () {
        $model = new Affiliation;

        expect($model->affiliatable())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class);
    });
});

describe('hasRor', function () {
    it('returns true when identifier_scheme is ROR and identifier is set', function () {
        $model = new Affiliation([
            'identifier_scheme' => 'ROR',
            'identifier' => 'https://ror.org/04z8jg394',
        ]);

        expect($model->hasRor())->toBeTrue();
    });

    it('returns false when identifier_scheme is not ROR', function () {
        $model = new Affiliation([
            'identifier_scheme' => 'ISNI',
            'identifier' => '0000000121239035',
        ]);

        expect($model->hasRor())->toBeFalse();
    });

    it('returns false when identifier is null', function () {
        $model = new Affiliation([
            'identifier_scheme' => 'ROR',
            'identifier' => null,
        ]);

        expect($model->hasRor())->toBeFalse();
    });
});

describe('getRorIdAttribute', function () {
    it('returns identifier when hasRor is true', function () {
        $model = new Affiliation([
            'identifier_scheme' => 'ROR',
            'identifier' => 'https://ror.org/04z8jg394',
        ]);

        expect($model->ror_id)->toBe('https://ror.org/04z8jg394');
    });

    it('returns null when hasRor is false', function () {
        $model = new Affiliation([
            'identifier_scheme' => 'ISNI',
            'identifier' => '0000000121239035',
        ]);

        expect($model->ror_id)->toBeNull();
    });
});
