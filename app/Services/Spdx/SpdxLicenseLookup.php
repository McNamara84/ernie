<?php

declare(strict_types=1);

namespace App\Services\Spdx;

use App\Models\Right;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * In-memory lookup table for SPDX licenses.
 *
 * The lookup is built from ERNIE's local `rights` catalog. That catalog is
 * populated by the existing `spdx:sync-licenses` command, so discovery jobs do
 * not need to call the SPDX website while curators are waiting.
 */
final readonly class SpdxLicenseLookup
{
    public const string SCHEME_URI = 'https://spdx.org/licenses/';

    public const string RIGHTS_IDENTIFIER_SCHEME = 'SPDX';

    /**
     * Text aliases that are safe enough to use as strong matches.
     *
     * Keep this list deliberately boring and reviewed. Legal metadata is not a
     * good place for adventurous fuzzy matching.
     *
     * @var array<string, string>
     */
    private const APPROVED_TEXT_ALIASES = [
        'cc by 4.0' => 'CC-BY-4.0',
        'cc attribution 4.0' => 'CC-BY-4.0',
        'creative commons attribution 4.0' => 'CC-BY-4.0',
        'creative commons attribution 4.0 international' => 'CC-BY-4.0',
        'cc by-nc 4.0' => 'CC-BY-NC-4.0',
        'cc by nc 4.0' => 'CC-BY-NC-4.0',
        'cc attribution-noncommercial 4.0 international (cc by-nc 4.0)' => 'CC-BY-NC-4.0',
        'creative commons attribution-noncommercial 4.0 international (cc by-nc 4.0)' => 'CC-BY-NC-4.0',
        'cc by-sa 4.0' => 'CC-BY-SA-4.0',
        'cc by sa 4.0' => 'CC-BY-SA-4.0',
        'cc0 universal 1.0' => 'CC0-1.0',
        'creative commons zero v1.0 universal' => 'CC0-1.0',
        'apache license 2.0' => 'Apache-2.0',
        'apache license version 2.0' => 'Apache-2.0',
        'apache license, version 2.0' => 'Apache-2.0',
        'mit licence' => 'MIT',
        'mit license' => 'MIT',
        'gnu general public license version 3' => 'GPL-3.0-only',
        'gnu general public license, version 3' => 'GPL-3.0-only',
        'gnu general public license, version 3, 29 june 2007' => 'GPL-3.0-only',
        'open data commons open database license (odbl)' => 'ODbL-1.0',
        'european union public licence 1.2' => 'EUPL-1.2',
    ];

    /**
     * URI aliases for license pages that are commonly imported in DataCite or
     * legacy metadata but are not necessarily the URL stored in the SPDX list.
     *
     * @var array<string, string>
     */
    private const APPROVED_URI_ALIASES = [
        'creativecommons.org/licenses/by/4.0' => 'CC-BY-4.0',
        'creativecommons.org/licenses/by/4.0/legalcode' => 'CC-BY-4.0',
        'creativecommons.org/licenses/by-nc/4.0' => 'CC-BY-NC-4.0',
        'creativecommons.org/licenses/by-nc/4.0/legalcode' => 'CC-BY-NC-4.0',
        'creativecommons.org/licenses/by-sa/4.0' => 'CC-BY-SA-4.0',
        'creativecommons.org/licenses/by-sa/4.0/legalcode' => 'CC-BY-SA-4.0',
        'creativecommons.org/publicdomain/zero/1.0' => 'CC0-1.0',
        'www.apache.org/licenses/license-2.0' => 'Apache-2.0',
        'apache.org/licenses/license-2.0' => 'Apache-2.0',
        'www.gnu.org/licenses/gpl-3.0.html' => 'GPL-3.0-only',
        'www.gnu.org/licenses/gpl-3.0.en.html' => 'GPL-3.0-only',
        'opensource.org/licenses/mit' => 'MIT',
        'opensource.org/licenses/bsd-3-clause' => 'BSD-3-Clause',
        'opensource.org/licenses/eupl-1.2' => 'EUPL-1.2',
        'opendatacommons.org/licenses/odbl/1.0/index.html' => 'ODbL-1.0',
    ];

    /**
     * @param  array<string, SpdxLicenseData>  $licensesByIdentifier
     * @param  array<string, string>  $identifierKeys
     * @param  array<string, string>  $nameKeys
     * @param  array<string, string>  $uriKeys
     * @param  array<string, string>  $aliasKeys
     */
    private function __construct(
        private array $licensesByIdentifier,
        private array $identifierKeys,
        private array $nameKeys,
        private array $uriKeys,
        private array $aliasKeys,
    ) {}

    public static function fromRightsCatalog(): self
    {
        /** @var Collection<int, Right> $rights */
        $rights = Right::query()
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '')
            ->get(['identifier', 'name', 'uri', 'scheme_uri']);

        return self::fromLicenses(
            $rights->map(fn (Right $right): SpdxLicenseData => SpdxLicenseData::fromRight($right))->all(),
        );
    }

    /**
     * @param  iterable<int, SpdxLicenseData>  $licenses
     */
    public static function fromLicenses(iterable $licenses): self
    {
        $licensesByIdentifier = [];
        $identifierKeys = [];
        $nameKeys = [];
        $uriKeys = [];

        foreach ($licenses as $license) {
            $licensesByIdentifier[$license->identifier] = $license;

            // SPDX identifiers are case-sensitive in display, but matching user
            // input case-insensitively is a helpful and safe normalization.
            $identifierKeys[self::normalizeIdentifier($license->identifier)] = $license->identifier;
            $nameKeys[self::normalizeText($license->name)] = $license->identifier;

            if ($license->rightsUri !== null && trim($license->rightsUri) !== '') {
                $uriKeys[self::normalizeUri($license->rightsUri)] = $license->identifier;
            }

            $uriKeys[self::normalizeUri(self::licensePageUrl($license->identifier))] = $license->identifier;
        }

        $aliasKeys = [];

        foreach (self::APPROVED_TEXT_ALIASES as $alias => $identifier) {
            if (isset($licensesByIdentifier[$identifier])) {
                $aliasKeys[self::normalizeText($alias)] = $identifier;
            }
        }

        foreach (self::APPROVED_URI_ALIASES as $uri => $identifier) {
            if (isset($licensesByIdentifier[$identifier])) {
                $uriKeys[self::normalizeUri($uri)] = $identifier;
            }
        }

        return new self($licensesByIdentifier, $identifierKeys, $nameKeys, $uriKeys, $aliasKeys);
    }

    public static function licensePageUrl(string $identifier): string
    {
        return self::SCHEME_URI . $identifier . '.html';
    }

    public function findByIdentifier(?string $identifier): ?SpdxLicenseData
    {
        $key = self::normalizeIdentifier($identifier);

        return $this->licenseForKey($this->identifierKeys, $key);
    }

    public function findByName(?string $name): ?SpdxLicenseData
    {
        $key = self::normalizeText($name);

        return $this->licenseForKey($this->nameKeys, $key);
    }

    public function findByAlias(?string $text): ?SpdxLicenseData
    {
        $key = self::normalizeText($text);

        return $this->licenseForKey($this->aliasKeys, $key);
    }

    public function findByUri(?string $uri): ?SpdxLicenseData
    {
        $key = self::normalizeUri($uri);

        return $this->licenseForKey($this->uriKeys, $key);
    }

    /**
     * @return array<string, string>
     */
    public function approvedTextAliases(): array
    {
        return self::APPROVED_TEXT_ALIASES;
    }

    public static function normalizeIdentifier(?string $identifier): string
    {
        return Str::lower(trim((string) $identifier));
    }

    public static function normalizeText(?string $text): string
    {
        $normalized = Str::lower(trim((string) $text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public static function normalizeUri(?string $uri): string
    {
        $uri = trim((string) $uri);

        if ($uri === '') {
            return '';
        }

        $parts = parse_url($uri);

        if ($parts === false) {
            return Str::lower(rtrim($uri, '/'));
        }

        $host = Str::lower($parts['host'] ?? '');
        $path = Str::lower($parts['path'] ?? '');
        $path = preg_replace('/\/+/', '/', $path) ?? $path;
        $path = rtrim($path, '/');

        return ltrim($host . '/' . ltrim($path, '/'), '/');
    }

    /**
     * @param  array<string, string>  $map
     */
    private function licenseForKey(array $map, string $key): ?SpdxLicenseData
    {
        if ($key === '' || ! isset($map[$key])) {
            return null;
        }

        return $this->licensesByIdentifier[$map[$key]] ?? null;
    }
}
