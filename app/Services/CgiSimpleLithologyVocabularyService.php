<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CgiSimpleLithologyVocabularyParser;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CgiSimpleLithologyVocabularyService
{
    private const UPDATE_LOCK = 'vocabularies:cgi-simple-lithology:update';

    public function __construct(
        private readonly CgiSimpleLithologyVocabularyParser $parser,
    ) {}

    /** @return array<string, mixed> */
    public function fetchRemotePayload(): array
    {
        $endpoint = $this->validatedEndpoint();
        $collectionUri = (string) config('simple_lithology.collection_uri');
        $schemeUri = (string) config('simple_lithology.scheme_uri');

        $conceptQuery = <<<SPARQL
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
SELECT ?concept ?prefLabel ?definition ?broader WHERE {
  ?concept a skos:Concept ; skos:prefLabel ?prefLabel .
  FILTER(STRSTARTS(STR(?concept), "{$collectionUri}/"))
  FILTER(LANG(?prefLabel) = "" || LANGMATCHES(LANG(?prefLabel), "en"))
  OPTIONAL {
    ?concept skos:definition ?definition .
    FILTER(LANG(?definition) = "" || LANGMATCHES(LANG(?definition), "en"))
  }
  OPTIONAL { ?concept skos:broader ?broader . }
}
ORDER BY ?concept ?prefLabel ?broader
SPARQL;

        $metadataQuery = <<<SPARQL
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX schema: <https://schema.org/>
SELECT ?dateModified WHERE {
  VALUES ?scheme { <{$schemeUri}> }
  { ?scheme schema:dateModified ?dateModified }
  UNION
  { ?scheme dcterms:modified ?dateModified }
}
ORDER BY DESC(?dateModified)
LIMIT 1
SPARQL;

        $conceptBindings = $this->bindings($this->request($endpoint, $conceptQuery));
        $metadataBindings = $this->bindings($this->request($endpoint, $metadataQuery));
        $dateModified = $metadataBindings[0]['dateModified']['value'] ?? null;

        return $this->parser->buildPayload(
            $conceptBindings,
            is_string($dateModified) ? $dateModified : null,
            (int) config('simple_lithology.min_concepts'),
            (int) config('simple_lithology.max_concepts'),
            (int) config('simple_lithology.max_depth'),
        );
    }

    /** @return array<string, mixed>|null */
    public function localPayload(): ?array
    {
        $file = (string) config('simple_lithology.output_file');
        if (! Storage::disk('local')->exists($file)) {
            return null;
        }

        $contents = Storage::disk('local')->get($file);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('The local CGI Simple Lithology vocabulary cannot be read.');
        }

        $payload = json_decode($contents, true);
        if (! is_array($payload)) {
            throw new RuntimeException('The local CGI Simple Lithology vocabulary is invalid.');
        }

        $this->parser->validatePayload(
            $payload,
            (int) config('simple_lithology.min_concepts'),
            (int) config('simple_lithology.max_concepts'),
            (int) config('simple_lithology.max_depth'),
        );

        return $payload;
    }

    /** @return array<string, mixed> */
    public function updateLocalVocabulary(): array
    {
        return Cache::lock(self::UPDATE_LOCK, 180)->block(5, function (): array {
            $payload = $this->fetchRemotePayload();
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            $file = (string) config('simple_lithology.output_file');
            $temporaryFile = $file.'.'.Str::uuid().'.tmp';
            Storage::disk('local')->put($temporaryFile, $json);

            try {
                $temporaryPath = Storage::disk('local')->path($temporaryFile);
                $destinationPath = Storage::disk('local')->path($file);
                if (! rename($temporaryPath, $destinationPath)) {
                    throw new RuntimeException('Failed to atomically replace the CGI Simple Lithology vocabulary.');
                }
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($temporaryFile);
                throw $exception;
            }

            return $payload;
        });
    }

    /**
     * @return array{localCount: int, remoteCount: int, updateAvailable: bool, lastUpdated: string|null, localSha: string|null, remoteSha: string, updateReason: string|null}
     */
    public function compareWithRemote(): array
    {
        $local = $this->localPayload();
        $remote = $this->fetchRemotePayload();
        $localSource = [];
        if ($local !== null && isset($local['source']) && is_array($local['source'])) {
            $localSource = $local['source'];
        }

        $localSha = is_string($localSource['sha256'] ?? null) ? $localSource['sha256'] : null;
        $remoteSha = (string) $remote['source']['sha256'];
        $updateAvailable = $localSha === null || ! hash_equals($localSha, $remoteSha);

        return [
            'localCount' => (int) ($local['total'] ?? 0),
            'remoteCount' => (int) $remote['total'],
            'updateAvailable' => $updateAvailable,
            'lastUpdated' => is_string($local['lastUpdated'] ?? null) ? $local['lastUpdated'] : null,
            'localSha' => $localSha,
            'remoteSha' => $remoteSha,
            'updateReason' => $updateAvailable
                ? ($localSha === null ? 'No local vocabulary is installed.' : 'Labels, definitions, or hierarchy relationships changed.')
                : null,
        ];
    }

    private function validatedEndpoint(): string
    {
        $endpoint = (string) config('simple_lithology.endpoint');
        $parts = parse_url($endpoint);
        $allowedHost = (string) config('simple_lithology.allowed_host');

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ! hash_equals($allowedHost, mb_strtolower($parts['host']))
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new RuntimeException('The configured CGI vocabulary endpoint is not allowed.');
        }

        return $endpoint;
    }

    private function request(string $endpoint, string $query): Response
    {
        $response = Http::connectTimeout((int) config('simple_lithology.connect_timeout'))
            ->timeout((int) config('simple_lithology.timeout'))
            ->retry(3, 250, throw: false)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(['User-Agent' => 'ERNIE CGI Simple Lithology updater'])
            ->accept('application/sparql-results+json')
            ->get($endpoint, ['query' => $query]);

        if (! $response->successful()) {
            throw new RuntimeException("CGI vocabulary API request failed with HTTP {$response->status()}.");
        }

        $contentType = mb_strtolower($response->header('Content-Type'));
        if (! str_contains($contentType, 'application/sparql-results+json')
            && ! str_contains($contentType, 'application/json')
        ) {
            throw new RuntimeException("CGI vocabulary API returned an unexpected content type: {$contentType}");
        }

        if (strlen($response->body()) > (int) config('simple_lithology.max_response_bytes')) {
            throw new RuntimeException('CGI vocabulary API response exceeds the configured size limit.');
        }

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function bindings(Response $response): array
    {
        $bindings = $response->json('results.bindings');
        if (! is_array($bindings)) {
            throw new RuntimeException('CGI vocabulary API returned malformed SPARQL JSON.');
        }

        return array_values(array_filter($bindings, 'is_array'));
    }
}
