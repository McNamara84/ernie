<?php

declare(strict_types=1);

namespace App\Services\Citations;

/**
 * Normalises DataCite `resourceTypeGeneral` strings to the canonical
 * DataCite 4.7 vocabulary (PascalCase, known values only).
 */
final class DataCiteTypeMapper
{
    /**
     * @var array<string>
     */
    private const KNOWN = [
        'Audiovisual',
        'Award',
        'Book',
        'BookChapter',
        'Collection',
        'ComputationalNotebook',
        'ConferencePaper',
        'ConferenceProceeding',
        'DataPaper',
        'Dataset',
        'Dissertation',
        'Event',
        'Image',
        'Instrument',
        'InteractiveResource',
        'Journal',
        'JournalArticle',
        'Model',
        'OutputManagementPlan',
        'PeerReview',
        'PhysicalObject',
        'Preprint',
        'Project',
        'Report',
        'Service',
        'Software',
        'Sound',
        'Standard',
        'StudyRegistration',
        'Text',
        'Workflow',
        'Other',
    ];

    public function map(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Text';
        }

        $normalized = $this->normalize($value);
        foreach (self::KNOWN as $known) {
            if (strtolower($known) === strtolower($normalized)) {
                return $known;
            }
        }

        return 'Text';
    }

    private function normalize(string $value): string
    {
        $parts = preg_split('/[\s_\-]+/', trim($value)) ?: [];
        $pascal = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $pascal .= ucfirst(strtolower($part));
        }

        return $pascal !== '' ? $pascal : $value;
    }
}
