<?php

declare(strict_types=1);

use App\Support\Swhid;

covers(Swhid::class);

$fixtures = json_decode(file_get_contents(__DIR__.'/../../../fixtures/identifiers/swhid-cases.json'), true, flags: JSON_THROW_ON_ERROR);

it('implements the shared SWHID contract', function (array $case): void {
    expect(Swhid::isCandidate($case['input']))->toBe($case['candidate'])
        ->and(Swhid::normalize($case['input']))->toBe($case['normalized'])
        ->and(Swhid::normalize($case['normalized']))->toBe($case['normalized']);

    $result = Swhid::validate($case['input']);
    expect($result['valid'])->toBe($case['valid'])
        ->and(Swhid::isValid($case['input']))->toBe($case['valid'])
        ->and($result['normalized'])->toBe($case['normalized'])
        ->and($result['errors'][0]['code'] ?? null)->toBe($case['error'])
        ->and(Swhid::parse($case['input']))->toBe($result['parsed'])
        ->and(Swhid::resolveUrl($case['input']))->toBe($case['valid'] ? Swhid::RESOLVER.$case['normalized'] : null);

    if ($case['valid']) {
        expect($result['parsed']['core'])->toBe(explode(';', $case['normalized'])[0])
            ->and($result['errors'])->toBe([]);
    } else {
        expect($result['parsed'])->toBeNull()
            ->and($result['errors'][0]['field'])->not->toBeEmpty()
            ->and($result['errors'][0]['message'])->not->toBeEmpty()
            ->and(Swhid::comparisonKey($case['input']))->toBeNull();
    }
})->with(array_combine(array_column($fixtures['cases'], 'name'), array_map(fn (array $case): array => [$case], $fixtures['cases'])));

it('compares normalized bytes and qualifier sets without changing stored order', function (string $first, string $second): void {
    expect(Swhid::comparisonKey($first))->not->toBeNull()
        ->and(Swhid::comparisonKey($first))->toBe(Swhid::comparisonKey($second));
})->with($fixtures['equivalent']);

it('preserves distinct contexts, fragments and case-sensitive paths', function (string $first, string $second): void {
    expect(Swhid::comparisonKey($first))->not->toBe(Swhid::comparisonKey($second));
})->with($fixtures['distinct']);
