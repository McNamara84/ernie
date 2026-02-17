<?php

use App\Models\ResourceDate;

describe('ResourceDate', function () {
    describe('isRange()', function () {
        it('returns true when both start_date and end_date are set', function () {
            $date = new ResourceDate([
                'start_date' => '2020-01-01',
                'end_date' => '2020-12-31',
            ]);

            expect($date->isRange())->toBeTrue();
        });

        it('returns false when only start_date is set (open-ended range)', function () {
            $date = new ResourceDate([
                'start_date' => '2020-01-01',
                'end_date' => null,
            ]);

            expect($date->isRange())->toBeFalse();
        });

        it('returns false when only end_date is set', function () {
            $date = new ResourceDate([
                'start_date' => null,
                'end_date' => '2020-12-31',
            ]);

            expect($date->isRange())->toBeFalse();
        });

        it('returns false when neither date is set', function () {
            $date = new ResourceDate([
                'start_date' => null,
                'end_date' => null,
            ]);

            expect($date->isRange())->toBeFalse();
        });
    });

    describe('isOpenEndedRange()', function () {
        it('returns true when only start_date is set', function () {
            $date = new ResourceDate([
                'start_date' => '2020-01-01',
                'end_date' => null,
            ]);

            expect($date->isOpenEndedRange())->toBeTrue();
        });

        it('returns false when both dates are set', function () {
            $date = new ResourceDate([
                'start_date' => '2020-01-01',
                'end_date' => '2020-12-31',
            ]);

            expect($date->isOpenEndedRange())->toBeFalse();
        });

        it('returns false when only end_date is set', function () {
            $date = new ResourceDate([
                'start_date' => null,
                'end_date' => '2020-12-31',
            ]);

            expect($date->isOpenEndedRange())->toBeFalse();
        });

        it('returns false when neither date is set', function () {
            $date = new ResourceDate([
                'start_date' => null,
                'end_date' => null,
            ]);

            expect($date->isOpenEndedRange())->toBeFalse();
        });
    });

    describe('hasRangeStart()', function () {
        it('returns true when start_date is set', function () {
            $date = new ResourceDate([
                'start_date' => '2020-01-01',
                'end_date' => null,
            ]);

            expect($date->hasRangeStart())->toBeTrue();
        });

        it('returns true when both dates are set', function () {
            $date = new ResourceDate([
                'start_date' => '2020-01-01',
                'end_date' => '2020-12-31',
            ]);

            expect($date->hasRangeStart())->toBeTrue();
        });

        it('returns false when start_date is not set', function () {
            $date = new ResourceDate([
                'start_date' => null,
                'end_date' => '2020-12-31',
            ]);

            expect($date->hasRangeStart())->toBeFalse();
        });

        it('returns false when neither date is set', function () {
            $date = new ResourceDate([
                'start_date' => null,
                'end_date' => null,
            ]);

            expect($date->hasRangeStart())->toBeFalse();
        });
    });
});
