<?php

namespace App\Http\Middleware;

class EnsureValidErnieApiKey extends EnsureValidApiKey
{
    protected function serviceName(): string
    {
        return 'ernie';
    }
}
