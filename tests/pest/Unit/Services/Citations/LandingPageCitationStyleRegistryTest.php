<?php

declare(strict_types=1);

use App\Services\Citations\LandingPageCitationStyleRegistry;

covers(LandingPageCitationStyleRegistry::class);

it('exposes the five fixed styles in UI order with explicit locales', function () {
    $styles = (new LandingPageCitationStyleRegistry())->styles();

    expect($styles)
        ->toHaveCount(5)
        ->sequence(
            fn ($style) => $style
                ->id->toBe('apa-7')
                ->label->toBe('APA 7')
                ->locale->toBe('en-US')
                ->path->toEndWith('apa.csl'),
            fn ($style) => $style
                ->id->toBe('harvard')
                ->label->toBe('Harvard (Cite Them Right)')
                ->locale->toBe('en-GB')
                ->path->toEndWith('harvard-cite-them-right.csl'),
            fn ($style) => $style
                ->id->toBe('copernicus')
                ->label->toBe('Copernicus / EGU')
                ->locale->toBe('en-US')
                ->path->toEndWith('copernicus-publications.csl'),
            fn ($style) => $style
                ->id->toBe('agu')
                ->label->toBe('AGU')
                ->locale->toBe('en-US')
                ->path->toEndWith('american-geophysical-union.csl'),
            fn ($style) => $style
                ->id->toBe('gsa')
                ->label->toBe('GSA')
                ->locale->toBe('en-US')
                ->path->toEndWith('the-geological-society-of-america.csl'),
        );

    expect(array_column($styles, 'id'))->toEqual(array_unique(array_column($styles, 'id')))
        ->and(array_column($styles, 'path'))->toEqual(array_unique(array_column($styles, 'path')));
});

it('points only to present parseable independent CSL styles', function () {
    foreach ((new LandingPageCitationStyleRegistry())->styles() as $style) {
        expect($style['path'])->toBeFile();

        $xml = simplexml_load_file($style['path']);
        expect($xml)->not->toBeFalse();

        $xml->registerXPathNamespace('csl', 'http://purl.org/net/xbiblio/csl');
        expect($xml->xpath('/csl:style'))->toHaveCount(1)
            ->and($xml->xpath('/csl:style/csl:info/csl:link[@rel="independent-parent"]'))->toBe([]);
    }
});

it('can resolve the same allow-list from an explicit directory', function () {
    $directory = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'citation-styles';
    $styles = (new LandingPageCitationStyleRegistry($directory))->styles();

    expect($styles[0]['path'])->toBe($directory.DIRECTORY_SEPARATOR.'apa.csl')
        ->and($styles[4]['path'])->toBe(
            $directory.DIRECTORY_SEPARATOR.'the-geological-society-of-america.csl',
        );
});
