<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/** Only an explicit negative Handle response without a provider PID is excludable. */
final class UnregisteredInstrumentHandle extends InvalidArgumentException
{
    public const REASON = 'unregistered_handle';

    public function __construct()
    {
        parent::__construct('Metadata Handle is not registered and has no ePIC provider PID.');
    }
}
