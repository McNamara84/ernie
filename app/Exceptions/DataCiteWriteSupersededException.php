<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DataCiteWriteSupersededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A newer DOI update superseded this request. Reload the resource before retrying.');
    }
}
