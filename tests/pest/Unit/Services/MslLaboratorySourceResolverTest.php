<?php

declare(strict_types=1);

use App\Services\MslLaboratorySourceResolver;
use Illuminate\Support\Facades\Http;

covers(MslLaboratorySourceResolver::class);

beforeEach(function (): void {
    config()->set([
        'msl.github_api_base' => 'https://api.example.test',
        'msl.repository' => 'UtrechtUniversity/msl_vocabularies',
        'msl.ref' => 'main',
        'msl.laboratories_base_path' => 'vocabularies/labs',
        'msl.laboratories_filename' => 'laboratories.json',
        'msl.http_retries' => 1,
        'msl.http_retry_delay_ms' => 0,
    ]);
});

it('selects the highest stable numeric version and resolves its file metadata', function (): void {
    Http::fake([
        'api.example.test/repos/*/contents/vocabularies/labs?*' => Http::response([
            ['type' => 'dir', 'name' => '1.9'],
            ['type' => 'dir', 'name' => '1.10'],
            ['type' => 'dir', 'name' => '2.0-beta'],
            ['type' => 'dir', 'name' => 'latest'],
            ['type' => 'file', 'name' => 'laboratories.json'],
        ]),
        'api.example.test/repos/*/contents/vocabularies/labs/1.10/laboratories.json?*' => Http::response([
            'type' => 'file',
            'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'download_url' => 'https://raw.example.test/laboratories.json',
        ]),
    ]);

    $resolved = (new MslLaboratorySourceResolver)->resolveLatest();

    expect($resolved)
        ->toMatchArray([
            'version' => '1.10',
            'path' => 'vocabularies/labs/1.10/laboratories.json',
            'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'download_url' => 'https://raw.example.test/laboratories.json',
        ]);
});

it('rejects discovery responses without a stable version directory', function (): void {
    Http::fake([
        '*' => Http::response([
            ['type' => 'dir', 'name' => '1.2-beta'],
            ['type' => 'dir', 'name' => 'latest'],
            ['type' => 'file', 'name' => '1.3'],
        ]),
    ]);

    expect(fn () => (new MslLaboratorySourceResolver)->resolveLatest())
        ->toThrow(RuntimeException::class, 'No stable MSL laboratories version directory');
});

it('reports directory discovery HTTP errors distinctly', function (): void {
    Http::fake(['*' => Http::response([], 503)]);

    expect(fn () => (new MslLaboratorySourceResolver)->resolveLatest())
        ->toThrow(RuntimeException::class, 'laboratories version discovery: HTTP 503');
});

it('wraps directory discovery connection failures with operation context', function (): void {
    Http::fake(['*' => Http::failedConnection('discovery timed out')]);

    expect(fn () => (new MslLaboratorySourceResolver)->resolveLatest())
        ->toThrow(RuntimeException::class, 'Failed during laboratories version discovery: discovery timed out');
});

it('reports missing laboratories file metadata', function (): void {
    Http::fakeSequence()
        ->push([['type' => 'dir', 'name' => '1.1']])
        ->push(['type' => 'file', 'sha' => 'abc']);

    expect(fn () => (new MslLaboratorySourceResolver)->resolveLatest())
        ->toThrow(RuntimeException::class, 'file metadata for version 1.1 is incomplete');
});
