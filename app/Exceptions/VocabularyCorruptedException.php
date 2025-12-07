<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a vocabulary file contains invalid JSON.
 */
class VocabularyCorruptedException extends RuntimeException
{
    public function __construct(string $jsonError)
    {
        parent::__construct("Invalid JSON in vocabulary file: {$jsonError}");
    }
}
