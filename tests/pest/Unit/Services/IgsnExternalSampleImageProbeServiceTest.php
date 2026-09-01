<?php

declare(strict_types=1);

use App\Services\Igsn\IgsnExternalSampleImageProbeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

covers(IgsnExternalSampleImageProbeService::class);

beforeEach(function (): void {
    Config::set('igsn_images.external_probe_max_bytes', 1024);
    Config::set('igsn_images.allowed_mime_types', ['image/jpeg' => 'jpg']);
});

function externalProbeJpeg(): string
{
    return (string) base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', true);
}

it('accepts a bounded JPEG response from an allowlisted ICDP URL', function (): void {
    Http::fake(['data.icdp-online.org/*' => Http::response(externalProbeJpeg(), 206, ['Content-Type' => 'image/jpeg'])]);

    $result = app(IgsnExternalSampleImageProbeService::class)->probe(
        'https://data.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg',
    );

    expect($result)->toBe([
        'status' => 'available',
        'url' => 'https://data.icdp-online.org/sites/cosc/news/cores/CS_5054.jpg',
        'message' => 'external_image_available',
    ]);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Range', 'bytes=0-1023'));
});

it('classifies definitive missing responses and invalid content as unavailable', function (int $status, string $body, string $message): void {
    Http::fake(['data.icdp-online.org/*' => Http::response($body, $status)]);

    $result = app(IgsnExternalSampleImageProbeService::class)->probe(
        'https://data.icdp-online.org/sites/lusklint/news/cores/missing.jpg',
    );

    expect($result['status'])->toBe('unavailable')
        ->and($result['message'])->toBe($message);
})->with([
    'not found' => [404, '', 'http_404'],
    'gone' => [410, '', 'http_410'],
    'HTML instead of an image' => [200, '<html>missing</html>', 'unsupported_mime_type'],
]);

it('keeps transient server failures separate from unavailable images', function (): void {
    Http::fake(['data.icdp-online.org/*' => Http::response('', 503)]);

    $result = app(IgsnExternalSampleImageProbeService::class)->probe(
        'https://data.icdp-online.org/sites/cosc/news/cores/temporary.jpg',
    );

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toBe('http_503');
});

it('refuses to probe URLs outside the existing image allowlist', function (): void {
    Http::preventStrayRequests();

    expect(app(IgsnExternalSampleImageProbeService::class)->probe('https://example.org/image.jpg'))->toBe([
        'status' => 'failed',
        'url' => null,
        'message' => 'unsupported_external_url',
    ]);
    Http::assertNothingSent();
});
