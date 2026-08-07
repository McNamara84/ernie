<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class IncompleteCitationLabelResolutionException extends RuntimeException
{
    /**
     * @param  array<string, string>  $failures  Related identifier or placeholder => failure reason
     */
    public function __construct(
        public readonly array $failures,
    ) {
        $identifiers = array_keys($failures);
        $displayedIdentifiers = array_slice($identifiers, 0, 5);
        $suffix = count($identifiers) > count($displayedIdentifiers) ? ', …' : '';

        parent::__construct(sprintf(
            'Citation labels could not be resolved for %d related identifier(s): %s%s. The resource was not imported. Please correct invalid identifiers or retry when the DOI metadata service is available.',
            count($identifiers),
            implode(', ', $displayedIdentifiers),
            $suffix,
        ));
    }
}
