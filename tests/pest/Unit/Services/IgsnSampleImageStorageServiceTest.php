<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\Igsn\IgsnSampleImageStorageService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

covers(IgsnSampleImageStorageService::class);

beforeEach(function (): void {
    Storage::fake('public');
    Config::set('igsn_images.disk', 'public');
    Config::set('igsn_images.max_bytes', 1024 * 1024);
    Config::set('igsn_images.allowed_mime_types', ['image/jpeg' => 'jpg']);
});

function issue1168Metadata(string $sourceUrl): IgsnMetadata
{
    $resource = Resource::factory()->create(['doi' => '10.60510/gfso273n39']);

    return IgsnMetadata::create([
        'resource_id' => $resource->id,
        'sample_image_source_url' => $sourceUrl,
    ]);
}

function issue1168Jpeg(): string
{
    return (string) base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', true);
}

it('stores a validated managed JPEG at a deterministic path and is idempotent', function (): void {
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response(issue1168Jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/SO273.jpg');
    $service = app(IgsnSampleImageStorageService::class);

    $first = $service->sync($metadata);
    $metadata->refresh();

    expect($first['status'])->toBe('stored')
        ->and($metadata->sample_image_storage_path)->toStartWith('igsn-sample-images/gfso273n39/')
        ->and($metadata->sample_image_mime_type)->toBe('image/jpeg')
        ->and($metadata->sample_image_size)->toBe(strlen(issue1168Jpeg()))
        ->and($metadata->sample_image_external_url)->toBeNull();
    Storage::disk('public')->assertExists((string) $metadata->sample_image_storage_path);

    expect($service->sync($metadata)['status'])->toBe('unchanged');
    Http::assertSentCount(1);
});

it('publishes canonical ICDP URLs only after validating the external image', function (): void {
    Http::fake(['data.icdp-online.org/*' => Http::response(issue1168Jpeg(), 206, ['Content-Type' => 'image/jpeg'])]);
    $metadata = issue1168Metadata('http://www-icdp.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg');

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata)['status'])->toBe('external');
    $metadata->refresh();

    expect($metadata->sample_image_external_url)->toBe('https://data.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg')
        ->and($metadata->sample_image_storage_path)->toBeNull();
    Http::assertSentCount(1);
});

it('removes a definitively unavailable external URL while retaining its source descriptor', function (): void {
    Http::fake(['data.icdp-online.org/*' => Http::response('', 404)]);
    $metadata = issue1168Metadata('http://www-icdp.icdp-online.org/sites/lusklint/news/cores/missing.jpg');
    $metadata->update(['sample_image_external_url' => 'https://data.icdp-online.org/sites/lusklint/news/cores/missing.jpg']);

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata))->toBe([
        'status' => 'unavailable',
        'message' => 'http_404',
    ]);

    expect($metadata->refresh()->sample_image_source_url)
        ->toBe('http://www-icdp.icdp-online.org/sites/lusklint/news/cores/missing.jpg')
        ->and($metadata->sample_image_external_url)->toBeNull()
        ->and($metadata->sampleImageUrl())->toBeNull();
});

it('keeps a previously published URL when the external probe fails temporarily', function (): void {
    Http::fake(['data.icdp-online.org/*' => Http::response('', 503)]);
    $metadata = issue1168Metadata('http://www-icdp.icdp-online.org/sites/cosc/news/cores/temporary.jpg');
    $metadata->update(['sample_image_external_url' => 'https://data.icdp-online.org/sites/cosc/news/cores/temporary.jpg']);

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata))->toBe([
        'status' => 'failed',
        'message' => 'http_503',
    ]);

    expect($metadata->refresh()->sample_image_external_url)
        ->toBe('https://data.icdp-online.org/sites/cosc/news/cores/temporary.jpg');
});

it('rejects invalid content without publishing a broken image path', function (string $body, array $headers, int $maxBytes): void {
    Config::set('igsn_images.max_bytes', $maxBytes);
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response($body, 200, $headers)]);
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/invalid.jpg');

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata)['status'])->toBe('failed');
    $metadata->refresh();

    expect($metadata->sample_image_storage_path)->toBeNull()
        ->and($metadata->sampleImageUrl())->toBeNull();
})->with([
    'wrong MIME' => ['plain text', ['Content-Type' => 'text/plain'], 1024],
    'oversize' => [issue1168Jpeg(), ['Content-Type' => 'image/jpeg'], 10],
]);

it('rejects redirects with the explicit redirect error', function (): void {
    Http::fake([
        'dataservices.gfz-potsdam.de/*' => Http::response('', 302, [
            'Location' => 'https://example.org/redirected-image.jpg',
        ]),
    ]);
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/redirect.jpg');

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata))->toBe([
        'status' => 'failed',
        'message' => 'Sample image redirects are not allowed.',
    ]);
    Http::assertSentCount(1);
});

it('does not treat a stored path for another source as current and keeps it when replacement fails', function (): void {
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/replacement.jpg');
    $metadata->update([
        'sample_image_storage_path' => 'igsn-sample-images/gfso273n39/existing.jpg',
        'sample_image_mime_type' => 'image/jpeg',
        'sample_image_size' => 123,
    ]);
    Storage::disk('public')->put('igsn-sample-images/gfso273n39/existing.jpg', issue1168Jpeg());
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response('', 503)]);

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata)['status'])->toBe('failed');
    $metadata->refresh();

    expect($metadata->sample_image_storage_path)->toBe('igsn-sample-images/gfso273n39/existing.jpg');
    Storage::disk('public')->assertExists('igsn-sample-images/gfso273n39/existing.jpg');
});

it('keeps the old managed image and removes the replacement when the transaction rolls back', function (): void {
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/replacement.jpg');
    $oldPath = 'igsn-sample-images/gfso273n39/existing.jpg';
    $metadata->forceFill([
        'sample_image_storage_path' => $oldPath,
        'sample_image_mime_type' => 'image/jpeg',
        'sample_image_size' => strlen(issue1168Jpeg()),
    ])->save();
    Storage::disk('public')->put($oldPath, issue1168Jpeg());
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response(issue1168Jpeg(), 200)]);

    $replacementPath = null;
    $startingTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $result = app(IgsnSampleImageStorageService::class)->sync($metadata, true);
        $replacementPath = $metadata->sample_image_storage_path;

        expect($result['status'])->toBe('stored')
            ->and($replacementPath)->toBeString()->not->toBe($oldPath);
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertExists((string) $replacementPath);

        DB::rollBack();
    } finally {
        while (DB::transactionLevel() > $startingTransactionLevel) {
            DB::rollBack();
        }
    }

    expect($metadata->refresh()->sample_image_storage_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);
    Storage::disk('public')->assertMissing((string) $replacementPath);
});

it('deletes the old managed image only after the replacement transaction commits', function (): void {
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/replacement.jpg');
    $oldPath = 'igsn-sample-images/gfso273n39/existing.jpg';
    $metadata->forceFill([
        'sample_image_storage_path' => $oldPath,
        'sample_image_mime_type' => 'image/jpeg',
        'sample_image_size' => strlen(issue1168Jpeg()),
    ])->save();
    Storage::disk('public')->put($oldPath, issue1168Jpeg());
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response(issue1168Jpeg(), 200)]);

    $replacementPath = null;
    $startingTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $result = app(IgsnSampleImageStorageService::class)->sync($metadata, true);
        $replacementPath = $metadata->sample_image_storage_path;

        expect($result['status'])->toBe('stored');
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertExists((string) $replacementPath);

        DB::commit();
    } finally {
        while (DB::transactionLevel() > $startingTransactionLevel) {
            DB::rollBack();
        }
    }

    expect($metadata->refresh()->sample_image_storage_path)->toBe($replacementPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists((string) $replacementPath);
});

it('restores a managed image descriptor when switching to an external image rolls back', function (): void {
    Http::fake(['data.icdp-online.org/*' => Http::response(issue1168Jpeg(), 206, ['Content-Type' => 'image/jpeg'])]);
    $metadata = issue1168Metadata('http://www-icdp.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg');
    $oldPath = 'igsn-sample-images/gfso273n39/existing.jpg';
    $metadata->forceFill([
        'sample_image_storage_path' => $oldPath,
        'sample_image_mime_type' => 'image/jpeg',
        'sample_image_size' => strlen(issue1168Jpeg()),
    ])->save();
    Storage::disk('public')->put($oldPath, issue1168Jpeg());

    $startingTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $result = app(IgsnSampleImageStorageService::class)->sync($metadata);

        expect($result['status'])->toBe('external')
            ->and($metadata->sample_image_storage_path)->toBeNull();
        Storage::disk('public')->assertExists($oldPath);

        DB::rollBack();
    } finally {
        while (DB::transactionLevel() > $startingTransactionLevel) {
            DB::rollBack();
        }
    }

    expect($metadata->refresh()->sample_image_storage_path)->toBe($oldPath)
        ->and($metadata->sample_image_external_url)->toBeNull();
    Storage::disk('public')->assertExists($oldPath);
});

it('logs synchronization failures safely when the resource relationship is missing', function (): void {
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response('', 503)]);
    $log = Log::spy();

    $metadata = new IgsnMetadata;
    $metadata->forceFill([
        'resource_id' => 999999,
        'sample_image_source_url' => 'https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/missing.jpg',
    ]);

    $result = app(IgsnSampleImageStorageService::class)->sync($metadata);

    expect($result['status'])->toBe('failed');
    $log->shouldHaveReceived('warning')
        ->once()
        ->with(
            'IGSN sample image synchronization failed',
            Mockery::on(static fn (array $context): bool => $context['resource_id'] === 999999 && $context['doi'] === null),
        );
});
