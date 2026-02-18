<?php

declare(strict_types=1);

namespace App\Http\Middleware;

class EnsureValidErnieApiKey extends EnsureValidApiKey
{
    protected function serviceName(): string
    {
        return 'ernie';
    }
}
