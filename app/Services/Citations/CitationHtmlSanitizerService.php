<?php

declare(strict_types=1);

namespace App\Services\Citations;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use UnexpectedValueException;

/**
 * Sanitizes the single bibliography entry emitted by citeproc-php.
 *
 * This intentionally does not share the landing-page description sanitizer:
 * CSL output needs a smaller typography-oriented allow-list and fixed classes
 * for its generated layout.
 */
final class CitationHtmlSanitizerService
{
    /**
     * Elements whose content must be removed along with the element itself.
     *
     * @var list<string>
     */
    private const DROP_WITH_CONTENT = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
        'svg',
        'math',
        'template',
    ];

    /**
     * Inline elements emitted by citeproc-php that are safe without attributes.
     *
     * @var list<string>
     */
    private const INLINE_ELEMENTS = [
        'i',
        'em',
        'b',
        'strong',
        'sub',
        'sup',
    ];

    /**
     * Convert a citeproc bibliography document to one sanitized CSL entry.
     */
    public function sanitize(string $bibliographyHtml, string $css = ''): string
    {
        $source = $this->loadDocument($bibliographyHtml);
        $entry = $this->firstEntry($source);

        if ($entry === null) {
            throw new UnexpectedValueException('The CSL processor returned no bibliography entry.');
        }

        $target = new DOMDocument('1.0', 'UTF-8');
        $targetEntry = $target->createElement('div');
        $targetEntry->setAttribute('class', implode(' ', $this->entryClasses($css)));
        $target->appendChild($targetEntry);

        $this->appendSanitizedChildren($entry, $targetEntry, $target);

        $html = $target->saveHTML($targetEntry);

        if ($html === false) {
            throw new UnexpectedValueException('The sanitized CSL bibliography entry could not be serialized.');
        }

        return $html;
    }

    /**
     * Build clipboard text from the same sanitized HTML shown to the user.
     */
    public function toPlainText(string $sanitizedHtml): string
    {
        $document = $this->loadDocument($sanitizedHtml);
        $entry = $this->firstEntry($document);

        if ($entry === null) {
            throw new UnexpectedValueException('The sanitized citation contains no bibliography entry.');
        }

        $text = $this->visibleText($entry);
        $normalized = preg_replace('/[\s\p{Z}]+/u', ' ', $text);

        return trim($normalized ?? $text);
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousInternalErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="citation-parser-root">'.$html.'</div>',
                LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }

        if (! $loaded) {
            throw new UnexpectedValueException('The CSL bibliography HTML could not be parsed.');
        }

        return $document;
    }

    private function firstEntry(DOMDocument $document): ?DOMElement
    {
        $xpath = new DOMXPath($document);
        $entries = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " csl-entry ")]',
        );

        if ($entries === false) {
            return null;
        }

        $entry = $entries->item(0);

        return $entry instanceof DOMElement ? $entry : null;
    }

    /**
     * @return list<string>
     */
    private function entryClasses(string $css): array
    {
        $classes = ['csl-entry'];

        if (preg_match('/\.csl-entry\s*\{(?P<rules>[^}]*)\}/s', $css, $matches) !== 1) {
            return $classes;
        }

        $rules = $matches['rules'];

        if (
            preg_match('/(?:^|;)\s*padding-left\s*:\s*2em\s*(?:;|$)/i', $rules) === 1
            && preg_match('/(?:^|;)\s*text-indent\s*:\s*-2em\s*(?:;|$)/i', $rules) === 1
        ) {
            $classes[] = 'csl-hanging-indent';
        }

        if (preg_match('/(?:^|;)\s*line-height\s*:\s*2em\s*(?:;|$)/i', $rules) === 1) {
            $classes[] = 'csl-double-spaced';
        }

        return $classes;
    }

    private function appendSanitizedChildren(DOMNode $source, DOMNode $target, DOMDocument $targetDocument): void
    {
        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMText) {
                $target->appendChild($targetDocument->createTextNode($child->data));

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            if (in_array($tag, self::INLINE_ELEMENTS, true)) {
                $element = $targetDocument->createElement($tag);
                $target->appendChild($element);
                $this->appendSanitizedChildren($child, $element, $targetDocument);

                continue;
            }

            if ($tag === 'br') {
                $target->appendChild($targetDocument->createElement('br'));

                continue;
            }

            if ($tag === 'a') {
                $this->appendAnchor($child, $target, $targetDocument);

                continue;
            }

            if ($tag === 'span') {
                $this->appendSpan($child, $target, $targetDocument);

                continue;
            }

            if ($tag === 'div') {
                $this->appendLayoutDiv($child, $target, $targetDocument);

                continue;
            }

            // Unknown presentational markup is unwrapped so its visible text is
            // retained, but none of its attributes or behavior can survive.
            $this->appendSanitizedChildren($child, $target, $targetDocument);
        }
    }

    private function appendAnchor(DOMElement $source, DOMNode $target, DOMDocument $targetDocument): void
    {
        $href = trim($source->getAttribute('href'));

        if (! $this->isSafeHttpsUrl($href)) {
            $this->appendSanitizedChildren($source, $target, $targetDocument);

            return;
        }

        $anchor = $targetDocument->createElement('a');
        $anchor->setAttribute('href', $href);
        $anchor->setAttribute('rel', 'noopener noreferrer');
        $target->appendChild($anchor);
        $this->appendSanitizedChildren($source, $anchor, $targetDocument);
    }

    private function appendSpan(DOMElement $source, DOMNode $target, DOMDocument $targetDocument): void
    {
        $classes = $this->safeTypographyClasses($source->getAttribute('style'));

        if ($classes === []) {
            $this->appendSanitizedChildren($source, $target, $targetDocument);

            return;
        }

        $span = $targetDocument->createElement('span');
        $span->setAttribute('class', implode(' ', $classes));
        $target->appendChild($span);
        $this->appendSanitizedChildren($source, $span, $targetDocument);
    }

    private function appendLayoutDiv(DOMElement $source, DOMNode $target, DOMDocument $targetDocument): void
    {
        $class = $this->knownClass(
            $source->getAttribute('class'),
            ['csl-left-margin', 'csl-right-inline'],
        );

        if ($class === null) {
            $this->appendSanitizedChildren($source, $target, $targetDocument);

            return;
        }

        $div = $targetDocument->createElement('div');
        $div->setAttribute('class', $class);
        $target->appendChild($div);
        $this->appendSanitizedChildren($source, $div, $targetDocument);
    }

    /**
     * @return list<string>
     */
    private function safeTypographyClasses(string $style): array
    {
        $classes = [];

        foreach (explode(';', strtolower($style)) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = trim($property);
            $value = trim($value);

            if ($property === 'font-variant' && $value === 'small-caps') {
                $classes[] = 'csl-small-caps';
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param  list<string>  $allowed
     */
    private function knownClass(string $classes, array $allowed): ?string
    {
        $tokens = preg_split('/\s+/', trim($classes)) ?: [];

        foreach ($allowed as $class) {
            if (in_array($class, $tokens, true)) {
                return $class;
            }
        }

        return null;
    }

    private function isSafeHttpsUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function visibleText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->data;
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $childText = $this->visibleText($child);

            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['div', 'br'], true)) {
                $text .= ' '.$childText.' ';
            } else {
                $text .= $childText;
            }
        }

        return $text;
    }
}
