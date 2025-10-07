<?php

namespace App\Http\Middleware;

class EnsureValidElmoApiKey extends EnsureValidApiKey
{
    protected function serviceName(): string
    {
        return 'elmo';
    }
}
