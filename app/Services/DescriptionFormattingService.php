<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use RuntimeException;

class DescriptionFormattingService
{
    /** @var list<string> */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'sub', 'sup', 'code'];

    /** @var list<string> */
    private const DROP_WITH_CONTENT_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base'];

    /**
     * @return array{plainText: string, landingPageHtml: string|null}
     */
    public function formatForStorage(string $input): array
    {
        $trimmedInput = $this->normalizePlainText($input);

        if ($trimmedInput === '') {
            return [
                'plainText' => '',
                'landingPageHtml' => null,
            ];
        }

        if (! $this->containsHtml($trimmedInput)) {
            return [
                'plainText' => $trimmedInput,
                'landingPageHtml' => null,
            ];
        }

        $sanitizedHtml = $this->sanitizeHtml($trimmedInput);
        $plainText = $this->plainTextFromHtml($sanitizedHtml);

        if ($plainText === '') {
            return [
                'plainText' => '',
                'landingPageHtml' => null,
            ];
        }

        return [
            'plainText' => $plainText,
            'landingPageHtml' => $sanitizedHtml !== '' ? $sanitizedHtml : null,
        ];
    }

    public function sanitizeHtml(string $html): string
    {
        $wrapper = $this->parseFragment($html);

        return trim($this->sanitizeChildren($wrapper));
    }

    public function plainTextFromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $wrapper = $this->parseFragment($html);
        $text = $this->extractText($wrapper);

        return $this->normalizeExtractedText($text);
    }

    private function containsHtml(string $input): bool
    {
        $htmlDetectionTags = [...self::ALLOWED_TAGS, ...self::DROP_WITH_CONTENT_TAGS];
        $detectedTagsPattern = implode('|', array_map(
            static fn (string $tagName): string => preg_quote($tagName, '/'),
            $htmlDetectionTags,
        ));

        return preg_match('/<\s*\/?\s*(?:'.$detectedTagsPattern.')(?=[\s>\/])/i', $input) === 1;
    }

    private function parseFragment(string $html): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousLibxmlSetting = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="description-wrapper">'.$html.'</div>',
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlSetting);

        if ($loaded === false) {
            throw new RuntimeException('Failed to parse description HTML fragment.');
        }

        $wrapper = $document->getElementsByTagName('div')->item(0);

        if (! $wrapper instanceof DOMElement) {
            throw new RuntimeException('Description HTML wrapper element is missing.');
        }

        return $wrapper;
    }

    private function sanitizeChildren(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $childNode) {
            $html .= $this->sanitizeNode($childNode);
        }

        return $html;
    }

    private function sanitizeNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->wholeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tagName = strtolower($node->tagName);

        if (in_array($tagName, self::DROP_WITH_CONTENT_TAGS, true)) {
            return '';
        }

        if (! in_array($tagName, self::ALLOWED_TAGS, true)) {
            return $this->sanitizeChildren($node);
        }

        if ($tagName === 'br') {
            return '<br>';
        }

        $content = $this->sanitizeChildren($node);

        if ($tagName === 'a') {
            $href = $this->sanitizeHref($node->getAttribute('href'));

            if ($href === null) {
                return $content;
            }

            return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false).'">'.$content.'</a>';
        }

        return sprintf('<%1$s>%2$s</%1$s>', $tagName, $content);
    }

    private function sanitizeHref(?string $href): ?string
    {
        $normalizedHref = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($normalizedHref === '') {
            return null;
        }

        $parts = parse_url($normalizedHref);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return null;
        }

        return $normalizedHref;
    }

    private function extractText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->wholeText;
        }

        if (! $node instanceof DOMElement) {
            return $this->extractChildrenText($node);
        }

        $tagName = strtolower($node->tagName);

        return match ($tagName) {
            'br' => "\n",
            'p' => $this->formatBlockText($node),
            'ul', 'ol' => $this->formatListText($node),
            'a' => $this->formatAnchorText($node),
            default => $this->extractChildrenText($node),
        };
    }

    private function extractChildrenText(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $childNode) {
            $text .= $this->extractText($childNode);
        }

        return $text;
    }

    private function formatBlockText(DOMElement $element): string
    {
        $content = trim($this->extractChildrenText($element));

        if ($content === '') {
            return '';
        }

        return $content."\n\n";
    }

    private function formatListText(DOMElement $list): string
    {
        $items = [];
        $isOrdered = strtolower($list->tagName) === 'ol';
        $counter = 1;

        foreach ($list->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement || strtolower($childNode->tagName) !== 'li') {
                continue;
            }

            $itemText = $this->normalizeInlineText($this->extractChildrenText($childNode));

            if ($itemText === '') {
                continue;
            }

            $items[] = ($isOrdered ? $counter.'. ' : '- ').$itemText;
            $counter++;
        }

        if ($items === []) {
            return '';
        }

        return implode("\n", $items)."\n\n";
    }

    private function formatAnchorText(DOMElement $element): string
    {
        $label = $this->normalizeInlineText($this->extractChildrenText($element));
        $href = $this->sanitizeHref($element->getAttribute('href'));

        if ($href === null) {
            return $label;
        }

        if ($label === '') {
            return $href;
        }

        return $label === $href ? $label : sprintf('%s (%s)', $label, $href);
    }

    private function normalizePlainText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim($text);
    }

    private function normalizeExtractedText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizeInlineText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}