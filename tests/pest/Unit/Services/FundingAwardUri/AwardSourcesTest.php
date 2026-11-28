<?php

declare(strict_types=1);

use App\Services\FundingAwardUri\AwardEvidenceEvaluator;
use App\Services\FundingAwardUri\AwardHttpClient;
use App\Services\FundingAwardUri\AwardMetadata;
use App\Services\FundingAwardUri\CordisAwardSource;
use App\Services\FundingAwardUri\CrossrefGrantSource;
use App\Services\FundingAwardUri\FunderIdentity;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FundingAwardUriFixtures as F;

beforeEach(function (): void {
    F::fake();
    Sleep::fake();
});

it('queries registered grants with exact award and funder filters and caches only reduced evidence', function (): void {
    $source = app(CrossrefGrantSource::class);
    $funder = new FunderIdentity('10.13039/100010269', ['10.13039/100010269']);
    $first = $source->lookup('218300', $funder);
    $this->travel(1)->hours();
    $second = $source->lookup('218300', $funder);
    expect($first->error)->toBeNull()->and($second->candidates[0]->fetchedAt)->toBe($first->candidates[0]->fetchedAt)
        ->and($first->candidates[0]->uri)->toBe(F::URI);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['filter'] === 'type:grant,award.number:218300,award.funder:10.13039/100010269');
});

it('does not treat a publication DOI as a grant DOI', function (): void {
    F::fake([[...F::grant(), 'type' => 'journal-article']]);
    expect(app(CrossrefGrantSource::class)->lookup('218300', new FunderIdentity('10.13039/100010269', []))->candidates)->toBe([]);
});

it('rejects incomplete Crossref data', function (array $response, string $reason): void {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response($response)]);
    $result = app(CrossrefGrantSource::class)->lookup('218300', new FunderIdentity('10.13039/100010269', []));
    expect($result->error)->toBe($reason);
})->with([
    [[], 'invalid_source_response'],
    [['message' => ['total-results' => 301, 'items' => []]], 'query_incomplete'],
    [['message' => ['total-results' => 1, 'items' => []]], 'query_incomplete'],
    [['message' => ['total-results' => 1, 'items' => [['type' => 'grant', 'award' => '218300']]]], 'incomplete_grant_record'],
]);

it('paginates Crossref until uniqueness can be evaluated', function (): void {
    Http::swap(new Factory);
    Http::fakeSequence()->push(['message' => ['total-results' => 2, 'items' => [F::grant()]]])
        ->push(['message' => ['total-results' => 2, 'items' => [F::grant(doi: '10.35802/other')]]]);
    $result = app(CrossrefGrantSource::class)->lookup('218300', new FunderIdentity('10.13039/100010269', []));
    expect($result->candidates)->toHaveCount(2);
    Http::assertSent(fn ($request) => $request['offset'] === 1);
});

it('requires a unique CORDIS project and a verified programme', function (string $scenario, ?string $error): void {
    Http::swap(new Factory);
    $project = ['project' => ['type' => 'uri', 'value' => 'http://data.europa.eu/s66/resource/projects/1234-abcd']];
    $row = ['id' => ['value' => '101059547'], 'title' => ['value' => 'Research award'], 'grant' => ['value' => 'grant-123'],
        'programme' => ['value' => 'programme-123'], 'code' => ['value' => 'HORIZON.2.6.4']];
    if ($scenario === 'no programme') {
        unset($row['code']);
    }
    if ($scenario === 'wrong number') {
        $row['id']['value'] = '101059548';
    }
    Http::fakeSequence()->push(['results' => ['bindings' => $scenario === 'ambiguous' ? [$project, $project] : [$project]]])
        ->push(['results' => ['bindings' => [$row]]]);
    $funder = new FunderIdentity('10.13039/501100000780', ['10.13039/501100000780']);
    $result = app(CordisAwardSource::class)->lookup('101059547', $funder);
    expect($result->error)->toBe($error);
    if ($error === null) {
        expect(app(AwardEvidenceEvaluator::class)->evaluate('101059547', null, $funder, [$result])->candidate?->uri)
            ->toBe('https://cordis.europa.eu/project/id/101059547');
        Http::assertSentCount(2);
    }
})->with([['valid', null], ['ambiguous', 'ambiguous_award'], ['no programme', 'insufficient_programme_evidence'], ['wrong number', 'award_number_mismatch']]);

it('does not query CORDIS for unknown funders or nonnumeric awards', function (): void {
    $source = app(CordisAwardSource::class);
    expect($source->lookup('123', new FunderIdentity('10.13039/123', []))->candidates)->toBe([])
        ->and($source->lookup('ABC-123', new FunderIdentity('10.13039/501100000780', []))->candidates)->toBe([]);
    Http::assertNothingSent();
});

it('bounds retries and does not negative-cache a source outage', function (): void {
    Http::swap(new Factory);
    Http::fakeSequence()->push([], 503)->push([], 503)->push([], 503)
        ->push(['message' => ['total-results' => 0, 'items' => []]]);
    $source = app(CrossrefGrantSource::class);
    $funder = new FunderIdentity('10.13039/100010269', []);
    expect($source->lookup('218300', $funder)->error)->toBe('source_unavailable')
        ->and($source->lookup('218300', $funder)->error)->toBeNull();
    Http::assertSentCount(4);
});

it('honors a long Retry-After without delaying the job indefinitely', function (): void {
    Http::swap(new Factory);
    Http::fakeSequence()->push([], 429, ['Retry-After' => '3600']);
    expect(fn () => app(AwardHttpClient::class)->get('crossref', []))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
    expect(Cache::get('award-uri:next-request:crossref'))->toBeGreaterThan(microtime(true) + 3500);
});

it('rejects redirects and malformed JSON without retrying', function (int $status, string $body): void {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response($body, $status, ['Location' => 'https://untrusted.example'])]);
    expect(fn () => app(AwardHttpClient::class)->get('crossref', []))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([[302, ''], [200, '<html>unavailable</html>'], [403, '{}']]);

it('rejects unusable award numbers and preserves significant characters', function (mixed $input, ?string $expected): void {
    expect(AwardMetadata::number($input))->toBe($expected);
})->with([[null, null], ['', null], ['n/a', null], ['12,34', null], ['123 456', null], [' 0012-A/3 ', '0012-A/3'], ['00123', '00123']]);

it('validates award URI hosts and canonicalizes DOI casing', function (string $input, ?string $expected): void {
    expect(AwardMetadata::uri($input))->toBe($expected);
})->with([
    ['https://doi.org/10.35802/ABC', 'https://doi.org/10.35802/abc'],
    ['https://cordis.europa.eu/project/id/123', 'https://cordis.europa.eu/project/id/123'],
    ['http://doi.org/10.35802/abc', null], ['https://doi.org.evil/10.35802/abc', null],
    ['javascript:alert(1)', null], ['https://cordis.europa.eu/project/id/123?x=1', null],
    ['https://doi.org/10.35802/'.str_repeat('a', 512), null],
]);

it('handles transport failures and a short shared cooldown', function (): void {
    Http::swap(new Factory);
    Http::fake(['*' => Http::failedConnection()]);
    Cache::put('award-uri:next-request:crossref', microtime(true) + 1, 60);
    expect(fn () => app(AwardHttpClient::class)->get('crossref', []))->toThrow(RuntimeException::class);
    Sleep::assertSleptTimes(3);
    expect(fn () => app(AwardHttpClient::class)->get('unsupported', []))->toThrow(RuntimeException::class, 'Unsupported award source.');
});

it('rejects malformed CORDIS responses instead of treating them as no matches', function (array $response): void {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response($response)]);
    expect(app(CordisAwardSource::class)->lookup('123', new FunderIdentity('10.13039/501100000780', []))->error)->toBe('invalid_source_response');
})->with([
    [[]], [['results' => ['bindings' => [123]]]], [['results' => ['bindings' => [['project' => 'not a binding']]]]],
    [['results' => ['bindings' => [['project' => ['value' => 'https://untrusted.example/project']]]]]],
]);

it('rejects a truncated CORDIS detail query', function (): void {
    Http::swap(new Factory);
    Http::fakeSequence()->push(['results' => ['bindings' => [['project' => ['value' => 'http://data.europa.eu/s66/resource/projects/1234']]]]])
        ->push(['results' => ['bindings' => []]]);
    expect(app(CordisAwardSource::class)->lookup('123', new FunderIdentity('10.13039/501100000780', []))->error)->toBe('query_incomplete');
});

it('keeps explicit Crossref links and ROR identities but ignores unrelated raw metadata', function (): void {
    $grant = F::grant();
    $grant['project'][0]['funding'][] = ['funder' => ['id' => [['id' => 'https://ror.org/029chgv08'], 'malformed']]];
    $grant['project'][0]['funding'][] = 'malformed';
    $grant['resource']['primary']['URL'] = 'https://cordis.europa.eu/project/id/218300';
    $grant['abstract'] = 'An unrelated abstract';
    F::fake([$grant]);
    $candidate = app(CrossrefGrantSource::class)->lookup('218300', new FunderIdentity('10.13039/100010269', []))->candidates[0];
    expect($candidate->funderIds)->toContain('https://ror.org/029chgv08')
        ->and($candidate->aliases)->toBe(['https://cordis.europa.eu/project/id/218300'])
        ->and($candidate->evidence())->not->toHaveKey('abstract');
});

it('rejects malformed grant projects and changing pagination totals', function (bool $malformedProject): void {
    Http::swap(new Factory);
    $grant = F::grant();
    if ($malformedProject) {
        $grant['project'] = ['invalid'];
    }
    Http::fakeSequence()->push(['message' => ['total-results' => 2, 'items' => [$grant]]])
        ->push(['message' => ['total-results' => 3, 'items' => [F::grant()]]]);
    expect(app(CrossrefGrantSource::class)->lookup('218300', new FunderIdentity('10.13039/100010269', []))->error)
        ->toBe($malformedProject ? 'incomplete_grant_record' : 'query_incomplete');
})->with([true, false]);
