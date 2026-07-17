<?php

declare(strict_types=1);

namespace App\Services\Citations;

/**
 * The allow-listed CSL styles available on built-in landing pages.
 *
 * The order is part of the Inertia contract and must stay aligned with the
 * citation-style selector in the frontend.
 */
final class LandingPageCitationStyleRegistry
{
    /**
     * @var list<array{id: string, label: string, filename: string, locale: string}>
     */
    public const DEFINITIONS = [
        [
            'id' => 'apa-7',
            'label' => 'APA 7',
            'filename' => 'apa.csl',
            'locale' => 'en-US',
        ],
        [
            'id' => 'harvard',
            'label' => 'Harvard (Cite Them Right)',
            'filename' => 'harvard-cite-them-right.csl',
            'locale' => 'en-GB',
        ],
        [
            'id' => 'copernicus',
            'label' => 'Copernicus / EGU',
            'filename' => 'copernicus-publications.csl',
            'locale' => 'en-US',
        ],
        [
            'id' => 'agu',
            'label' => 'AGU',
            'filename' => 'american-geophysical-union.csl',
            'locale' => 'en-US',
        ],
        [
            'id' => 'gsa',
            'label' => 'GSA',
            'filename' => 'the-geological-society-of-america.csl',
            'locale' => 'en-US',
        ],
    ];

    public function __construct(
        private readonly ?string $styleDirectory = null,
    ) {}

    /**
     * @return list<array{id: string, label: string, path: string, locale: string}>
     */
    public function styles(): array
    {
        $styleDirectory = $this->styleDirectory ?? base_path('resources/data/csl/styles');

        return array_map(
            static fn (array $definition): array => [
                'id' => $definition['id'],
                'label' => $definition['label'],
                'path' => $styleDirectory.DIRECTORY_SEPARATOR.$definition['filename'],
                'locale' => $definition['locale'],
            ],
            self::DEFINITIONS,
        );
    }
}
