<?php

declare(strict_types=1);

// All resource-type slugs are immutable. See docs/schemaorg-resource-types.md.
return [
    'fallback' => [
        'primary_type' => 'https://schema.org/Thing',
        'additional_types' => [],
        'profile' => 'described-object',
        'fallback_reason' => 'The resource type is missing or has no approved mapping.',
    ],
    'resource_types' => [
        'audiovisual' => [
            'primary_type' => 'https://schema.org/VideoObject',
            'additional_types' => [],
            'profile' => 'media',
            'fallback_reason' => null,
        ],
        'award' => [
            'primary_type' => 'https://schema.org/Thing',
            'additional_types' => [],
            'profile' => 'described-object',
            'fallback_reason' => 'Awards include recognition and non-monetary support, not only grants.',
        ],
        'book' => [
            'primary_type' => 'https://schema.org/Book',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'book-chapter' => [
            'primary_type' => 'https://schema.org/Chapter',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'collection' => [
            'primary_type' => 'https://schema.org/Collection',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'computational-notebook' => [
            'primary_type' => 'https://schema.org/CreativeWork',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => 'A notebook combines prose, code and outputs; no dedicated general notebook type exists.',
        ],
        'conference-paper' => [
            'primary_type' => 'https://schema.org/ScholarlyArticle',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'conference-proceeding' => [
            'primary_type' => 'https://schema.org/Collection',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'data-paper' => [
            'primary_type' => 'https://schema.org/ScholarlyArticle',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'dataset' => [
            'primary_type' => 'https://schema.org/Dataset',
            'additional_types' => [],
            'profile' => 'dataset',
            'fallback_reason' => null,
        ],
        'dissertation' => [
            'primary_type' => 'https://schema.org/Thesis',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'event' => [
            'primary_type' => 'https://schema.org/Event',
            'additional_types' => [],
            'profile' => 'described-object',
            'fallback_reason' => null,
        ],
        'image' => [
            'primary_type' => 'https://schema.org/ImageObject',
            'additional_types' => [],
            'profile' => 'media',
            'fallback_reason' => null,
        ],
        'interactive-resource' => [
            'primary_type' => 'https://schema.org/CreativeWork',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => 'Interaction alone does not establish that the resource is a software application.',
        ],
        'instrument' => [
            'primary_type' => 'https://schema.org/Thing',
            'additional_types' => [],
            'profile' => 'described-object',
            'fallback_reason' => 'No general research instrument type exists; Product implies an offered product or service.',
        ],
        'journal' => [
            'primary_type' => 'https://schema.org/Periodical',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'journal-article' => [
            'primary_type' => 'https://schema.org/ScholarlyArticle',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'model' => [
            'primary_type' => 'https://schema.org/CreativeWork',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => 'A model may be conceptual or mathematical; the slug does not establish 3D content or software.',
        ],
        'output-management-plan' => [
            'primary_type' => 'https://schema.org/DigitalDocument',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'peer-review' => [
            'primary_type' => 'https://schema.org/Review',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'physical-object' => [
            'primary_type' => 'https://schema.org/Thing',
            'additional_types' => [],
            'profile' => 'described-object',
            'fallback_reason' => 'Samples, substances and artifacts have no suitable common specific Schema.org type.',
        ],
        'poster' => [
            'primary_type' => 'https://schema.org/CreativeWork',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => 'No general research poster type exists; VisualArtwork would assume an artwork.',
        ],
        'preprint' => [
            'primary_type' => 'https://schema.org/ScholarlyArticle',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'presentation' => [
            'primary_type' => 'https://schema.org/PresentationDigitalDocument',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'project' => [
            'primary_type' => 'https://schema.org/Project',
            'additional_types' => [],
            'profile' => 'described-object',
            'fallback_reason' => null,
        ],
        'report' => [
            'primary_type' => 'https://schema.org/Report',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'service' => [
            'primary_type' => 'https://schema.org/Service',
            'additional_types' => [],
            'profile' => 'described-object',
            'fallback_reason' => null,
        ],
        'software' => [
            'primary_type' => 'https://schema.org/SoftwareSourceCode',
            'additional_types' => ['https://schema.org/SoftwareApplication'],
            'profile' => 'software',
            'fallback_reason' => null,
        ],
        'sound' => [
            'primary_type' => 'https://schema.org/AudioObject',
            'additional_types' => [],
            'profile' => 'media',
            'fallback_reason' => null,
        ],
        'standard' => [
            'primary_type' => 'https://schema.org/CreativeWork',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => 'A general standard is not necessarily a technical specification or legislation.',
        ],
        'study-registration' => [
            'primary_type' => 'https://schema.org/DigitalDocument',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => null,
        ],
        'text' => [
            'primary_type' => 'https://schema.org/CreativeWork',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => 'Text is a literal datatype; TextObject and DigitalDocument assume a file or electronic document.',
        ],
        'workflow' => [
            'primary_type' => 'https://schema.org/CreativeWork',
            'additional_types' => [],
            'profile' => 'creative-work',
            'fallback_reason' => 'A workflow is not necessarily source code or HowTo instructions.',
        ],
        'other' => [
            'primary_type' => 'https://schema.org/Thing',
            'additional_types' => [],
            'profile' => 'described-object',
            'fallback_reason' => 'An unspecified resource is not necessarily a CreativeWork.',
        ],
    ],
];
