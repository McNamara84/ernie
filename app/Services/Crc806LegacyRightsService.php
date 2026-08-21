<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Spdx\SpdxLicenseLookup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Loads a missing license from the trusted CRC806 legacy landing page.
 *
 * This adapter is deliberately provider-specific. DataCite target URLs are
 * user-controlled metadata, so broad or redirect-following scraping would
 * introduce an SSRF risk and make legal metadata dependent on heuristics.
 */
final class Crc806LegacyRightsService
{
    private const string HOST = 'crc806db.uni-koeln.de';

    private const int MAX_RESPONSE_BYTES = 1_000_000;

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    private const int REQUEST_TIMEOUT_SECONDS = 5;

    private const int REQUEST_ATTEMPTS = 2;

    /** @var list<string> */
    private const array CC_LICENSE_FAMILIES = [
        'by',
        'by-sa',
        'by-nd',
        'by-nc',
        'by-nc-sa',
        'by-nc-nd',
    ];

    private bool $providerUnavailable = false;

    private ?SpdxLicenseLookup $licenseLookup = null;

    /**
     * @return array{
     *     rights: string,
     *     rightsUri: string,
     *     rightsIdentifier: string,
     *     rightsIdentifierScheme: string,
     *     schemeUri: string,
     *     source: string
     * }|null
     */
    public function findRights(string $doi, mixed $landingPageUrl): ?array
    {
        if ($this->providerUnavailable || ! is_string($landingPageUrl)) {
            return null;
        }

        $requestUrl = $this->canonicalLandingPageUrl($landingPageUrl);

        if ($requestUrl === null) {
            return null;
        }

        $normalizedDoi = $this->normalizeDoi($doi);

        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent('ERNIE CRC806 legacy rights importer')
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->retry(
                    self::REQUEST_ATTEMPTS,
                    100,
                    static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError()),
                    throw: false,
                )
                ->get($requestUrl);
        } catch (Throwable $exception) {
            $this->providerUnavailable = true;
            $this->logFailure($normalizedDoi, 'provider-connection-failure', [
                'exception' => $exception::class,
            ]);

            return null;
        }

        if ($response->status() === 429) {
            $this->providerUnavailable = true;
            $this->logFailure($normalizedDoi, 'provider-rate-limited', [
                'status' => $response->status(),
            ]);

            return null;
        }

        if ($response->serverError()) {
            $this->providerUnavailable = true;
            $this->logFailure($normalizedDoi, 'provider-server-error', [
                'status' => $response->status(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->logFailure($normalizedDoi, 'landing-page-http-error', [
                'status' => $response->status(),
            ]);

            return null;
        }

        try {
            $html = $this->readLimitedBody($response);
        } catch (Throwable $exception) {
            $this->providerUnavailable = true;
            $this->logFailure($normalizedDoi, 'provider-response-read-failure', [
                'exception' => $exception::class,
            ]);

            return null;
        }

        if ($html === null) {
            $this->logFailure($normalizedDoi, 'landing-page-response-too-large');

            return null;
        }

        $routeInfo = $this->extractRouteInfo($html);
        $dataset = $routeInfo['allProps']['dataset'] ?? null;

        if (! is_array($dataset)) {
            $this->logFailure($normalizedDoi, 'missing-structured-dataset');

            return null;
        }

        $embeddedDoi = $this->extractDatasetDoi($dataset['extras'] ?? null);

        if ($embeddedDoi === null || $embeddedDoi !== $normalizedDoi) {
            $this->logFailure($normalizedDoi, 'embedded-doi-mismatch');

            return null;
        }

        $license = $dataset['license'] ?? null;
        $name = is_array($license) && is_string($license['name'] ?? null)
            ? trim($license['name'])
            : '';
        $uri = is_array($license) && is_string($license['url'] ?? null)
            ? trim($license['url'])
            : '';

        if ($name === '' || $uri === '' || strlen($name) > 500 || strlen($uri) > 2048) {
            $this->logFailure($normalizedDoi, 'missing-or-invalid-license');

            return null;
        }

        $identifier = $this->spdxIdentifierFromCreativeCommonsUri($uri);

        if ($identifier === null) {
            $this->logFailure($normalizedDoi, 'unsupported-or-conflicting-license');

            return null;
        }

        $licenseLookup = $this->licenseLookup ??= SpdxLicenseLookup::fromRightsCatalog();
        $catalogLicense = $licenseLookup->findByIdentifier($identifier);

        if ($catalogLicense === null || $catalogLicense->schemeUri !== SpdxLicenseLookup::SCHEME_URI) {
            $this->logFailure($normalizedDoi, 'license-not-in-active-spdx-catalog', [
                'rights_identifier' => $identifier,
            ]);

            return null;
        }

        if (! $this->licenseNameMatchesIdentifier($name, $identifier, $licenseLookup)) {
            $this->logFailure($normalizedDoi, 'unsupported-or-conflicting-license');

            return null;
        }

        return [
            'rights' => $name,
            'rightsUri' => $uri,
            'rightsIdentifier' => $catalogLicense->identifier,
            'rightsIdentifierScheme' => SpdxLicenseLookup::RIGHTS_IDENTIFIER_SCHEME,
            'schemeUri' => SpdxLicenseLookup::SCHEME_URI,
            'source' => 'legacy-crc806',
        ];
    }

    private function canonicalLandingPageUrl(string $url): ?string
    {
        $url = trim($url);

        if (
            $url === ''
            || strlen($url) > 4096
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        $usesStandardPort = $port === null
            || ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $host !== self::HOST
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! $usesStandardPort
        ) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'https://'.self::HOST.($path === '' ? '/' : $path).$query;
    }

    private function readLimitedBody(Response $response): ?string
    {
        $contentLength = $response->header('Content-Length');

        if (
            ctype_digit($contentLength)
            && (int) $contentLength > self::MAX_RESPONSE_BYTES
        ) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $html = '';

        while (! $stream->eof()) {
            $remaining = self::MAX_RESPONSE_BYTES + 1 - strlen($html);

            if ($remaining <= 0) {
                return null;
            }

            $chunk = $stream->read(min(8192, $remaining));

            if ($chunk === '') {
                throw new \RuntimeException('CRC806 response stream stopped before EOF.');
            }

            $html .= $chunk;
        }

        return strlen($html) > self::MAX_RESPONSE_BYTES ? null : $html;
    }

    /** @return array<string, mixed> */
    private function extractRouteInfo(string $html): array
    {
        if (preg_match('/window\s*\.\s*__routeInfo\s*=\s*/', $html, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $assignmentEnd = $match[0][1] + strlen($match[0][0]);
        $jsonStart = strpos($html, '{', $assignmentEnd);

        if ($jsonStart === false) {
            return [];
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($index = $jsonStart; $index < $length; $index++) {
            $character = $html[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;

                continue;
            }

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;

                if ($depth === 0) {
                    $json = substr($html, $jsonStart, $index - $jsonStart + 1);

                    try {
                        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        return [];
                    }

                    return is_array($decoded) ? $decoded : [];
                }
            }
        }

        return [];
    }

    private function extractDatasetDoi(mixed $extras): ?string
    {
        if (! is_array($extras)) {
            return null;
        }

        $candidates = [];
        $this->collectDoiCandidates($extras, $candidates);
        $candidates = array_values(array_unique(array_filter(array_map(
            fn (string $candidate): string => $this->normalizeDoi($candidate),
            $candidates,
        ))));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  list<string>  $candidates
     */
    private function collectDoiCandidates(array $node, array &$candidates): void
    {
        $entryKey = $node['key'] ?? $node['name'] ?? null;

        if (is_string($entryKey) && $this->isDoiExtraKey($entryKey)) {
            $this->appendDoiCandidate($node['value'] ?? $node['text'] ?? null, $candidates);
        }

        foreach ($node as $key => $value) {
            if (is_string($key) && $this->isDoiExtraKey($key)) {
                $this->appendDoiCandidate($value, $candidates);
            }

            if (is_array($value)) {
                $this->collectDoiCandidates($value, $candidates);
            }
        }
    }

    /** @param list<string> $candidates */
    private function appendDoiCandidate(mixed $value, array &$candidates): void
    {
        if (! is_string($value)) {
            return;
        }

        $value = trim($value);

        if (preg_match('~10\.\d{4,9}/[^\s"\'<>\]},]+~i', $value, $match) === 1) {
            $candidates[] = rtrim($match[0], '.,;');
        }
    }

    private function isDoiExtraKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), ['bibtex:doi', 'doi'], true);
    }

    private function normalizeDoi(string $doi): string
    {
        $doi = preg_replace(
            '~^(?:doi:\s*|https?://(?:dx\.)?doi\.org/)~i',
            '',
            trim($doi),
        ) ?? trim($doi);

        return strtolower(rtrim(trim($doi), '.,;'));
    }

    private function spdxIdentifierFromCreativeCommonsUri(string $uri): ?string
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($uri);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = strtolower((string) ($parts['path'] ?? ''));

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || ! in_array($host, ['creativecommons.org', 'www.creativecommons.org'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $familyPattern = implode('|', array_map(
            static fn (string $family): string => preg_quote($family, '~'),
            self::CC_LICENSE_FAMILIES,
        ));

        if (preg_match('~^/licenses/('.$familyPattern.')/(\d+\.\d+)/?$~', $path, $match) === 1) {
            return 'CC-'.strtoupper($match[1]).'-'.$match[2];
        }

        if (preg_match('~^/publicdomain/zero/(\d+\.\d+)/?$~', $path, $match) === 1) {
            return 'CC0-'.$match[1];
        }

        return null;
    }

    private function licenseNameMatchesIdentifier(
        string $name,
        string $identifier,
        SpdxLicenseLookup $licenseLookup,
    ): bool {
        $normalizedName = strtoupper(trim($name));
        $normalizedName = preg_replace('/^CC-/', 'CC ', $normalizedName) ?? $normalizedName;
        $normalizedName = preg_replace('/\s+/', ' ', $normalizedName) ?? $normalizedName;

        if (preg_match('/^CC0(?:\s+(\d+\.\d+))?$/', $normalizedName, $match) === 1) {
            return str_starts_with($identifier, 'CC0-')
                && (! isset($match[1]) || $identifier === 'CC0-'.$match[1]);
        }

        if (preg_match('/^CC\s+(BY(?:-(?:NC|ND|SA))*)(?:\s+(\d+\.\d+))?$/', $normalizedName, $match) === 1) {
            $identifierFamily = preg_replace('/-\d+\.\d+$/', '', $identifier) ?? $identifier;

            return $identifierFamily === 'CC-'.$match[1]
                && (! isset($match[2]) || $identifier === $identifierFamily.'-'.$match[2]);
        }

        $namedLicense = $licenseLookup->findByName($name)
            ?? $licenseLookup->findByAlias($name);

        return $namedLicense?->identifier === $identifier;
    }

    /** @param array<string, int|string> $context */
    private function logFailure(string $doi, string $reason, array $context = []): void
    {
        Log::warning('CRC806 legacy license fallback was not applied.', array_merge([
            'doi' => $doi,
            'reason' => $reason,
        ], $context));
    }
}
