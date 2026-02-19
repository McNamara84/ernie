<?php

declare(strict_types=1);

use App\Services\DataCiteSyncResult;

covers(DataCiteSyncResult::class);

describe('DataCiteSyncResult factory methods', function (): void {
    it('creates a not-required result', function (): void {
        $result = DataCiteSyncResult::notRequired();

        expect($result)
            ->attempted->toBeFalse()
            ->success->toBeTrue()
            ->errorMessage->toBeNull()
            ->doi->toBeNull();
    });

    it('creates a succeeded result with DOI', function (): void {
        $doi = '10.5880/GFZ.2024.001';
        $result = DataCiteSyncResult::succeeded($doi);

        expect($result)
            ->attempted->toBeTrue()
            ->success->toBeTrue()
            ->errorMessage->toBeNull()
            ->doi->toBe($doi);
    });

    it('creates a failed result with DOI and error message', function (): void {
        $doi = '10.5880/GFZ.2024.002';
        $message = 'DataCite API timeout';
        $result = DataCiteSyncResult::failed($doi, $message);

        expect($result)
            ->attempted->toBeTrue()
            ->success->toBeFalse()
            ->errorMessage->toBe($message)
            ->doi->toBe($doi);
    });
});

describe('DataCiteSyncResult::hasFailed()', function (): void {
    it('returns false when not attempted', function (): void {
        $result = DataCiteSyncResult::notRequired();

        expect($result->hasFailed())->toBeFalse();
    });

    it('returns false when succeeded', function (): void {
        $result = DataCiteSyncResult::succeeded('10.5880/GFZ.2024.001');

        expect($result->hasFailed())->toBeFalse();
    });

    it('returns true when attempted and failed', function (): void {
        $result = DataCiteSyncResult::failed('10.5880/GFZ.2024.001', 'API error');

        expect($result->hasFailed())->toBeTrue();
    });
});

describe('DataCiteSyncResult::toArray()', function (): void {
    it('serializes not-required result', function (): void {
        $result = DataCiteSyncResult::notRequired();

        expect($result->toArray())->toBe([
            'attempted' => false,
            'success' => true,
            'errorMessage' => null,
            'doi' => null,
        ]);
    });

    it('serializes succeeded result', function (): void {
        $doi = '10.5880/GFZ.2024.001';
        $result = DataCiteSyncResult::succeeded($doi);

        expect($result->toArray())->toBe([
            'attempted' => true,
            'success' => true,
            'errorMessage' => null,
            'doi' => $doi,
        ]);
    });

    it('serializes failed result', function (): void {
        $doi = '10.5880/GFZ.2024.003';
        $message = 'Connection refused';
        $result = DataCiteSyncResult::failed($doi, $message);

        expect($result->toArray())->toBe([
            'attempted' => true,
            'success' => false,
            'errorMessage' => $message,
            'doi' => $doi,
        ]);
    });
});

describe('DataCiteSyncResult immutability', function (): void {
    it('is a readonly class', function (): void {
        $reflection = new ReflectionClass(DataCiteSyncResult::class);

        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('is a final class', function (): void {
        $reflection = new ReflectionClass(DataCiteSyncResult::class);

        expect($reflection->isFinal())->toBeTrue();
    });
});
