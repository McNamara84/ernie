<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when attempting to create a resource that already exists.
 *
 * This exception is used within database transactions to signal that a
 * duplicate resource was detected, allowing proper transaction semantics
 * while still communicating the conflict to the caller.
 *
 * @see \App\Http\Controllers\LandingPageController::store()
 */
class ResourceAlreadyExistsException extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @param  string  $resourceType  The type of resource (e.g., 'landing page')
     * @param  int|string  $identifier  The identifier of the conflicting resource
     */
    public function __construct(
        public readonly string $resourceType,
        public readonly int|string $identifier,
        string $message = ''
    ) {
        $defaultMessage = ucfirst($resourceType) . " already exists for identifier: {$identifier}";
        parent::__construct($message ?: $defaultMessage);
    }
}
