<?php

declare(strict_types=1);

namespace App\Support;

final class DataCiteSchemaVersion
{
    public const string KERNEL_4 = 'http://datacite.org/schema/kernel-4';

    /**
     * DataCite Kernel 4.7 resourceTypeGeneral values.
     *
     * @var list<string>
     */
    private const array KERNEL_4_RESOURCE_TYPES = [
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
        'Poster',
        'Preprint',
        'Presentation',
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

    public static function isKernel4(?string $schemaVersion): bool
    {
        if ($schemaVersion === null) {
            return false;
        }

        return preg_match('~/kernel-4(?:\.[0-9]+)?/?$~i', trim($schemaVersion)) === 1;
    }

    public static function isKnownLegacy(?string $schemaVersion): bool
    {
        if ($schemaVersion === null || trim($schemaVersion) === '') {
            return true;
        }

        $normalized = strtolower(rtrim(trim($schemaVersion), '/'));

        return preg_match('~/kernel-(?:2(?:\.[0-9]+)?|3(?:\.[0-9]+)?)$~', $normalized) === 1
            || preg_match('/^(?:2(?:\.[0-9]+)?|3(?:\.[0-9]+)?)$/', $normalized) === 1;
    }

    public static function isKernel4ResourceType(string $resourceTypeGeneral): bool
    {
        return in_array($resourceTypeGeneral, self::KERNEL_4_RESOURCE_TYPES, true);
    }
}
