<?php

declare(strict_types=1);

use App\Services\GemetApiService;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Helpers\NativeSleep;
use Tests\Support\GemetUpdateCheckClock;
use Tests\Support\GemetUpdateCheckFixture as Gemet;

covers(GemetApiService::class);

dataset('GEMET check phases', [
    'supergroups' => 'http://www.eionet.europa.eu/gemet/supergroup/',
    'groups' => Gemet::GROUP,
    'members' => Gemet::GROUP.'96',
]);

beforeEach(function (): void {
    $this->gemet = new GemetUpdateCheckClock;
    Http::preventStrayRequests();
});

it('retries only transient failures in every check phase', function (string $phase, ?int $status): void {
    $attempts = 0;
    $total = 0;
    Http::fake(function (Request $request) use ($phase, $status, &$attempts, &$total) {
        $total++;
        $target = $request['concept_uri'] ?? $request['thesaurus_uri'];
        if ($target === $phase && ++$attempts === 1) {
            if ($status === null) {
                throw new ConnectionException('cURL error 28');
            }

            return Http::response('', $status);
        }

        return Gemet::response($request);
    });

    expect($this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toBe(5)
        ->and($this->gemet->waits)->toBe([0.5])
        ->and($attempts)->toBe(2)
        ->and($total)->toBe(5);
})->with('GEMET check phases')->with([null, 408, 429, 500, 502, 503, 504]);

it('stops after two failed attempts and records the technical cause', function (string $phase): void {
    Log::spy();
    $attempts = 0;
    Http::fake(function (Request $request) use ($phase, &$attempts) {
        if (($request['concept_uri'] ?? $request['thesaurus_uri']) === $phase) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds');
        }

        return Gemet::response($request, 9);
    });

    try {
        $this->gemet->fetchHierarchyNodeCountForUpdateCheck();
        $this->fail('Expected an unavailable GEMET error.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('GEMET could not be reached. Please try again later.')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }

    expect($attempts)->toBe(2)->and($this->gemet->waits)->toBe([0.5]);
    Log::shouldHaveReceived('warning')->once()->with('GEMET update check failed.', Mockery::on(
        fn (array $context): bool => $context['attempts'] === 2
            && $context['exception'] instanceof ConnectionException
            && $context['uri'] === $phase
            && $context['reason'] === 'unavailable',
    ));
    Http::assertNotSent(fn (Request $request): bool => ($request['concept_uri'] ?? null) === Gemet::GROUP.'100');
})->with('GEMET check phases');

it('does not retry permanent HTTP errors', function (int $status): void {
    Http::fake(fn () => Http::response('', $status));

    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(RuntimeException::class, 'GEMET could not be reached')
        ->and($this->gemet->waits)->toBeEmpty();
    Http::assertSentCount(1);
})->with([301, 400, 401, 403, 404, 422]);

it('respects provider retry delays', function (string $header, float $delay): void {
    $this->freezeTime();
    $header = $header === 'future' ? now()->addSeconds(3)->format(DATE_RFC7231) : $header;
    $attempts = 0;
    Http::fake(function (Request $request) use ($header, &$attempts) {
        if (++$attempts === 1) {
            return Http::response('', 503, ['Retry-After' => $header]);
        }

        return Gemet::response($request);
    });

    expect($this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toBe(5)
        ->and($this->gemet->waits)->toBe([$delay]);
})->with(['seconds' => ['2', 2.0], 'date' => ['future', 3.0], 'invalid' => ['bad header', 0.5], 'past' => ['Thu, 01 Jan 1970 00:00:00 GMT', 0.5]]);

it('uses the longest retry delay of the failed requests in a pool', function (): void {
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        $uri = $request['concept_uri'] ?? null;
        if ($uri !== null && ($calls[$uri] = ($calls[$uri] ?? 0) + 1) === 1) {
            return Http::response('', 429, ['Retry-After' => str_ends_with($uri, '96') ? '1' : '3']);
        }

        return Gemet::response($request);
    });

    expect($this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toBe(5)
        ->and($this->gemet->waits)->toBe([3.0]);
});

it('does not retry before Retry-After when the provider delay exceeds the budget', function (): void {
    Http::fake(fn () => Http::response('', 429, ['Retry-After' => '90']));

    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(RuntimeException::class, 'exceeded its time limit')
        ->and($this->gemet->waits)->toBeEmpty();
    Http::assertSentCount(1);
});

it('rejects malformed payloads without retrying or returning a partial count', function (string $body, string $phase): void {
    Http::fake(function (Request $request) use ($body, $phase) {
        if (($request['concept_uri'] ?? $request['thesaurus_uri']) === $phase) {
            return Http::response($body, 200);
        }

        return Gemet::response($request);
    });

    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(RuntimeException::class, 'invalid response')
        ->and($this->gemet->waits)->toBeEmpty();
})->with(['html' => '<html>Error</html>', 'null' => 'null', 'scalar' => '42', 'empty object' => '{}', 'error object' => '{"error":"unavailable"}', 'numeric object' => '{"0":{"uri":"valid"}}', 'missing uri' => '[{"label":"broken"}]', 'empty uri' => '[{"uri":" "}]', 'invalid uri type' => '[{"uri":17}]', 'mixed list' => '[{"uri":"valid"},false]', 'broken json' => '['])->with('GEMET check phases');

it('accepts empty membership lists and single entities', function (): void {
    Http::fake(function (Request $request) {
        if (isset($request['concept_uri'])) {
            return Http::response($request['concept_uri'] === Gemet::GROUP.'96' ? '[]' : json_encode(Gemet::entity('concept/1')));
        }
        if (str_contains($request['thesaurus_uri'], 'supergroup')) {
            return Http::response(Gemet::entity('supergroup/1'));
        }

        return Gemet::response($request);
    });

    expect($this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toBe(4);
});

it('rejects an empty navigation vocabulary', function (string $phase): void {
    Http::fake(fn (Request $request) => $request['thesaurus_uri'] === $phase ? Http::response([]) : Gemet::response($request));
    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(RuntimeException::class, 'invalid response');
})->with(['http://www.eionet.europa.eu/gemet/supergroup/', Gemet::GROUP]);

it('propagates programming errors instead of reporting an external outage', function (): void {
    Http::fake(fn () => throw new LogicException('broken transport callback'));
    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(LogicException::class, 'broken transport callback');
});

it('reduces request and connect timeouts to the remaining budget after a retry', function (string $phase): void {
    $options = [];
    $calls = 0;
    Http::fake(function (Request $request, array $requestOptions) use ($phase, &$options, &$calls) {
        if (($request['concept_uri'] ?? $request['thesaurus_uri']) === $phase) {
            $options[] = [$requestOptions['timeout'], $requestOptions['connect_timeout']];
            if (++$calls === 1) {
                $this->gemet->time = 84;
                throw new ConnectionException('timeout');
            }
        }

        return Gemet::response($request);
    });

    expect($this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toBe(5)
        ->and($options[0])->toBe([30.0, 10])
        ->and($options[1])->toBe([5.5, 5.5]);
})->with('GEMET check phases');

it('recomputes the timeouts before starting the next member pool', function (): void {
    $options = [];
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        if (isset($request['concept_uri'])) {
            $options[$request['concept_uri']] = [$requestOptions['timeout'], $requestOptions['connect_timeout']];
        }
        if (($request['concept_uri'] ?? null) === Gemet::GROUP.'96') {
            $this->gemet->time = 84;
        }

        return Gemet::response($request, 9);
    });

    expect($this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toBe(19)
        ->and($options[Gemet::GROUP.'96'])->toBe([30.0, 10])
        ->and($options[Gemet::GROUP.'100'])->toBe([6.0, 6.0]);
});

it('shares the deadline between navigation requests and successive member pools', function (string $phase): void {
    Http::fake(function (Request $request) use ($phase) {
        if (($request['concept_uri'] ?? $request['thesaurus_uri']) === $phase) {
            $this->gemet->time = 90;
        }

        return Gemet::response($request, 9);
    });

    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(RuntimeException::class, 'exceeded its time limit');
    Http::assertNotSent(fn (Request $request): bool => ($request['concept_uri'] ?? null) === Gemet::GROUP.'100');
})->with('GEMET check phases');

it('checks the deadline again after waiting for a retry', function (): void {
    $this->gemet->oversleep = 90;
    Http::fake(fn () => Http::response('', 503));
    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(RuntimeException::class, 'exceeded its time limit');
    Http::assertSentCount(1);
});

it('never sends a sub-millisecond timeout that cURL could round to unlimited', function (): void {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        if (++$calls === 1) {
            $this->gemet->time = 89.9999;
        }

        return Gemet::response($request);
    });
    expect(fn () => $this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toThrow(RuntimeException::class, 'exceeded its time limit');
    Http::assertSentCount(1);
});

it('keeps at most four member requests active and handles out-of-order completion', function (): void {
    $active = [];
    $maximum = 0;
    $completed = [];
    // Laravel's Http::fake waits for promises while collecting transfer stats.
    // Intercept at the transport middleware to preserve real pending work.
    Http::globalMiddleware(function () use (&$active, &$maximum, &$completed): Closure {
        return function ($psrRequest, array $options) use (&$active, &$maximum, &$completed) {
            $request = (new Request($psrRequest))->withData($options['laravel_data']);
            if (! isset($request['concept_uri'])) {
                return Gemet::response($request, 9);
            }

            $uri = $request['concept_uri'];
            $promise = new Promise(function () use (&$active, &$completed): void {
                // Resolve in reverse order, while keeping the transport pending
                // until Guzzle waits. This measures active work, not request count.
                foreach (array_reverse($active, true) as $key => $pending) {
                    unset($active[$key]);
                    $completed[] = $key;
                    $pending->resolve(new PsrResponse(200, [], json_encode([Gemet::entity('concept/1')])));
                }
            });
            $active[$uri] = $promise;
            $maximum = max($maximum, count($active));

            return $promise;
        };
    });

    expect($this->gemet->fetchHierarchyNodeCountForUpdateCheck())->toBe(19)
        ->and($maximum)->toBe(4)
        ->and($active)->toBeEmpty()
        ->and($completed)->toHaveCount(9)
        ->and($completed[0])->toBe(Gemet::GROUP.'99');
});

it('uses the production clock and retry wait without sleeping in the test', function (): void {
    NativeSleep::fake();
    $calls = 0;
    Http::fake(function (Request $request, array $options) use (&$calls) {
        expect($request->hasHeader('Accept', 'application/json'))->toBeTrue()
            ->and($request['language'])->toBe('en')
            ->and($options['allow_redirects'])->toBeFalse();
        if (++$calls === 1) {
            throw new ConnectionException('timeout');
        }

        return Gemet::response($request);
    });
    try {
        expect((new GemetApiService)->fetchHierarchyNodeCountForUpdateCheck())->toBe(5)
            ->and(NativeSleep::microseconds())->toBe([500000]);
    } finally {
        NativeSleep::restore();
    }
});
