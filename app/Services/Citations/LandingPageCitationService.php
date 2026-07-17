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
        private readonly LandingPageCslItemMapper $mapper,
        private readonly CitationHtmlSanitizer $sanitizer,
        private readonly LandingPageCitationStyleRegistry $registry,
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
        $previousErrorReporting = error_reporting();
        error_reporting($previousErrorReporting & ~E_DEPRECATED);

        try {
            $styleSheet = StyleSheet::loadStyleSheet($style['path']);
            $processor = new CiteProc($styleSheet, $style['locale']);
            $css = $processor->renderCssStyles();
            $cslItem = $this->engineCompatibleItem($item);
            $bibliography = $processor->render([$cslItem], 'bibliography');
            $html = $this->sanitizer->sanitize($bibliography, $css);
            $text = $this->sanitizer->toPlainText($html);

            if ($text === '') {
                throw new UnexpectedValueException('The CSL processor returned an empty bibliography entry.');
            }

            return [$html, $text];
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

    /**
     * citeproc-php 2.7.1 does not render CSL's standard "literal" name
     * property. Keep the mapper's canonical CSL-JSON intact and add the
     * equivalent family value only to the engine-specific object graph.
     *
     * @param  array<string, mixed>  $item
     */
    private function engineCompatibleItem(array $item): object
    {
        if (isset($item['author']) && is_array($item['author'])) {
            foreach ($item['author'] as &$author) {
                if (
                    is_array($author)
                    && isset($author['literal'])
                    && is_string($author['literal'])
                    && ! isset($author['family'])
                ) {
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

        return $decoded;
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
