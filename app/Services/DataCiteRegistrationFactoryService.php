<?php

declare(strict_types=1);

namespace App\Services;

final class DataCiteRegistrationFactoryService
{
    public function __construct(
        private readonly DataCiteRequestLimiter $limiter,
    ) {}

    public function forMode(bool $testMode): DataCiteRegistrationService
    {
        return new DataCiteRegistrationService($this->clientForMode($testMode));
    }

    public function clientForMode(bool $testMode): DataCiteMemberApiClient
    {
        return new DataCiteMemberApiClient($this->limiter, $testMode);
    }
}
