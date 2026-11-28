<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use Illuminate\Support\Carbon;
use Throwable;

final class AwardEvidenceEvaluator
{
    /** @param list<AwardSourceResult> $results */
    public function evaluate(string $number, ?string $title, FunderIdentity $funder, array $results): AwardMatch
    {
        $candidates = [];
        foreach ($results as $result) {
            if ($result->error !== null) {
                return new AwardMatch(reason: $result->error);
            }
            foreach ($result->candidates as $candidate) {
                if ($candidate->awardNumber !== $number) {
                    continue;
                }
                if (($reason = $this->rejectReason($candidate, $title, $funder)) !== null) {
                    return new AwardMatch(reason: $reason);
                }
                $candidates[$candidate->uri] = $candidate;
            }
        }
        if ($candidates === []) {
            return new AwardMatch;
        }
        ksort($candidates);
        $values = array_values($candidates);
        if (count($values) === 1) {
            return new AwardMatch($values[0], $values, 'matched');
        }
        if (count($values) === 2 && $values[0]->provider !== $values[1]->provider) {
            [$first, $second] = $values;
            if (in_array($first->uri, $second->aliases, true) || in_array($second->uri, $first->aliases, true)) {
                return new AwardMatch($first->provider === 'crossref' ? $first : $second, $values, 'matched');
            }
        }

        return new AwardMatch(reason: 'ambiguous_award');
    }

    private function rejectReason(AwardCandidate $candidate, ?string $title, FunderIdentity $funder): ?string
    {
        if (AwardMetadata::uri($candidate->uri) !== $candidate->uri || ! in_array($candidate->provider, ['crossref', 'cordis'], true)) {
            return 'invalid_award_uri';
        }
        if ($candidate->provider === 'crossref') {
            if (! str_starts_with($candidate->uri, 'https://doi.org/') || $candidate->sourceUrl !== 'https://api.crossref.org/works/'.rawurlencode($candidate->recordId)
                || AwardMetadata::uri('https://doi.org/'.$candidate->recordId) !== $candidate->uri) {
                return 'invalid_award_uri';
            }
            if (array_intersect($funder->ids, $candidate->funderIds) === []) {
                return 'funder_conflict';
            }
        } else {
            if ($candidate->uri !== 'https://cordis.europa.eu/project/id/'.$candidate->awardNumber || $candidate->sourceUrl !== $candidate->uri) {
                return 'invalid_award_uri';
            }
            $allowed = $funder->cordisProgrammes();
            if (! array_any($candidate->programmes, static fn (string $code): bool => array_any($allowed,
                static fn (string $prefix): bool => str_starts_with($code, $prefix) || $code === rtrim($prefix, '.')))) {
                return 'insufficient_programme_evidence';
            }
        }
        if (AwardMetadata::title($title) !== '' && ! in_array(AwardMetadata::title($title), array_map(AwardMetadata::title(...), $candidate->titles), true)) {
            return 'award_title_conflict';
        }
        try {
            $fetched = Carbon::parse($candidate->fetchedAt);
            if ($candidate->fetchedAt === '' || $fetched->isFuture() || $fetched->lt(now()->subDays((int) config('funding_award_uri.evidence_days', 7)))) {
                return 'evidence_expired';
            }
        } catch (Throwable) {
            return 'invalid_evidence';
        }

        return null;
    }

    public function restore(mixed $evidence): AwardSourceResult
    {
        if (! is_array($evidence) || ! array_is_list($evidence) || $evidence === []) {
            return new AwardSourceResult(error: 'invalid_evidence');
        }
        $candidates = [];
        foreach ($evidence as $item) {
            if (! is_array($item)) {
                return new AwardSourceResult(error: 'invalid_evidence');
            }
            foreach (['provider', 'record_id', 'award_number', 'award_uri', 'fetched_at', 'source_url'] as $key) {
                if (! is_string($item[$key] ?? null)) {
                    return new AwardSourceResult(error: 'invalid_evidence');
                }
            }
            foreach (['funder_ids', 'titles', 'programmes', 'aliases'] as $key) {
                if (! is_array($item[$key] ?? null) || ! array_is_list($item[$key]) || ! array_all($item[$key], static fn (mixed $value): bool => is_string($value))) {
                    return new AwardSourceResult(error: 'invalid_evidence');
                }
            }
            $candidates[] = new AwardCandidate($item['provider'], $item['record_id'], $item['award_number'], $item['award_uri'],
                $item['funder_ids'], $item['titles'], $item['programmes'], $item['fetched_at'], $item['source_url'], $item['aliases'],
                is_string($item['source_updated_at'] ?? null) ? $item['source_updated_at'] : null);
        }

        return new AwardSourceResult($candidates);
    }
}
