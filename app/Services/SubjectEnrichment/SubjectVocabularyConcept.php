<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

/**
 * A normalized concept extracted from one local subject vocabulary cache.
 */
final readonly class SubjectVocabularyConcept
{
    /**
     * @param  list<string>  $synonyms
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $path,
        public string $scheme,
        public string $schemeUri,
        public ?string $classificationCode,
        public string $language,
        public SubjectVocabularySource $source,
        public array $synonyms = [],
    ) {}

    public function valueUri(): ?string
    {
        return filter_var($this->id, FILTER_VALIDATE_URL) === false ? null : $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toVocabularyPayload(): array
    {
        return array_filter([
            'id' => $this->id,
            'label' => $this->label,
            'path' => $this->path,
            'scheme' => $this->scheme,
            'scheme_uri' => $this->schemeUri,
            'value_uri' => $this->valueUri(),
            'classification_code' => $this->classificationCode,
            'language' => $this->language,
            'synonyms' => $this->synonyms,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
