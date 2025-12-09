<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a vocabulary file cannot be read.
 */
class VocabularyReadException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Failed to read vocabulary file.');
    }
}
