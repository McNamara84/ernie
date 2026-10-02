<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class SchemaOrgResourceTypes
{
    /** @return array<string, array{string, string, string, bool}> */
    public static function cases(): array
    {
        // Reviewed expectations are independent of the runtime mapping configuration.
        return [
            'audiovisual' => ['audiovisual', 'VideoObject', 'media', false],
            'award' => ['award', 'Thing', 'described-object', true],
            'book' => ['book', 'Book', 'creative-work', false],
            'book-chapter' => ['book-chapter', 'Chapter', 'creative-work', false],
            'collection' => ['collection', 'Collection', 'creative-work', false],
            'computational-notebook' => ['computational-notebook', 'CreativeWork', 'creative-work', true],
            'conference-paper' => ['conference-paper', 'ScholarlyArticle', 'creative-work', false],
            'conference-proceeding' => ['conference-proceeding', 'Collection', 'creative-work', false],
            'data-paper' => ['data-paper', 'ScholarlyArticle', 'creative-work', false],
            'dataset' => ['dataset', 'Dataset', 'dataset', false],
            'dissertation' => ['dissertation', 'Thesis', 'creative-work', false],
            'event' => ['event', 'Event', 'described-object', false],
            'image' => ['image', 'ImageObject', 'media', false],
            'interactive-resource' => ['interactive-resource', 'CreativeWork', 'creative-work', true],
            'instrument' => ['instrument', 'Thing', 'described-object', true],
            'journal' => ['journal', 'Periodical', 'creative-work', false],
            'journal-article' => ['journal-article', 'ScholarlyArticle', 'creative-work', false],
            'model' => ['model', 'CreativeWork', 'creative-work', true],
            'output-management-plan' => ['output-management-plan', 'DigitalDocument', 'creative-work', false],
            'peer-review' => ['peer-review', 'Review', 'creative-work', false],
            'physical-object' => ['physical-object', 'Thing', 'described-object', true],
            'poster' => ['poster', 'CreativeWork', 'creative-work', true],
            'preprint' => ['preprint', 'ScholarlyArticle', 'creative-work', false],
            'presentation' => ['presentation', 'PresentationDigitalDocument', 'creative-work', false],
            'project' => ['project', 'Project', 'described-object', false],
            'report' => ['report', 'Report', 'creative-work', false],
            'service' => ['service', 'Service', 'described-object', false],
            'software' => ['software', 'SoftwareSourceCode', 'software', false],
            'sound' => ['sound', 'AudioObject', 'media', false],
            'standard' => ['standard', 'CreativeWork', 'creative-work', true],
            'study-registration' => ['study-registration', 'DigitalDocument', 'creative-work', false],
            'text' => ['text', 'CreativeWork', 'creative-work', true],
            'workflow' => ['workflow', 'CreativeWork', 'creative-work', true],
            'other' => ['other', 'Thing', 'described-object', true],
        ];
    }
}
