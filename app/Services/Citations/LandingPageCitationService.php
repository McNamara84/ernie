<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Models\Resource;
use Illuminate\Support\Facades\Log;
use Seboettg\CiteProc\CiteProc;
use Seboettg\CiteProc\StyleSheet;
use Throwable;
use UnexpectedValueException;

/**
 * Pre-renders the five official citation styles used by landing pages.
 */
final class LandingPageCitationService
{
    public function __construct(
        private readonly LandingPageCslItemMapperService $mapper,
        private readonly CitationHtmlSanitizerService $sanitizer,
        private readonly LandingPageCitationStyleRegistryService $registry,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     available: bool,
     *     html: string|null,
     *     text: string|null
     * }>
     */
    public function format(Resource $resource): array
    {
        $styles = $this->registry->styles();

        try {
            $item = $this->mapper->map($resource);
        } catch (Throwable $exception) {
            return array_map(function (array $style) use ($resource, $exception): array {
                $this->logFailure($resource, $style['id'], $exception);

                return $this->unavailableStyle($style);
            }, $styles);
        }

        return array_map(function (array $style) use ($resource, $item): array {
            try {
                [$html, $text] = $this->renderStyle($style, $item);

                return [
                    'id' => $style['id'],
                    'label' => $style['label'],
                    'available' => true,
                    'html' => $html,
                    'text' => $text,
                ];
            } catch (Throwable $exception) {
                $this->logFailure($resource, $style['id'], $exception);

                return $this->unavailableStyle($style);
            }
        }, $styles);
    }

    /**
     * @param  array{id: string, label: string, path: string, locale: string}  $style
     * @param  array<string, mixed>  $item
     * @return array{string, string}
     */
    private function renderStyle(array $style, array $item): array
    {
        [$cslItem, $markers] = $this->engineCompatibleItem($item);
        $previousErrorReporting = error_reporting();
        error_reporting($previousErrorReporting & ~E_DEPRECATED);

        try {
            $styleSheet = StyleSheet::loadStyleSheet($style['path']);
            $processor = new CiteProc($styleSheet, $style['locale']);
            $css = $processor->renderCssStyles();
            $bibliography = $processor->render([$cslItem], 'bibliography');
        } finally {
            error_reporting($previousErrorReporting);
        }

        $bibliography = $this->restoreEngineText($bibliography, $markers);
        $html = $this->sanitizer->sanitize($bibliography, $css);
        $text = $this->sanitizer->toPlainText($html);

        if ($text === '') {
            throw new UnexpectedValueException('The CSL processor returned an empty bibliography entry.');
        }

        return [$html, $text];
    }

    /**
     * citeproc-php 2.7.1 does not render CSL's standard "literal" name
     * property. Keep the mapper's canonical CSL-JSON intact and add the
     * equivalent family value only to the engine-specific object graph.
     *
     * @param  array<string, mixed>  $item
     * @return array{
     *     object,
     *     array{ampersand: string, less_than: string, greater_than: string}
     * }
     */
    private function engineCompatibleItem(array $item): array
    {
        $markers = $this->engineTextMarkers($item);

        foreach (['title', 'publisher', 'version', 'genre'] as $textKey) {
            if (isset($item[$textKey]) && is_string($item[$textKey])) {
                $item[$textKey] = $this->escapeEngineText($item[$textKey], $markers);
            }
        }

        if (isset($item['author']) && is_array($item['author'])) {
            foreach ($item['author'] as &$author) {
                if (is_array($author)) {
                    foreach (['family', 'given', 'literal'] as $nameKey) {
                        if (isset($author[$nameKey]) && is_string($author[$nameKey])) {
                            $author[$nameKey] = $this->escapeEngineText($author[$nameKey], $markers);
                        }
                    }
                }

                if (
                    is_array($author)
                    && isset($author['literal'])
                    && is_string($author['literal'])
                    && ! isset($author['family'])
                ) {
                    $author['given'] = '';
                    $author['family'] = $author['literal'];
                }
            }
            unset($author);
        }

        $decoded = json_decode(
            json_encode($item, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_object($decoded)) {
            throw new UnexpectedValueException('The CSL item could not be converted for the processor.');
        }

        return [$decoded, $markers];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{ampersand: string, less_than: string, greater_than: string}
     */
    private function engineTextMarkers(array $item): array
    {
        $serializedItem = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        for ($nonce = 0; ; $nonce++) {
            $prefix = "\u{E000}{$nonce}";
            $markers = [
                'ampersand' => $prefix."0\u{E003}",
                'less_than' => $prefix."1\u{E003}",
                'greater_than' => $prefix."2\u{E003}",
            ];
            $collides = false;

            foreach ($markers as $marker) {
                if (str_contains($serializedItem, $marker)) {
                    $collides = true;

                    break;
                }
            }

            if (! $collides) {
                return $markers;
            }
        }
    }

    /**
     * @param  array{ampersand: string, less_than: string, greater_than: string}  $markers
     */
    private function escapeEngineText(string $value, array $markers): string
    {
        // citeproc-php treats angle brackets in metadata as its own markup.
        // Collision-checked placeholders survive formatting and are restored
        // to numeric entities only after rendering, so the DOM parser receives
        // visible text rather than executable elements.
        return strtr($value, [
            '&' => $markers['ampersand'],
            '<' => $markers['less_than'],
            '>' => $markers['greater_than'],
        ]);
    }

    /**
     * @param  array{ampersand: string, less_than: string, greater_than: string}  $markers
     */
    private function restoreEngineText(string $bibliography, array $markers): string
    {
        return strtr($bibliography, [
            $markers['ampersand'] => '&#38;',
            $markers['less_than'] => '&#60;',
            $markers['greater_than'] => '&#62;',
        ]);
    }

    /**
     * @param  array{id: string, label: string, path: string, locale: string}  $style
     * @return array{id: string, label: string, available: false, html: null, text: null}
     */
    private function unavailableStyle(array $style): array
    {
        return [
            'id' => $style['id'],
            'label' => $style['label'],
            'available' => false,
            'html' => null,
            'text' => null,
        ];
    }

    private function logFailure(Resource $resource, string $styleId, Throwable $exception): void
    {
        Log::error('Failed to render landing-page citation style.', [
            'resource_id' => $resource->getKey(),
            'style_id' => $styleId,
            'exception' => $exception,
        ]);
    }
}
