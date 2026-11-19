<?php

declare(strict_types=1);

use App\Services\News\NewsMarkdownRendererService;

it('renders common Markdown and safe external images with homepage heading levels', function (): void {
    $html = app(NewsMarkdownRendererService::class)->render("# Heading\n\n**Bold** and *italic* with [internal](/about), [relative](guide) and [mail](mailto:news@example.org).\n\n- Item\n\n1. First\n\n![Rock](https://example.org/rock.jpg \"A rock\")\n\n> Quote\n\n```text\nExample\n```");
    expect($html)->toContain('<h4>Heading</h4>', '<strong>Bold</strong>', '<em>italic</em>', '<ul>', '<ol>', '<blockquote>', '<pre>')
        ->toContain('href="/about"', 'href="guide"', 'href="mailto:news@example.org"', 'src="https://example.org/rock.jpg"', 'alt="Rock"', 'loading="lazy"', 'referrerpolicy="no-referrer"');
});

it('excludes unsafe image sources', function (string $url): void {
    $html = app(NewsMarkdownRendererService::class)->render("![Image]({$url})");
    expect($html)->not->toContain('<img', 'src=');
})->with(['http://example.org/image.png', '//example.org/image.png', '/images/image.png', 'data:image/png;base64,AAAA', 'javascript:alert%281%29', 'ftp://example.org/image.png']);

it('removes unsafe links while preserving their labels', function (string $url): void {
    $html = app(NewsMarkdownRendererService::class)->render("[**Label**]({$url})");
    expect($html)->toContain('<strong>Label</strong>')->not->toContain('<a', 'href=');
})->with(['javascript:alert%281%29', 'jav&#x61;script:alert%281%29', 'data:text/html,hello', 'file:///etc/passwd', '//example.org', 'vbscript:alert%281%29']);

it('strips raw HTML and cannot introduce script attributes', function (): void {
    $html = app(NewsMarkdownRendererService::class)->render('<img src=x onerror="alert(1)"><script>alert(2)</script><iframe src="https://example.org"></iframe>');
    expect($html)->not->toContain('<img', '<script', '<iframe', 'onerror');
    $escaped = app(NewsMarkdownRendererService::class)->render('![alt" onerror="alert(1)](https://example.org/image.png "title\" onerror=\"alert")');
    expect($escaped)->not->toContain(' onerror="');
});

it('supports empty content and limits deeply nested input', function (): void {
    $renderer = app(NewsMarkdownRendererService::class);
    expect($renderer->render(''))->toBe('');
    $html = $renderer->render(str_repeat('> ', 100).'Nested text');
    expect(substr_count($html, '<blockquote>'))->toBeLessThanOrEqual(32);
});
