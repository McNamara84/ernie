<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\VocabularyCorruptedException;
use Illuminate\Support\Facades\File;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;

class PublicVocabularySchemaService
{
    private const DOCUMENT = 'https://ernie.invalid/openapi.json';

    public function revision(): string
    {
        $revision = File::hash(resource_path('data/openapi.json'), 'xxh128');
        if ($revision === false) {
            throw new \RuntimeException('Could not read the public vocabulary schemas.');
        }

        return $revision;
    }

    /** Validate only the response alternative advertised for this URL slug. */
    public function validate(string $slug, mixed $payload): void
    {
        $document = json_decode(File::get(resource_path('data/openapi.json')), flags: JSON_THROW_ON_ERROR);
        $operation = $document->paths->{'/api/v1/vocabularies/data/{vocab}'}->get;
        $alternatives = $operation->responses->{'200'}->content->{'application/json'}->schema->anyOf;
        $references = array_column($alternatives, '$ref', 'title');
        $resolver = new SchemaResolver;
        $resolver->registerRaw($document, self::DOCUMENT);
        $validator = (new Validator)->setResolver($resolver);

        if (! isset($references[$slug]) || ! $validator->validate($payload, self::DOCUMENT.$references[$slug])->isValid()) {
            throw new VocabularyCorruptedException("The snapshot does not match the {$slug} response schema.");
        }
    }
}
