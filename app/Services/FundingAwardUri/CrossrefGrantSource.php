<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

final class CrossrefGrantSource extends CachedAwardSource
{
    public function lookup(string $awardNumber, FunderIdentity $funder): AwardSourceResult
    {
        return $this->cached('crossref', $awardNumber, $funder, function () use ($awardNumber, $funder): AwardSourceResult {
            $limit = max(1, min(300, (int) config('funding_award_uri.max_candidates', 300)));
            $candidates = [];
            $offset = 0;
            $expectedTotal = null;
            do {
                $data = $this->http->get('crossref', [
                    'filter' => 'type:grant,award.number:'.$awardNumber.',award.funder:'.$funder->crossrefId,
                    'rows' => min(100, $limit), 'offset' => $offset,
                ]);
                $message = $data['message'] ?? null;
                if (! is_array($message) || ! is_int($message['total-results'] ?? null) || ! is_array($message['items'] ?? null)
                    || ! array_is_list($message['items'])) {
                    return new AwardSourceResult(error: 'invalid_source_response');
                }
                $total = $message['total-results'];
                if ($total < 0 || $total > $limit || ($expectedTotal !== null && $total !== $expectedTotal)
                    || $offset + count($message['items']) > $total || ($total > $offset && $message['items'] === [])) {
                    return new AwardSourceResult(error: 'query_incomplete');
                }
                $expectedTotal = $total;
                foreach ($message['items'] as $item) {
                    if (! is_array($item) || ($item['type'] ?? null) !== 'grant') {
                        continue;
                    }
                    $doi = AwardMetadata::text($item['DOI'] ?? null);
                    $uri = AwardMetadata::uri('https://doi.org/'.$doi);
                    $number = AwardMetadata::number($item['award'] ?? null);
                    if ($uri === null || $number === null || ! is_array($item['project'] ?? null)) {
                        return new AwardSourceResult(error: 'incomplete_grant_record');
                    }
                    $ids = [];
                    $titles = [];
                    foreach ($item['project'] as $project) {
                        if (! is_array($project)) {
                            return new AwardSourceResult(error: 'incomplete_grant_record');
                        }
                        foreach ($project['project-title'] ?? [] as $title) {
                            if (is_array($title) && ($text = AwardMetadata::text($title['title'] ?? null)) !== '') {
                                $titles[] = $text;
                            }
                        }
                        foreach ($project['funding'] ?? [] as $funding) {
                            if (! is_array($funding)) {
                                continue;
                            }
                            foreach ($funding['funder']['id'] ?? [] as $id) {
                                if (! is_array($id)) {
                                    continue;
                                }
                                $value = AwardMetadata::text($id['id'] ?? null);
                                if (preg_match('~^(?:https://doi\.org/)?(10\.13039/[0-9]+)$~D', $value, $matches)) {
                                    $ids[] = $matches[1];
                                } elseif (preg_match('~^https://ror\.org/0[a-z0-9]{6}[0-9]{2}$~D', $value)) {
                                    $ids[] = $value;
                                }
                            }
                        }
                    }
                    $aliases = [];
                    $primaryUrl = $item['resource']['primary']['URL'] ?? null;
                    if (($alias = AwardMetadata::uri($primaryUrl)) !== null && $alias !== $uri) {
                        $aliases[] = $alias;
                    }
                    $candidates[$uri] = new AwardCandidate('crossref', $doi, $number, $uri,
                        array_values(array_unique($ids)), array_values(array_unique($titles)), [], now()->toIso8601String(),
                        'https://api.crossref.org/works/'.rawurlencode($doi), $aliases,
                        is_string($item['indexed']['date-time'] ?? null) ? $item['indexed']['date-time'] : null);
                }
                $offset += count($message['items']);
            } while ($offset < $total);

            return new AwardSourceResult(array_values($candidates));
        });
    }
}
