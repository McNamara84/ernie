<?php

declare(strict_types=1);

use App\Console\Commands\BackfillIgsnSampleImages;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\Igsn\IgsnSampleImageBackfillService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

covers(BackfillIgsnSampleImages::class, IgsnSampleImageBackfillService::class);

beforeEach(function (): void {
    Storage::fake('public');
    Config::set('igsn_images.disk', 'public');
    Config::set('datacite.production.igsn_prefix', '10.60510');
    Config::set('datacite.legacy_igsn_portal', [
        'proxy_url' => 'https://igsn-portal.example.test/proxy.php',
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 5,
        'retry_times' => 1,
        'retry_sleep_ms' => 0,
        'retry_jitter_ms' => 0,
        'page_size' => 100,
        'datacenter_cache_ttl_seconds' => 0,
    ]);
});

function issue1168BackfillResource(string $handle): Resource
{
    $resource = Resource::factory()->create(['doi' => '10.60510/'.strtolower($handle)]);
    IgsnMetadata::create(['resource_id' => $resource->id]);

    return $resource;
}

function issue1168BackfillJpeg(): string
{
    return (string) base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', true);
}

/** @param array<string, string|null> $documents */
function issue1168FakePortal(array $documents, bool $includeImage = true): void
{
    $fakes = [
        'igsn-portal.example.test/*' => Http::response([
            'response' => [
                'numFound' => count($documents),
                'docs' => collect($documents)->map(
                    static fn (?string $xml, string $handle): array => $xml === null
                        ? ['igsn' => $handle, 'has_dif' => false]
                        : ['igsn' => $handle, 'has_dif' => true, 'dif' => base64_encode($xml)],
                )->values()->all(),
            ],
        ]),
    ];
    if ($includeImage) {
        $fakes['dataservices.gfz-potsdam.de/*'] = Http::response(issue1168BackfillJpeg(), 200, ['Content-Type' => 'image/jpeg']);
        $fakes['data.icdp-online.org/*'] = Http::response(issue1168BackfillJpeg(), 206, ['Content-Type' => 'image/jpeg']);
    }
    Http::fake($fakes);
}

it('is dry-run first then stores managed GFZ images and links ICDP images idempotently', function (): void {
    $managed = issue1168BackfillResource('GFSO273N39');
    $external = issue1168BackfillResource('ICDP5054EXZX001');
    issue1168BackfillResource('ICDP5054PLACE01');
    issue1168FakePortal([
        'GFSO273N39' => '<resource><sample><sample_image>SO273.jpg</sample_image><sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path></sample></resource>',
        'ICDP5054EXZX001' => '<resource><sample><sample_image>CS_5054.jpg</sample_image><sample_image_path>http://www-icdp.icdp-online.org/sites/cosc/news/cores/</sample_image_path></sample></resource>',
        'ICDP5054PLACE01' => '<resource><sample><sample_image>NN</sample_image><sample_image_path>http://www-icdp.icdp-online.org/sites/cosc/news/cores/</sample_image_path></sample></resource>',
    ]);

    $dry = app(IgsnSampleImageBackfillService::class)->run();
    expect($dry)->toMatchArray([
        'scanned' => 3,
        'would_store' => 1,
        'would_link_external' => 1,
        'invalid_placeholder' => 1,
        'failed' => 0,
    ])->and($managed->igsnMetadata()->first()->sample_image_source_url)->toBeNull();

    $applied = app(IgsnSampleImageBackfillService::class)->run(apply: true);
    $managedMetadata = $managed->igsnMetadata()->first();
    expect($applied)->toMatchArray(['stored' => 1, 'linked_external' => 1, 'invalid_placeholder' => 1, 'failed' => 0])
        ->and($managedMetadata->sample_image_storage_path)->not->toBeNull()
        ->and($managedMetadata->sampleImageUrl())->toStartWith('/storage/igsn-sample-images/')
        ->and($external->igsnMetadata()->first()->sample_image_external_url)
        ->toBe('https://data.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg');
    Storage::disk('public')->assertExists((string) $managedMetadata->sample_image_storage_path);

    $second = app(IgsnSampleImageBackfillService::class)->run(apply: true);
    expect($second)->toMatchArray(['unchanged' => 2, 'invalid_placeholder' => 1, 'failed' => 0]);
});

it('isolates download failures and supports filters resume limit force and CSV reports', function (): void {
    $first = issue1168BackfillResource('GFSO273FAIL1');
    $second = issue1168BackfillResource('GFSO273FAIL2');
    issue1168FakePortal([
        'GFSO273FAIL1' => '<resource><sample><sample_image>one.jpg</sample_image><sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path></sample></resource>',
        'GFSO273FAIL2' => '<resource><sample><sample_image>two.jpg</sample_image><sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path></sample></resource>',
    ], includeImage: false);
    Http::fake([
        'igsn-portal.example.test/*' => Http::response([
            'response' => [
                'numFound' => 1,
                'docs' => [[
                    'igsn' => 'GFSO273FAIL2',
                    'has_dif' => true,
                    'dif' => base64_encode('<resource><sample><sample_image>two.jpg</sample_image><sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path></sample></resource>'),
                ]],
            ],
        ]),
        'dataservices.gfz-potsdam.de/*' => Http::response('', 503),
    ]);
    $report = storage_path('framework/testing/issue-1168-'.Str::uuid().'.csv');

    try {
        $this->artisan('igsn:backfill-images', [
            '--apply' => true,
            '--after-id' => $first->id,
            '--limit' => 1,
            '--chunk' => 500,
            '--doi' => ['GFSO273FAIL2'],
            '--force' => true,
            '--report' => $report,
        ])->expectsOutputToContain('IGSN sample image backfill applied.')
            ->assertExitCode(Command::FAILURE);

        expect($first->igsnMetadata()->first()->sample_image_source_url)->toBeNull()
            ->and($second->igsnMetadata()->first()->sample_image_source_url)->toBeNull()
            ->and(File::get($report))->toContain('resource_id,doi,handle,status,message')
            ->toContain('GFSO273FAIL2')
            ->toContain('failed');
    } finally {
        File::delete($report);
    }
});

it('restores the previous descriptor after a failed replacement so the next run retries it', function (): void {
    $resource = issue1168BackfillResource('GFSO273RETRY');
    $metadata = $resource->igsnMetadata()->firstOrFail();
    $oldPath = 'igsn-sample-images/gfso273retry/old.jpg';
    $oldSource = 'https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/old.jpg';
    $metadata->update([
        'sample_image_source_url' => $oldSource,
        'sample_image_storage_path' => $oldPath,
        'sample_image_mime_type' => 'image/jpeg',
        'sample_image_size' => strlen(issue1168BackfillJpeg()),
    ]);
    Storage::disk('public')->put($oldPath, issue1168BackfillJpeg());
    $dif = '<resource><sample><sample_image>replacement.jpg</sample_image><sample_image_path>https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/</sample_image_path></sample></resource>';

    $imageAttempts = 0;
    Http::fake(function (Request $request) use ($dif, &$imageAttempts) {
        if (str_contains($request->url(), 'igsn-portal.example.test')) {
            return Http::response([
                'response' => [
                    'numFound' => 1,
                    'docs' => [[
                        'igsn' => 'GFSO273RETRY',
                        'has_dif' => true,
                        'dif' => base64_encode($dif),
                    ]],
                ],
            ]);
        }

        $imageAttempts++;

        return $imageAttempts === 1
            ? Http::response('', 503)
            : Http::response(issue1168BackfillJpeg(), 200, ['Content-Type' => 'image/jpeg']);
    });

    $failed = app(IgsnSampleImageBackfillService::class)->run(apply: true, dois: ['GFSO273RETRY']);
    $metadata->refresh();

    expect($failed['failed'])->toBe(1)
        ->and($metadata->sample_image_source_url)->toBe($oldSource)
        ->and($metadata->sample_image_storage_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);

    $retried = app(IgsnSampleImageBackfillService::class)->run(apply: true, dois: ['GFSO273RETRY']);
    $metadata->refresh();

    expect($retried['stored'])->toBe(1)
        ->and($metadata->sample_image_source_url)->toContain('replacement.jpg')
        ->and($metadata->sample_image_storage_path)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists((string) $metadata->sample_image_storage_path);
});
