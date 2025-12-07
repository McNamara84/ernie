<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a vocabulary file is not found.
 */
class VocabularyNotFoundException extends RuntimeException
{
    public function __construct(string $command)
    {
        parent::__construct("Vocabulary file not found. Please run: {$command}");
    }
}
