<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use Closure;

final class SizeFormatDownloadTargetResolverService
{
    /**
     * @param  (Closure(string): list<string>)|null  $dnsLookup
     */
    public function __construct(
        private readonly ?Closure $dnsLookup = null,
    ) {}

    /**
     * Resolve and validate a download target once so the caller can pin the
     * connection to the exact public address that passed SSRF validation.
     *
     * @return array{host: string, port: int, address: string, curl_resolve: string}|null
     */
    public function resolve(string $url): ?array
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return null;
        }

        if (
            ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $curlHost = strtolower(trim((string) $parts['host'], '[]'));
        $host = rtrim($curlHost, '.');

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80);

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->lookupAddresses($host);

        if ($addresses === [] || count(array_filter($addresses, $this->isPublicIpAddress(...))) !== count($addresses)) {
            return null;
        }

        usort(
            $addresses,
            static fn (string $left, string $right): int => (int) str_contains($left, ':') <=> (int) str_contains($right, ':'),
        );

        $address = $addresses[0];
        $curlAddress = str_contains($address, ':') ? '['.$address.']' : $address;

        return [
            'host' => $host,
            'port' => $port,
            'address' => $address,
            'curl_resolve' => $curlHost.':'.$port.':'.$curlAddress,
        ];
    }

    /** @return list<string> */
    private function lookupAddresses(string $host): array
    {
        if ($this->dnsLookup instanceof Closure) {
            return array_values(array_unique(($this->dnsLookup)($host)));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicIpAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
