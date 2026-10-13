<?php

declare(strict_types=1);

use App\Services\SizeFormat\SizeFormatDownloadTargetResolverService;

covers(SizeFormatDownloadTargetResolverService::class);

it('returns a curl resolve entry for the exact public address it validated', function (): void {
    $resolver = new SizeFormatDownloadTargetResolverService(
        fn (string $host): array => $host === 'downloads.example.test'
            ? ['2001:4860:4860::8888', '93.184.216.34']
            : [],
    );

    expect($resolver->resolve('https://downloads.example.test/archive.zip'))->toBe([
        'host' => 'downloads.example.test',
        'port' => 443,
        'address' => '93.184.216.34',
        'curl_resolve' => 'downloads.example.test:443:93.184.216.34',
    ]);
});

it('rejects a hostname when any resolved address is private', function (): void {
    $resolver = new SizeFormatDownloadTargetResolverService(
        fn (string $host): array => ['93.184.216.34', '127.0.0.1'],
    );

    expect($resolver->resolve('https://downloads.example.test/archive.zip'))->toBeNull();
});

it('rejects private literal addresses and credential-bearing URLs', function (): void {
    $resolver = new SizeFormatDownloadTargetResolverService;

    expect($resolver->resolve('http://10.0.0.1/data.csv'))->toBeNull()
        ->and($resolver->resolve('https://user:secret@93.184.216.34/data.csv'))->toBeNull();
});

it('preserves a trailing DNS dot in the curl pin while normalizing the lookup host', function (): void {
    $resolver = new SizeFormatDownloadTargetResolverService(
        fn (string $host): array => $host === 'downloads.example.test' ? ['93.184.216.34'] : [],
    );

    expect($resolver->resolve('https://downloads.example.test./data.csv'))->toMatchArray([
        'host' => 'downloads.example.test',
        'curl_resolve' => 'downloads.example.test.:443:93.184.216.34',
    ]);
});
