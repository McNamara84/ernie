<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Categories for DataCite contributor types.
 *
 * Determines whether a contributor type applies to persons,
 * institutions, or both entity types.
 */
enum ContributorCategory: string
{
    case PERSON = 'person';
    case INSTITUTION = 'institution';
    case BOTH = 'both';
}
