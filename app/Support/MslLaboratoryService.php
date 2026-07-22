<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\MslLaboratoryVocabularyService;
use Illuminate\Support\Facades\Log;

/**
 * Provides identifier lookup and upload enrichment from the local MSL vocabulary.
 *
 * @phpstan-type Laboratory array{
 *     identifier: string,
 *     name: string,
 *     display_name: string,
 *     affiliation_name: string,
 *     affiliation_ror: string|null,
 *     scientific_domain: string,
 *     country: string
 * }
 */
class MslLaboratoryService
{
    /** @var array<string, Laboratory>|null */
    private ?array $laboratoriesById = null;

    public function __construct(
        private readonly MslLaboratoryVocabularyService $vocabularyService
    ) {}

    /** @return array<string, Laboratory> */
    private function loadLaboratories(): array
    {
        if ($this->laboratoriesById !== null) {
            return $this->laboratoriesById;
        }

        try {
            $payload = $this->vocabularyService->getLocalPayload();

            if ($payload === null) {
                Log::warning('Local MSL laboratories vocabulary is not available');
                $this->laboratoriesById = [];

                return [];
            }

            $indexed = [];

            foreach ($payload['data'] as $laboratory) {
                $indexed[$laboratory['identifier']] = $laboratory;
            }

            $this->laboratoriesById = $indexed;

            return $indexed;
        } catch (\Throwable $exception) {
            Log::error('Failed to load local MSL laboratories vocabulary', [
                'error' => $exception->getMessage(),
            ]);
            $this->laboratoriesById = [];

            return [];
        }
    }

    /** @return Laboratory|null */
    public function findByLabId(string $labId): ?array
    {
        return $this->loadLaboratories()[$labId] ?? null;
    }

    public function isValidLabId(string $labId): bool
    {
        return isset($this->loadLaboratories()[$labId]);
    }

    /**
     * @return array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string}
     */
    public function enrichLaboratoryData(
        string $labId,
        ?string $name = null,
        ?string $affiliationName = null,
        ?string $affiliationRor = null
    ): array {
        $fromVocabulary = $this->findByLabId($labId);

        if ($fromVocabulary === null) {
            Log::warning('MSL Laboratory ID not found in vocabulary', [
                'labId' => $labId,
                'name' => $name,
            ]);

            return [
                'identifier' => $labId,
                'name' => $name ?? '',
                'affiliation_name' => $affiliationName ?? '',
                'affiliation_ror' => $affiliationRor ?? '',
            ];
        }

        return [
            'identifier' => $labId,
            'name' => $fromVocabulary['name'] ?: ($name ?? ''),
            'affiliation_name' => $fromVocabulary['affiliation_name'] ?: ($affiliationName ?? ''),
            'affiliation_ror' => ($fromVocabulary['affiliation_ror'] ?? '') ?: ($affiliationRor ?? ''),
        ];
    }

    /** Forget the request-local identifier index. */
    public function clearCache(): void
    {
        $this->laboratoriesById = null;
    }
}
