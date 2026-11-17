<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PidSetting;
use App\Services\Pidinst\DataCiteInstrumentNormalizer;
use InvalidArgumentException;
use Throwable;

/** Classifies a complete import using explicit metadata or one local vocabulary read. */
final class InstrumentReferenceClassifierService
{
    public function __construct(private readonly Pid4instVocabularyService $vocabulary) {}

    /** @param array<int, array<string, mixed>> $references
     * @return array<int, bool>
     */
    public function classify(array $references): array
    {
        $known = null;
        $result = [];
        foreach ($references as $index => $reference) {
            $result[$index] = false;
            if (($reference['relationType'] ?? null) !== 'IsCollectedBy') {
                continue;
            }
            $type = $reference['relatedIdentifierType'] ?? null;
            if ($type === 'Handle') {
                $result[$index] = true;
            } elseif ($type === 'DOI') {
                try {
                    $doi = DataCiteInstrumentNormalizer::doi($reference['relatedIdentifier'] ?? null);
                } catch (InvalidArgumentException) {
                    continue;
                }
                if (($reference['resourceTypeGeneral'] ?? null) === 'Instrument') {
                    $result[$index] = true;
                } else {
                    $known ??= $this->knownDois();
                    $result[$index] = ($known[$doi] ?? 0) === 1;
                }
            }
        }

        return $result;
    }

    /** @return array<string, int> */
    private function knownDois(): array
    {
        if (PidSetting::where('type', PidSetting::TYPE_PID4INST)->value('is_active') === false) {
            return [];
        }
        try {
            $records = $this->vocabulary->getLocalPayload()['data'];
        } catch (Throwable) {
            return [];
        }
        $known = [];
        foreach ($records as $record) {
            $identifiers = array_merge([['type' => $record['pidType'], 'value' => $record['pid']]], $record['lookupIdentifiers'] ?? []);
            $recordDois = [];
            foreach ($identifiers as $identifier) {
                if ($identifier['type'] === 'DOI') {
                    try {
                        $recordDois[DataCiteInstrumentNormalizer::doi($identifier['value'])] = true;
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                }
            }
            foreach ($recordDois as $doi => $_) {
                $known[$doi] = ($known[$doi] ?? 0) + 1;
            }
        }

        return $known;
    }
}
