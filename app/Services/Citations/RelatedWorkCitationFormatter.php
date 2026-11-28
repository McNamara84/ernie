<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Enums\CacheKey;
use App\Services\DataCiteApiService;
use Throwable;

final readonly class RelatedWorkCitationFormatter
{
    public function __construct(
        private DataCiteApiService $dataCite,
        private LandingPageCitationService $renderer,
        private CrossrefClient $crossref,
    ) {}

    public static function paperType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'article-journal', 'journal-article', 'journalarticle', 'journal article' => 'Journal article',
            'paper-conference', 'proceedings-article', 'conferencepaper', 'conference-paper', 'conference paper' => 'Conference paper',
            'preprint' => 'Preprint',
            default => null,
        };
    }

    /** @param array<string, mixed> $metadata */
    public function format(string $doi, array $metadata, ?float $deadline = null): ?RelatedWorkCitation
    {
        $type = strtolower($this->value($metadata['type'] ?? null));
        $nativeType = strtolower($this->value(data_get($metadata, 'types.resourceTypeGeneral')));
        $ambiguous = in_array($type, ['article', 'manuscript', 'posted-content', 'text'], true);
        $subtype = strtolower($this->value($metadata['subtype'] ?? $metadata['genre'] ?? null));
        $kind = self::paperType($type);
        $unclear = $ambiguous || $type === '';
        if ($nativeType !== '') {
            $nativeKind = self::paperType($nativeType);
            $unclear = $unclear || $nativeKind !== $kind;
            $kind = ($ambiguous || $type === '' || $nativeKind === $kind) ? $nativeKind : null;
            if ($nativeType === 'text' && ($ambiguous || $type === '')) {
                $kind = self::paperType($this->value(data_get($metadata, 'types.resourceType')));
            }
        } elseif ($ambiguous) {
            if ($subtype === '') {
                $native = $this->nativeMetadata($doi, $deadline ?? microtime(true) + 3);
                $subtype = strtolower($this->value($native['subtype'] ?? null));
                $kind = self::paperType($this->value($native['type'] ?? null));
                $metadata += $native;
            }
            if ($subtype === 'preprint') {
                $kind = 'Preprint';
            }
        }

        if ($subtype === 'preprint' && $kind !== 'Preprint') {
            $kind = null;
            $unclear = true;
        } elseif (in_array(str_replace(['_', '-'], ' ', $subtype), ['working paper', 'workingpaper'], true)) {
            $kind = null;
            $unclear = false;
        }

        if ($kind === null) {
            $legacy = trim($this->dataCite->buildCitationFromMetadata($metadata));

            return $legacy === '' ? null : new RelatedWorkCitation($legacy, workType: $unclear ? 'Unclassified' : null);
        }

        $item = $this->cslItem($doi, $metadata, $kind);
        if ($item['title'] === '') {
            return null;
        }

        try {
            $apaParts = CacheKey::CITATION_APA_PARTS;
            $parts = $apaParts->repository()->remember(
                $apaParts->key(hash('sha256', serialize($item))),
                $apaParts->ttl(),
                fn () => $this->renderer->formatApaParts($item),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $text = implode('', array_column($parts, 'text'));
        $hasSource = ($item['container-title'] ?? '') !== '' || ($item['archive'] ?? '') !== '' || ($item['event-title'] ?? '') !== '';

        return $text === '' ? null : new RelatedWorkCitation($text, $parts, $kind, $hasSource, $doi);
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function cslItem(string $doi, array $metadata, string $kind): array
    {
        $item = ['id' => $doi, 'DOI' => $doi, 'URL' => 'https://doi.org/'.$doi, 'title' => $this->value($metadata['title'] ?? null)];
        foreach (['author', 'editor', 'collection-editor'] as $role) {
            $names = [];
            foreach ((array) ($metadata[$role] ?? []) as $name) {
                if (! is_array($name)) {
                    continue;
                }
                $fields = array_filter(array_intersect_key($name, array_flip(['family', 'given', 'literal', 'suffix', 'non-dropping-particle'])), 'is_string');
                if ($fields !== []) {
                    $names[] = $fields;
                }
            }
            if ($names !== []) {
                $item[$role] = $names;
            }
        }
        foreach (['issued', 'published', 'created'] as $key) {
            $date = data_get($metadata, $key.'.date-parts.0');
            if (is_array($date) && is_numeric($date[0] ?? null)) {
                $item['issued'] = ['date-parts' => [array_map('intval', array_slice($date, 0, 3))]];
                break;
            }
        }

        if ($kind === 'Preprint') {
            $item['type'] = 'article';
            $archive = $this->value($metadata['archive'] ?? null)
                ?: $this->value($metadata['repository'] ?? null)
                ?: $this->value(data_get($metadata, 'institution.0.name'));
            if ($archive === '') {
                foreach (['container-title', 'publisher'] as $key) {
                    $name = $this->value($metadata[$key] ?? null);
                    if (in_array(strtolower($name), ['arxiv', 'biorxiv', 'medrxiv', 'psyarxiv', 'zenodo', 'socarxiv', 'eartharxiv', 'osf preprints'], true)) {
                        $archive = $name;
                        break;
                    }
                }
            }
            $item['archive'] = $archive;

            return $item;
        }

        foreach (['container-title', 'volume', 'issue', 'page'] as $key) {
            $value = $this->value($metadata[$key] ?? null);
            if ($value !== '') {
                $item[$key] = $value;
            }
        }
        $item['type'] = 'article-journal';
        if ($kind === 'Conference paper') {
            if (! empty($metadata['ISBN']) || (isset($item['editor']) && empty($metadata['ISSN']))) {
                $item['type'] = 'chapter';
                $item['publisher'] = $this->value($metadata['publisher'] ?? null);
            } elseif (empty($metadata['ISSN'])) {
                $item['type'] = 'paper-conference';
                $item['event-title'] = $this->value($metadata['event-title'] ?? data_get($metadata, 'event.name') ?? $metadata['event'] ?? null);
                $item['event-place'] = $this->value($metadata['event-place'] ?? data_get($metadata, 'event.location'));
            }
        }

        $number = $this->value($metadata['article-number'] ?? $metadata['number'] ?? null);
        if ($number !== '') {
            $item['number'] = $number;
        }
        if (isset($item['page'])) {
            $item['page'] = preg_replace('/(?<=\d)-(?=\d)/u', '–', $item['page']);
        }

        return $item;
    }

    private function value(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_scalar($entry) && trim((string) $entry) !== '') {
                    return trim((string) $entry);
                }
            }

            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Generic CSL article types also cover working papers. Consult the registering agency for an explicit type.
     * @return array<string, mixed>
     */
    private function nativeMetadata(string $doi, float $deadline): array
    {
        $nativePaper = CacheKey::CITATION_NATIVE_PAPER;
        $cache = $nativePaper->repository();
        $key = $nativePaper->key(hash('sha256', $doi));
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.1) {
            return [];
        }
        $work = $this->crossref->lookupWork($doi, $remaining);
        $responseDoi = $work->data['DOI'] ?? null;
        if ($work->data !== null && is_string($responseDoi) && strtolower($responseDoi) === $doi) {
            $fields = array_intersect_key($work->data, array_flip(['type', 'subtype', 'institution']));
        } elseif (! $work->found && $work->error === null && ($remaining = $deadline - microtime(true)) >= 0.1) {
            $attrs = $this->dataCite->getDataCiteMetadata($doi, $remaining, false);
            if (! is_string($attrs['doi'] ?? null) || strtolower($attrs['doi']) !== $doi) {
                return [];
            }
            $general = $this->value(data_get($attrs, 'types.resourceTypeGeneral'));
            $fields = ['type' => strtolower($general) === 'text' ? data_get($attrs, 'types.resourceType') : $general];
        } else {
            return [];
        }

        $cache->put($key, $fields, $nativePaper->ttl());

        return $fields;
    }
}
