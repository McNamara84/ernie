<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;

uses()->group('browser', 'homepage');

beforeEach(function (): void {
    app(Vite::class)
        ->useHotFile(storage_path('framework/testing-vite.hot'))
        ->useBuildDirectory('build');
});

it('renders the public homepage with scrollable hexagons at every screen size', function (int $width, int $height): void {
    $page = visit('/')
        ->resize($width, $height)
        ->waitForText('Welcome to GFZ Data Services')
        ->assertSee('Welcome ELMO - our new Metadata Editor!')
        ->assertCount('.science-topic-link', 25)
        ->assertAttribute('a[data-slot="button"][href="https://dataservices.gfz.de/elmo"]', 'target', '_blank')
        ->assertNoSmoke();

    $state = $page->script(<<<'JS'
        () => ({
            title: document.querySelector('h1')?.textContent,
            width: document.documentElement.scrollWidth,
            height: document.documentElement.scrollHeight,
            viewportWidth: innerWidth,
            viewportHeight: innerHeight,
            hexagon: getComputedStyle(document.querySelector('.science-topic-hexagon')).clipPath,
            links: [...document.querySelectorAll('.science-topic-link')].map(link => ({
                label: link.getAttribute('aria-label'),
                href: link.getAttribute('href'),
            })),
        })
        JS);

    expect($state['title'])->toBe('GFZ Data Services');
    expect($state['width'])->toBeLessThanOrEqual($state['viewportWidth'] + 1);
    expect($state['height'])->toBeGreaterThan($state['viewportHeight']);
    expect($state['hexagon'])->toContain('polygon');
    foreach ($state['links'] as $link) {
        expect($link['label'])->not->toBeEmpty();
        expect($link['href'])->toStartWith('/doi-search?topic=');
    }
})->with([
    'desktop' => [1440, 900],
    'tablet' => [768, 1024],
    'mobile' => [390, 844],
]);
