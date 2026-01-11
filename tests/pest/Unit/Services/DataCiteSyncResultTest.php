<?php

use App\Services\DataCiteSyncResult;

describe('DataCiteSyncResult', function () {
    describe('factory methods', function () {
        test('notRequired creates result with attempted=false and success=true', function () {
            $result = DataCiteSyncResult::notRequired();

            expect($result->attempted)->toBeFalse();
            expect($result->success)->toBeTrue();
            expect($result->errorMessage)->toBeNull();
            expect($result->doi)->toBeNull();
        });

        test('succeeded creates result with attempted=true and success=true', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $result = DataCiteSyncResult::succeeded($doi);

            expect($result->attempted)->toBeTrue();
            expect($result->success)->toBeTrue();
            expect($result->errorMessage)->toBeNull();
            expect($result->doi)->toBe($doi);
        });

        test('failed creates result with attempted=true and success=false', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $errorMessage = 'DataCite API timeout';
            $result = DataCiteSyncResult::failed($doi, $errorMessage);

            expect($result->attempted)->toBeTrue();
            expect($result->success)->toBeFalse();
            expect($result->errorMessage)->toBe($errorMessage);
            expect($result->doi)->toBe($doi);
        });
    });

    describe('hasFailed', function () {
        test('returns true when attempted and not successful', function () {
            $result = DataCiteSyncResult::failed('10.5880/test', 'Error');

            expect($result->hasFailed())->toBeTrue();
        });

        test('returns false when not attempted', function () {
            $result = DataCiteSyncResult::notRequired();

            expect($result->hasFailed())->toBeFalse();
        });

        test('returns false when attempted and successful', function () {
            $result = DataCiteSyncResult::succeeded('10.5880/test');

            expect($result->hasFailed())->toBeFalse();
        });
    });

    describe('toArray', function () {
        test('converts notRequired result to array', function () {
            $result = DataCiteSyncResult::notRequired();

            expect($result->toArray())->toBe([
                'attempted' => false,
                'success' => true,
                'errorMessage' => null,
                'doi' => null,
            ]);
        });

        test('converts succeeded result to array', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $result = DataCiteSyncResult::succeeded($doi);

            expect($result->toArray())->toBe([
                'attempted' => true,
                'success' => true,
                'errorMessage' => null,
                'doi' => $doi,
            ]);
        });

        test('converts failed result to array', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $errorMessage = 'Connection timeout';
            $result = DataCiteSyncResult::failed($doi, $errorMessage);

            expect($result->toArray())->toBe([
                'attempted' => true,
                'success' => false,
                'errorMessage' => $errorMessage,
                'doi' => $doi,
            ]);
        });
    });

    describe('immutability', function () {
        test('result is readonly and cannot be modified', function () {
            $result = DataCiteSyncResult::succeeded('10.5880/test');

            // This test verifies the readonly class constraint
            // Attempting to set properties would cause a compile error
            $reflection = new ReflectionClass($result);
            expect($reflection->isReadOnly())->toBeTrue();
        });
    });
});
