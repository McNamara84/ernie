<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectVocabularyConcept;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use App\Support\IdentifierUrl;

final readonly class EposDcatApConceptLookupService
{
    public function __construct(private SubjectVocabularyLookupService $lookup) {}

    public function concept(Subject $subject): ?SubjectVocabularyConcept
    {
        $scheme = $this->lookup->normalizeSupportedScheme($subject->subject_scheme);
        if ($scheme === null || blank($subject->value_uri)) {
            return null;
        }
        $concept = $this->lookup->findById($scheme, $subject->value_uri)->sole();

        return $concept !== null && filled($concept->definition)
            && IdentifierUrl::http($concept->valueUri()) !== null && IdentifierUrl::http($concept->schemeUri) !== null
            ? $concept : null;
    }
}
