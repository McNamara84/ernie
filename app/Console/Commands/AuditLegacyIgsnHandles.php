<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RelatedIdentifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Description('Read-only audit of published legacy IGSN Handles against the official Handle API.')]
#[Signature('igsn:audit-legacy-handles
                            {--batch=20 : Maximum concurrent Handle API requests per batch}
                            {--output= : Optional path for a JSON report}')]
class AuditLegacyIgsnHandles extends Command
{
    private const HANDLE_API_BASE_URL = 'https://hdl.handle.net/api/handles/';

    public function handle(): int
    {
        $batchSize = min(50, max(1, (int) $this->option('batch')));

        $identifiers = RelatedIdentifier::query()
            ->where('identifier', 'like', '10273/%')
            ->whereHas('identifierType', fn ($query) => $query->where('slug', 'IGSN'))
            ->whereHas('relationType', fn ($query) => $query->where('slug', 'IsIdenticalTo'))
            ->whereHas('resource', fn ($query) => $query
                ->whereNotNull('doi')
                ->where('doi', '!=', '')
                ->whereHas('landingPage', fn ($landingPageQuery) => $landingPageQuery->where('is_published', true)))
            ->orderBy('id')
            ->get(['id', 'resource_id', 'identifier']);

        if ($identifiers->isEmpty()) {
            $this->info('No published legacy IGSN Handles found.');
            $this->writeReport([], 0);

            return self::SUCCESS;
        }

        $this->info("Auditing {$identifiers->count()} published legacy IGSN Handle(s)...");

        $failures = [];
        $resolved = 0;

        foreach ($identifiers->chunk($batchSize) as $batch) {
            $responses = Http::pool(fn (Pool $pool): array => $batch
                ->mapWithKeys(fn (RelatedIdentifier $relatedIdentifier): array => [
                    (string) $relatedIdentifier->id => $pool
                        ->as((string) $relatedIdentifier->id)
                        ->acceptJson()
                        ->connectTimeout(5)
                        ->timeout(10)
                        ->retry(3, 200, throw: false)
                        ->get(self::HANDLE_API_BASE_URL.$relatedIdentifier->identifier),
                ])
                ->all());

            foreach ($batch as $relatedIdentifier) {
                $response = $responses[(string) $relatedIdentifier->id] ?? null;

                if ($response instanceof Response && $response->successful() && $response->json('responseCode') === 1) {
                    $resolved++;

                    continue;
                }

                $failures[] = [
                    'related_identifier_id' => $relatedIdentifier->id,
                    'resource_id' => $relatedIdentifier->resource_id,
                    'identifier' => $relatedIdentifier->identifier,
                    'resolver_url' => 'https://hdl.handle.net/'.$relatedIdentifier->identifier,
                    'classification' => $this->failureClassification($response),
                    'status' => $this->failureStatus($response),
                ];
            }
        }

        $this->info("Resolved: {$resolved}");

        $missing = count(array_filter($failures, static fn (array $failure): bool => $failure['classification'] === 'missing'));
        $unknown = count($failures) - $missing;
        $this->line("Missing: {$missing}");
        $this->line("Transient or unknown: {$unknown}");

        if (! $this->writeReport($failures, $identifiers->count(), $resolved)) {
            return self::FAILURE;
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function failureStatus(mixed $response): string
    {
        if ($response instanceof ConnectionException) {
            return 'connection-error';
        }

        if (! $response instanceof Response) {
            return 'missing-response';
        }

        if (! $response->successful()) {
            return 'http-'.$response->status();
        }

        $responseCode = $response->json('responseCode');

        return is_int($responseCode) ? 'handle-response-'.$responseCode : 'invalid-response';
    }

    private function failureClassification(mixed $response): string
    {
        return $response instanceof Response
            && $response->successful()
            && $response->json('responseCode') === 100
                ? 'missing'
                : 'unknown';
    }

    /**
     * @param  list<array<string, int|string>>  $failures
     */
    private function writeReport(array $failures, int $checked, int $resolved = 0): bool
    {
        $outputPath = $this->option('output');

        if (! is_string($outputPath) || trim($outputPath) === '') {
            return true;
        }

        $report = [
            'checked' => $checked,
            'resolved' => $resolved,
            'failed' => count($failures),
            'failures' => $failures,
        ];

        try {
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;

            if (file_put_contents($outputPath, $encoded) === false) {
                throw new \RuntimeException('Unable to write report.');
            }
        } catch (Throwable $exception) {
            $this->error("Could not write audit report to {$outputPath}: {$exception->getMessage()}");

            return false;
        }

        $this->info("Report written to {$outputPath}");

        return true;
    }
}
