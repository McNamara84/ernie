<?php

declare(strict_types=1);

namespace App\Services\Imports\Subjects;

final readonly class ImportedSubjectData
{
    public function __construct(
        public mixed $value,
        public mixed $subjectScheme = null,
        public mixed $schemeUri = null,
        public mixed $valueUri = null,
        public mixed $classificationCode = null,
        public mixed $language = null,
    ) {}
}
