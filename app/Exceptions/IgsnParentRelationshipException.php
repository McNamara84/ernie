<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class IgsnParentRelationshipException extends RuntimeException
{
    public const FAILURE_CODE = 'parent_relationship_conflict';
}
