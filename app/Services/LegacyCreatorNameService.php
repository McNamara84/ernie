<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OldDataset;
use App\Support\OrcidNormalizer;
use Illuminate\Support\Facades\Log;

final class LegacyCreatorNameService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function dataCiteCreators(OldDataset $dataset): array
    {
        $creators = [];

        try {
            $authors = $dataset->getAuthors();
        } catch (\Throwable $exception) {
            Log::warning('Unable to load SUMARIO creator names; continuing without creator-name enrichment.', [
                'doi' => $dataset->identifier,
                'legacy_resource_id' => $dataset->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        foreach ($authors as $author) {
            $givenName = $this->filled($author['givenName'] ?? null);
            $familyName = $this->filled($author['familyName'] ?? null);
            $name = $this->filled($author['name'])
                ?? $this->format($familyName, $givenName);

            if ($name === null) {
                continue;
            }

            $creator = [
                'name' => $name,
                'nameType' => 'Personal',
            ];

            if ($givenName !== null) {
                $creator['givenName'] = $givenName;
            }
            if ($familyName !== null) {
                $creator['familyName'] = $familyName;
            }

            $orcid = OrcidNormalizer::extractBareId((string) ($author['orcid'] ?? ''));
            if (OrcidNormalizer::isValid($orcid)) {
                $creator['nameIdentifiers'] = [[
                    'nameIdentifier' => $orcid,
                    'nameIdentifierScheme' => 'ORCID',
                    'schemeUri' => 'https://orcid.org/',
                ]];
            }

            $creators[] = $creator;
        }

        return $creators;
    }

    private function format(?string $familyName, ?string $givenName): ?string
    {
        if ($familyName !== null && $givenName !== null) {
            return $familyName.', '.$givenName;
        }

        return $familyName ?? $givenName;
    }

    private function filled(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
