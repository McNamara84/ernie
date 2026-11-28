<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

final readonly class AwardHttpClient
{
    public function __construct(private Factory $http) {}

    /** @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    public function get(string $provider, array $query): array
    {
        $url = match ($provider) {
            'crossref' => 'https://api.crossref.org/works',
            'cordis' => 'https://cordis.europa.eu/datalab/sparql',
            default => throw new RuntimeException('Unsupported award source.'),
        };
        $attempts = max(1, min(3, (int) config('funding_award_uri.attempts', 3)));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $retry = false;
            try {
                $response = Cache::lock('award-uri:http:'.$provider, 90)->block(30, function () use ($provider, $query, $url) {
                    $key = 'award-uri:next-request:'.$provider;
                    $wait = max(0, (float) Cache::get($key, 0) - microtime(true));
                    if ($wait > (int) config('funding_award_uri.max_wait_seconds', 30)) {
                        throw new RuntimeException('Award source is cooling down.');
                    }
                    if ($wait > 0) {
                        Sleep::usleep((int) ceil($wait * 1000000));
                    }
                    $interval = max(0, (int) config('funding_award_uri.request_interval_ms', 1000)) / 1000;
                    Cache::put($key, microtime(true) + $interval, 3600);
                    $response = $this->http->connectTimeout((int) config('funding_award_uri.connect_timeout', 5))
                        ->timeout((int) config('funding_award_uri.timeout', 20))
                        ->withOptions(['allow_redirects' => false])
                        ->withUserAgent('ERNIE Award URI Assistant (mailto:'.config('crossref.mailto', '').')')
                        ->withHeaders(['Accept' => $provider === 'cordis' ? 'application/sparql-results+json' : 'application/json'])
                        ->get($url, $query);
                    if ($response->status() === 429 || $response->serverError()) {
                        $header = $response->header('Retry-After');
                        $seconds = ctype_digit($header) ? (int) $header : max(0, (strtotime($header) ?: time()) - time());
                        Cache::put($key, microtime(true) + max($interval, $seconds), max(3600, $seconds + 60));
                    }

                    return $response;
                });
                $retry = $response->status() === 429 || $response->serverError();
                if ($response->successful() && strlen($response->body()) <= 4000000 && is_array($json = $response->json())) {
                    return $json;
                }
            } catch (ConnectionException) {
                $retry = true;
            } catch (Throwable $exception) {
                throw new RuntimeException('Award source unavailable.', previous: $exception);
            }
            if (! $retry || $attempt === $attempts - 1) {
                break;
            }
            Sleep::usleep(200000 * (2 ** $attempt));
        }

        throw new RuntimeException('Award source unavailable or returned invalid data.');
    }
}
