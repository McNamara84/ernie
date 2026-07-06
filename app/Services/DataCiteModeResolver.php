<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Config;

final class DataCiteModeResolver
{
    public function shouldUseTestMode(?User $user = null): bool
    {
        if ((bool) Config::get('datacite.test_mode', true)) {
            return true;
        }

        return $user?->role === UserRole::BEGINNER;
    }

    public function isTestModeForcedForUser(?User $user = null): bool
    {
        return ! (bool) Config::get('datacite.test_mode', true)
            && $user?->role === UserRole::BEGINNER;
    }

    public function configKey(?User $user = null): string
    {
        return $this->shouldUseTestMode($user) ? 'test' : 'production';
    }

    /**
     * @return list<string>
     */
    public function allowedPrefixes(?User $user = null): array
    {
        return array_values((array) Config::get("datacite.{$this->configKey($user)}.prefixes", []));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataCiteConfig(?User $user = null): array
    {
        return (array) Config::get("datacite.{$this->configKey($user)}", []);
    }
}
