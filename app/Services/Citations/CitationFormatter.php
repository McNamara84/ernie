<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Models\RelatedItem;

/**
 * Formats a RelatedItem as an APA 7 or IEEE citation string.
 *
 * Pure function — no DB or HTTP calls. The identical algorithm is
 * mirrored in `resources/js/lib/citation-formatter.ts` so the frontend
 * can format without a round trip. Both sides share the same fixtures
 * under tests/Fixtures/citations/.
 */
final class CitationFormatter
{
    public const STYLE_APA = 'apa';
    public const STYLE_IEEE = 'ieee';

    public function format(RelatedItem $item, string $style = self::STYLE_APA): string
    {
        return match ($style) {
            self::STYLE_IEEE => $this->formatIeee($item),
            default => $this->formatApa($item),
        };
    }

    private function formatApa(RelatedItem $item): string
    {
        $creators = $this->formatCreatorsApa($item);
        $year = $item->publication_year !== null ? "({$item->publication_year})" : '(n.d.)';
        $title = $this->mainTitle($item);
        $container = $this->containerTitle($item);
        $volIssue = $this->volumeIssue($item);
        $pages = $this->pages($item);
        $publisher = $item->publisher;
        $doi = $this->doiUrl($item);

        $parts = [];
        if ($creators !== '') {
            $parts[] = "{$creators} {$year}.";
        } else {
            $parts[] = "{$year}.";
        }

        $titlePart = $title;
        if ($container !== null) {
            $parts[] = "{$titlePart}.";
            $cont = $container;
            if ($volIssue !== '') {
                $cont .= ", {$volIssue}";
            }
            if ($pages !== '') {
                $cont .= ", {$pages}";
            }
            $parts[] = "{$cont}.";
        } else {
            if ($volIssue !== '') {
                $titlePart .= " ({$volIssue})";
            }
            if ($pages !== '') {
                $titlePart .= ", {$pages}";
            }
            $parts[] = "{$titlePart}.";
            if ($publisher !== null && $publisher !== '') {
                $parts[] = "{$publisher}.";
            }
        }

        if ($doi !== null) {
            $parts[] = $doi;
        }

        return trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    private function formatIeee(RelatedItem $item): string
    {
        $creators = $this->formatCreatorsIeee($item);
        $title = $this->mainTitle($item);
        $container = $this->containerTitle($item);
        $volume = $item->volume;
        $issue = $item->issue;
        $pages = $this->pages($item);
        $year = $item->publication_year;
        $publisher = $item->publisher;
        $doi = $this->doiUrl($item);

        $parts = [];
        if ($creators !== '') {
            $parts[] = "{$creators},";
        }

        $parts[] = "\"{$title},\"";

        if ($container !== null) {
            $segment = $container;
            if ($volume !== null && $volume !== '') {
                $segment .= ", vol. {$volume}";
            }
            if ($issue !== null && $issue !== '') {
                $segment .= ", no. {$issue}";
            }
            if ($pages !== '') {
                $segment .= ", pp. {$pages}";
            }
            if ($year !== null) {
                $segment .= ", {$year}";
            }
            $parts[] = "{$segment}.";
        } else {
            if ($publisher !== null && $publisher !== '') {
                $parts[] = "{$publisher},";
            }
            if ($year !== null) {
                $parts[] = "{$year}.";
            }
        }

        if ($doi !== null) {
            $parts[] = "doi: {$doi}";
        }

        return trim(implode(' ', $parts));
    }

    private function mainTitle(RelatedItem $item): string
    {
        return $item->mainTitle() ?? '[untitled]';
    }

    private function containerTitle(RelatedItem $item): ?string
    {
        // For journal articles / chapters, publisher acts as container.
        // More detailed container fields are not in the schema.
        if (in_array($item->related_item_type, ['JournalArticle', 'BookChapter', 'ConferencePaper'], true)) {
            return $item->publisher;
        }

        return null;
    }

    private function volumeIssue(RelatedItem $item): string
    {
        if ($item->volume && $item->issue) {
            return "{$item->volume}({$item->issue})";
        }
        if ($item->volume) {
            return (string) $item->volume;
        }
        if ($item->issue) {
            return "({$item->issue})";
        }

        return '';
    }

    private function pages(RelatedItem $item): string
    {
        if ($item->first_page && $item->last_page) {
            return "{$item->first_page}-{$item->last_page}";
        }
        if ($item->first_page) {
            return (string) $item->first_page;
        }

        return '';
    }

    private function doiUrl(RelatedItem $item): ?string
    {
        if ($item->identifier_type === 'DOI' && $item->identifier) {
            $doi = ltrim($item->identifier, '/');
            if (str_starts_with($doi, 'http')) {
                return $doi;
            }

            return "https://doi.org/{$doi}";
        }

        return null;
    }

    private function formatCreatorsApa(RelatedItem $item): string
    {
        $names = $item->creators->map(fn ($c): string => $this->creatorApaName($c))->all();
        $names = array_values(array_filter($names, static fn (string $n): bool => $n !== ''));

        if (count($names) === 0) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        if (count($names) <= 20) {
            $last = array_pop($names);

            return implode(', ', $names) . ', & ' . $last;
        }

        // APA 7: more than 20 authors → first 19 … last
        $last = $names[count($names) - 1];
        $first19 = array_slice($names, 0, 19);

        return implode(', ', $first19) . ', … ' . $last;
    }

    private function formatCreatorsIeee(RelatedItem $item): string
    {
        $names = $item->creators->map(fn ($c): string => $this->creatorIeeeName($c))->all();
        $names = array_values(array_filter($names, static fn (string $n): bool => $n !== ''));

        if (count($names) === 0) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        if (count($names) <= 6) {
            $last = array_pop($names);

            return implode(', ', $names) . ', and ' . $last;
        }

        return $names[0] . ' et al.';
    }

    private function creatorApaName(mixed $creator): string
    {
        if ($creator->name_type === 'Organizational') {
            return (string) $creator->name;
        }

        $family = $creator->family_name;
        $given = $creator->given_name;
        if ($family && $given) {
            $initials = $this->initials($given);

            return "{$family}, {$initials}";
        }

        return (string) $creator->name;
    }

    private function creatorIeeeName(mixed $creator): string
    {
        if ($creator->name_type === 'Organizational') {
            return (string) $creator->name;
        }

        $family = $creator->family_name;
        $given = $creator->given_name;
        if ($family && $given) {
            $initials = $this->initials($given);

            return "{$initials} {$family}";
        }

        return (string) $creator->name;
    }

    private function initials(string $given): string
    {
        $parts = preg_split('/[\s\-]+/', trim($given)) ?: [];
        $initials = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials[] = mb_strtoupper(mb_substr($part, 0, 1)) . '.';
        }

        return implode(' ', $initials);
    }
}
