<?php

declare(strict_types=1);

namespace App\Services\Iso19115;

use App\Support\Iso19115\Iso19115ValidationResult;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Throwable;

/** Validates the complete ISO/TR 19115-4 document without network access. */
class Iso19115JsonValidator
{
    /** @var list<string> */
    private const FILES = [
        'json/19115/-4/mdj/1.0.0/19115-4.json',
        'json/19115/-4/mdj/1.0.0/mdj.json',
        'json/19115/-4/mdj/1.0.0/Feature.json',
        'json/19157/-1/dqc/1.0.0/dqc.json',
    ];

    /** @param array<string, mixed> $document */
    public function validate(array $document): Iso19115ValidationResult
    {
        try {
            // A fresh, explicitly populated resolver has no remote protocols or directory fallback.
            $resolver = new SchemaResolver;
            foreach (self::FILES as $file) {
                $path = config('iso19115.json.directory').'/'.$file;
                if (! is_file($path) || ! is_readable($path)) {
                    throw new RuntimeException('The pinned ISO 19115-4 schema package is incomplete.');
                }
                $contents = file_get_contents($path);
                if (! is_string($contents) || ! $resolver->registerRaw(json_decode($contents, false, 512, JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('An ISO 19115-4 schema is invalid.');
                }
            }

            $validator = new Validator;
            $validator->loader()->setResolver($resolver);
            $validator->parser()->setOption('allowFormats', true);
            $validator->setMaxErrors(50);
            $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            $result = $validator->validate($data, (string) config('iso19115.json.schema'));

            if ($result->isValid()) {
                return new Iso19115ValidationResult;
            }

            $errors = [];
            $error = $result->error();
            if ($error === null) {
                throw new RuntimeException('Schema validation failed without diagnostics.');
            }
            foreach ((new ErrorFormatter)->format($error) as $path => $messages) {
                // Values may contain private data; report only paths and validation messages.
                foreach ($messages as $message) {
                    $errors[] = $path.': '.$message;
                }
            }

            return new Iso19115ValidationResult($errors);
        } catch (Throwable $exception) {
            return new Iso19115ValidationResult(['ISO 19115-4 validation could not complete ('.$exception::class.').']);
        }
    }
}
