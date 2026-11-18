<?php

declare(strict_types=1);

namespace App\Services\News;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;

final class NewsMarkdownRendererService
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 32, 'max_delimiters_per_line' => 500]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            foreach (iterator_to_array($event->getDocument()->iterator()) as $node) {
                if ($node instanceof Heading) {
                    $node->setLevel(min(6, $node->getLevel() + 3));
                } elseif ($node instanceof Image) {
                    if (! $this->allowedLink($node->getUrl()) || ! str_starts_with(strtolower($node->getUrl()), 'https://')) {
                        $node->detach();
                    } else {
                        $node->data->set('attributes', ['loading' => 'lazy', 'referrerpolicy' => 'no-referrer']);
                    }
                } elseif ($node instanceof Link && ! $this->allowedLink($node->getUrl())) {
                    foreach (iterator_to_array($node->children()) as $child) {
                        $node->insertBefore($child);
                    }
                    $node->detach();
                }
            }
        });
        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): string
    {
        return (string) $this->converter->convert($markdown);
    }

    private function allowedLink(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1 || str_starts_with($url, '//')) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) !== 1) {
            return true;
        }

        return preg_match('~^(https?://[^/]+|mailto:[^\s]+)~i', $url) === 1;
    }
}
