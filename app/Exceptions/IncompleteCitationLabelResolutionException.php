<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class IncompleteCitationLabelResolutionException extends RuntimeException
{
    /**
     * @param  array<string, string>  $failures  DOI or invalid DOI value => failure reason
     */
    public function __construct(
        public readonly array $failures,
    ) {
        $dois = array_keys($failures);
        $displayedDois = array_slice($dois, 0, 5);
        $suffix = count($dois) > count($displayedDois) ? ', …' : '';

        parent::__construct(sprintf(
            'Citation labels could not be resolved for %d related DOI(s): %s%s. The resource was not imported. Please retry when the DOI metadata service is available.',
            count($dois),
            implode(', ', $displayedDois),
            $suffix,
        ));
    }
}
