<?php

declare(strict_types=1);

use App\Services\DescriptionFormattingService;

covers(DescriptionFormattingService::class);

beforeEach(function (): void {
    $this->service = new DescriptionFormattingService;
});

it('keeps plain text descriptions unchanged', function (): void {
    $result = $this->service->formatForStorage("Plain text\nwith line breaks");

    expect($result['plainText'])->toBe("Plain text\nwith line breaks")
        ->and($result['landingPageHtml'])->toBeNull();
});

it('sanitizes allowed html and strips unsupported markup', function (): void {
    $result = $this->service->formatForStorage('<p>Hello <strong>world</strong><script>alert(1)</script> <a href="javascript:alert(1)">bad link</a> <a href="https://example.org/docs">Documentation</a></p>');

    expect($result['landingPageHtml'])->toBe('<p>Hello <strong>world</strong> bad link <a href="https://example.org/docs">Documentation</a></p>')
        ->and($result['plainText'])->toBe('Hello world bad link Documentation (https://example.org/docs)');
});

it('converts formatted lists into readable plain text for exports', function (): void {
    $result = $this->service->formatForStorage('<p>Overview</p><ul><li>First item</li><li>Second item</li></ul>');

    expect($result['landingPageHtml'])->toBe('<p>Overview</p><ul><li>First item</li><li>Second item</li></ul>')
        ->and($result['plainText'])->toBe("Overview\n\n- First item\n- Second item");
});

it('drops unsupported links from landing page html while preserving link text', function (): void {
    $result = $this->service->formatForStorage('<p><a href="ftp://example.org/file">FTP resource</a></p>');

    expect($result['landingPageHtml'])->toBe('<p>FTP resource</p>')
        ->and($result['plainText'])->toBe('FTP resource');
});

it('treats formatting-only html as empty content', function (): void {
    $result = $this->service->formatForStorage('<p><br></p>');

    expect($result['landingPageHtml'])->toBeNull()
        ->and($result['plainText'])->toBe('');
});

it('keeps literal angle-bracketed plain text untouched when no supported html tag exists', function (): void {
    $result = $this->service->formatForStorage('Use placeholder <x> in the formula and keep it literal.');

    expect($result['landingPageHtml'])->toBeNull()
        ->and($result['plainText'])->toBe('Use placeholder <x> in the formula and keep it literal.');
});

it('sanitizes unsupported html wrappers into plain text content', function (): void {
    $result = $this->service->formatForStorage('<span>Inline <mark>formatting</mark></span>');

    expect($result['landingPageHtml'])->toBe('Inline formatting')
        ->and($result['plainText'])->toBe('Inline formatting');
});