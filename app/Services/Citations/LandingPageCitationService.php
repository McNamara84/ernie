<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Models\Resource;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

        return array_map(
            fn (array $style): array => $this->formatMappedStyle($resource, $style, $item),
            $styles,
        );
    }

    /**
     * Render one allow-listed citation style without paying the cost of all
     * five landing-page styles. Public portal previews use this on demand.
     *
     * @return array{
     *     id: string,
     *     label: string,
     *     available: bool,
     *     html: string|null,
     *     text: string|null
     * }
     */
    public function formatStyle(Resource $resource, string $styleId): array
    {
        $style = $this->findStyle($styleId);

        try {
            $item = $this->mapper->map($resource);
        } catch (Throwable $exception) {
            $this->logFailure($resource, $style['id'], $exception);

            return $this->unavailableStyle($style);
        }

        return $this->formatMappedStyle($resource, $style, $item);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{text: string, italic: bool}>
     */
    public function formatApaParts(array $item): array
    {
        [$html] = $this->renderStyle($this->findStyle('apa-7'), $item);

        return $this->sanitizer->toTextParts($html);
    }

    /** @return array{id: string, label: string, path: string, locale: string} */
    private function findStyle(string $styleId): array
    {

        foreach ($this->registry->styles() as $candidate) {
            if ($candidate['id'] === $styleId) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException("Unknown landing-page citation style [{$styleId}].");
    }

    /**
     * @param  array{id: string, label: string, path: string, locale: string}  $style
     * @param  array<string, mixed>  $item
     * @return array{id: string, label: string, available: bool, html: string|null, text: string|null}
     */
    private function formatMappedStyle(Resource $resource, array $style, array $item): array
    {
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
            // render() always initializes citeproc-php's global context. Run it
            // before the CSS pass so a context left by a previously broken
            // style can never leak into this style.
            $bibliography = $processor->render([$cslItem], 'bibliography');
            $css = $processor->renderCssStyles();
        } finally {
            error_reporting($previousErrorReporting);
        }

        $bibliography = $this->restoreEngineText($bibliography, $markers);
        $bibliography = $this->normalizeDoiResolverUrl($bibliography, $item);
        $html = $this->sanitizer->sanitize($bibliography, $css);
        $text = $this->sanitizer->toPlainText($html);

        if ($text === '') {
            throw new UnexpectedValueException('The CSL processor returned an empty bibliography entry.');
        }

        return [$html, $text];
    }

    /** @param array<string, mixed> $item */
    private function normalizeDoiResolverUrl(string $bibliography, array $item): string
    {
        $doi = $item['DOI'] ?? null;
        $url = $item['URL'] ?? null;

        if (! is_string($doi) || $doi === '' || ! is_string($url) || $url === '') {
            return $bibliography;
        }

        $normalized = preg_replace_callback(
            '/doi:\s*'.preg_quote($doi, '/').'/iu',
            static fn (): string => $url,
            $bibliography,
        );

        return is_string($normalized) ? $normalized : $bibliography;
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

        array_walk_recursive($item, function (mixed &$value) use ($markers): void {
            if (is_string($value)) {
                $value = $this->escapeEngineText($value, $markers);
            }
        });

        foreach (['author', 'editor', 'collection-editor'] as $role) {
            foreach ($item[$role] ?? [] as $index => $name) {
                if (is_array($name) && isset($name['literal']) && ! isset($name['family'])) {
                    $item[$role][$index]['given'] = '';
                    $item[$role][$index]['family'] = $name['literal'];
                }
            }
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
