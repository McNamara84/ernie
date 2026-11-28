<?php

declare(strict_types=1);

use App\Services\FundingAwardUri\AwardCandidate;
use App\Services\FundingAwardUri\AwardEvidenceEvaluator;
use App\Services\FundingAwardUri\AwardSourceResult;
use App\Services\FundingAwardUri\FunderIdentity;

function awardCandidate(array $overrides = []): AwardCandidate
{
    return new AwardCandidate(...[...[
        'provider' => 'crossref', 'recordId' => '10.35802/218300', 'awardNumber' => '218300', 'uri' => 'https://doi.org/10.35802/218300',
        'funderIds' => ['10.13039/501100000780'], 'titles' => ['Research award'], 'programmes' => [],
        'fetchedAt' => now()->toIso8601String(), 'sourceUrl' => 'https://api.crossref.org/works/10.35802%2F218300',
    ], ...$overrides]);
}

it('requires exact funder, award, title, canonical URI and recent evidence', function (array $overrides, ?string $title, string $reason): void {
    $candidate = awardCandidate($overrides);
    $match = app(AwardEvidenceEvaluator::class)->evaluate('218300', $title, new FunderIdentity('10.13039/501100000780', ['10.13039/501100000780']), [new AwardSourceResult([$candidate])]);
    expect($match->reason)->toBe($reason)->and($match->candidate !== null)->toBe($reason === 'matched');
})->with([
    [[], '  RESEARCH   award ', 'matched'], [[], null, 'matched'], [[], 'Other award', 'award_title_conflict'],
    [['awardNumber' => '0218300'], null, 'no_match'], [['funderIds' => ['10.13039/123']], null, 'funder_conflict'],
    [['uri' => 'https://example.org/award'], null, 'invalid_award_uri'], [['provider' => 'unknown'], null, 'invalid_award_uri'],
    [['sourceUrl' => 'https://example.org'], null, 'invalid_award_uri'], [['fetchedAt' => '2020-01-01'], null, 'evidence_expired'],
    [['fetchedAt' => '2099-01-01'], null, 'evidence_expired'], [['fetchedAt' => 'invalid date'], null, 'invalid_evidence'],
]);

it('merges two provider URIs only when the source explicitly links the same award', function (bool $linked): void {
    $cordisUri = 'https://cordis.europa.eu/project/id/218300';
    $first = awardCandidate(['aliases' => $linked ? [$cordisUri] : []]);
    $second = awardCandidate(['provider' => 'cordis', 'uri' => $cordisUri, 'sourceUrl' => $cordisUri, 'programmes' => ['HORIZON.2.6']]);
    $match = app(AwardEvidenceEvaluator::class)->evaluate('218300', null, new FunderIdentity('10.13039/501100000780', ['10.13039/501100000780']), [new AwardSourceResult([$first, $second])]);
    expect($match->candidate?->uri)->toBe($linked ? $first->uri : null)->and($match->reason)->toBe($linked ? 'matched' : 'ambiguous_award');
})->with([true, false]);

it('does not let a matching title or a second good source override a conflict or outage', function (): void {
    $candidate = awardCandidate();
    $evaluator = app(AwardEvidenceEvaluator::class);
    $funder = new FunderIdentity('10.13039/501100000780', ['10.13039/501100000780']);
    expect($evaluator->evaluate('218300', null, $funder, [new AwardSourceResult([$candidate]), new AwardSourceResult(error: 'source_unavailable')])->candidate)->toBeNull()
        ->and($evaluator->evaluate('218300', null, $funder, [new AwardSourceResult([$candidate, awardCandidate(['funderIds' => []])])])->reason)->toBe('funder_conflict');
});

it('rejects incomplete persisted evidence', function (mixed $input): void {
    expect(app(AwardEvidenceEvaluator::class)->restore($input)->error)->toBe('invalid_evidence');
})->with([[null], [[]], [[['provider' => 'crossref']]], [['not an object']]]);

it('rejects malformed source lists and mismatched CORDIS programme evidence', function (): void {
    $evaluator = app(AwardEvidenceEvaluator::class);
    expect($evaluator->restore([[...awardCandidate()->evidence(), 'funder_ids' => ['wrong' => 'shape']]])->error)->toBe('invalid_evidence');
    $funder = new FunderIdentity('10.13039/100010661', ['10.13039/100010661']);
    $uri = 'https://cordis.europa.eu/project/id/218300';
    $candidate = awardCandidate(['provider' => 'cordis', 'uri' => $uri, 'sourceUrl' => $uri, 'programmes' => ['HORIZON.2.6']]);
    expect($evaluator->evaluate('218300', null, $funder, [new AwardSourceResult([$candidate])])->reason)->toBe('insufficient_programme_evidence');
    $candidate = awardCandidate(['provider' => 'cordis', 'uri' => $uri, 'sourceUrl' => 'https://example.org']);
    expect($evaluator->evaluate('218300', null, $funder, [new AwardSourceResult([$candidate])])->reason)->toBe('invalid_award_uri');
});
