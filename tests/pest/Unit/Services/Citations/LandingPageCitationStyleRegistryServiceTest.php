<?php

declare(strict_types=1);

use App\Services\Citations\LandingPageCitationStyleRegistryService;

covers(LandingPageCitationStyleRegistryService::class);

it('exposes the five fixed styles in UI order with explicit locales', function () {
    $styles = (new LandingPageCitationStyleRegistryService)->styles();

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

it('pins the expected file contents and internal IDs of all independent CSL styles', function () {
    $expectedStyles = [
        'apa-7' => ['filename' => 'apa.csl', 'csl_id' => 'http://www.zotero.org/styles/apa', 'sha256' => '17bc430cf931767d551a894129b3a705e1feee91090295c09674e370ccdef5d9'],
        'harvard' => ['filename' => 'harvard-cite-them-right.csl', 'csl_id' => 'http://www.zotero.org/styles/harvard-cite-them-right', 'sha256' => '6053e3448b5e7da4a814f2a8610c1bf29cc5a243c24c9e2e5c3e7cd225230df7'],
        'copernicus' => ['filename' => 'copernicus-publications.csl', 'csl_id' => 'http://www.zotero.org/styles/copernicus-publications', 'sha256' => 'a0e16fd5f4af5c5043726cdd1b82984d1ac12d8118492c39004cc547352a3bdb'],
        'agu' => ['filename' => 'american-geophysical-union.csl', 'csl_id' => 'http://www.zotero.org/styles/american-geophysical-union', 'sha256' => '2c343e722c03bbda4722edbd234ca0ae21173a3f5088bf239145df433a9a59f7'],
        'gsa' => ['filename' => 'the-geological-society-of-america.csl', 'csl_id' => 'http://www.zotero.org/styles/the-geological-society-of-america', 'sha256' => '2e0aaf443ae73fd81edaea5a231357e9f231a8a7d4e2632083484434ea6cab6b'],
    ];

    foreach ((new LandingPageCitationStyleRegistryService)->styles() as $style) {
        $expected = $expectedStyles[$style['id']];

        expect($style['path'])->toBeFile()
            ->and(basename($style['path']))->toBe($expected['filename'])
            ->and(hash_file('sha256', $style['path']))->toBe($expected['sha256']);

        $xml = simplexml_load_file($style['path']);
        expect($xml)->not->toBeFalse();

        $xml->registerXPathNamespace('csl', 'http://purl.org/net/xbiblio/csl');
        $ids = $xml->xpath('/csl:style/csl:info/csl:id');

        expect($xml->xpath('/csl:style'))->toHaveCount(1);
        expect($ids)->toHaveCount(1);
        expect((string) $ids[0])->toBe($expected['csl_id']);
        expect($xml->xpath('/csl:style/csl:info/csl:link[@rel="independent-parent"]'))->toBe([]);
    }
});

it('can resolve the same allow-list from an explicit directory', function () {
    $directory = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'citation-styles';
    $styles = (new LandingPageCitationStyleRegistryService($directory))->styles();

    expect($styles[0]['path'])->toBe($directory.DIRECTORY_SEPARATOR.'apa.csl')
        ->and($styles[4]['path'])->toBe(
            $directory.DIRECTORY_SEPARATOR.'the-geological-society-of-america.csl',
        );
});
