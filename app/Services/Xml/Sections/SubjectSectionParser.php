<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\Imports\Subjects\ImportedSubjectData;
use App\Services\Imports\Subjects\SubjectImportNormalizer;
use App\Services\Imports\Subjects\SubjectImportResult;
use App\Support\Xml\XmlElementHelpers;
use Saloon\XmlWrangler\XmlReader;

final readonly class SubjectSectionParser
{
    public function __construct(private SubjectImportNormalizer $normalizer) {}

    public function parse(XmlReader $reader): SubjectImportResult
    {
        $elements = $reader
            ->xpathElement('//*[local-name()="subjects"]/*[local-name()="subject"]')
            ->get();

        $subjects = [];
        foreach ($elements as $element) {
            $subjects[] = new ImportedSubjectData(
                value: XmlElementHelpers::stringValue($element),
                subjectScheme: $element->getAttribute('subjectScheme'),
                schemeUri: $element->getAttribute('schemeURI'),
                valueUri: $element->getAttribute('valueURI'),
                classificationCode: $element->getAttribute('classificationCode'),
                language: $element->getAttribute('xml:lang'),
            );
        }

        return $this->normalizer->normalize($subjects);
    }
}
