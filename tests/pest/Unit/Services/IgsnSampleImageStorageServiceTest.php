<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\Igsn\IgsnSampleImageStorageService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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

it('stores canonical ICDP URLs without downloading the external image', function (): void {
    Http::preventStrayRequests();
    $metadata = issue1168Metadata('http://www-icdp.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg');

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata)['status'])->toBe('external')
        ->and($metadata->fresh()->sample_image_external_url)->toBe('https://data.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg')
        ->and($metadata->fresh()->sample_image_storage_path)->toBeNull();
    Http::assertNothingSent();
});

it('rejects invalid content without publishing a broken image path', function (string $body, array $headers, int $maxBytes): void {
    Config::set('igsn_images.max_bytes', $maxBytes);
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response($body, 200, $headers)]);
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/invalid.jpg');

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata)['status'])->toBe('failed')
        ->and($metadata->fresh()->sample_image_storage_path)->toBeNull()
        ->and($metadata->fresh()->sampleImageUrl())->toBeNull();
})->with([
    'wrong MIME' => ['plain text', ['Content-Type' => 'text/plain'], 1024],
    'oversize' => [issue1168Jpeg(), ['Content-Type' => 'image/jpeg'], 10],
]);

it('does not treat a stored path for another source as current and keeps it when replacement fails', function (): void {
    $metadata = issue1168Metadata('https://dataservices.gfz-potsdam.de/extern/IGSN/GFSO273/replacement.jpg');
    $metadata->update([
        'sample_image_storage_path' => 'igsn-sample-images/gfso273n39/existing.jpg',
        'sample_image_mime_type' => 'image/jpeg',
        'sample_image_size' => 123,
    ]);
    Storage::disk('public')->put('igsn-sample-images/gfso273n39/existing.jpg', issue1168Jpeg());
    Http::fake(['dataservices.gfz-potsdam.de/*' => Http::response('', 503)]);

    expect(app(IgsnSampleImageStorageService::class)->sync($metadata)['status'])->toBe('failed')
        ->and($metadata->fresh()->sample_image_storage_path)->toBe('igsn-sample-images/gfso273n39/existing.jpg');
    Storage::disk('public')->assertExists('igsn-sample-images/gfso273n39/existing.jpg');
});
