<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;

final class ResourceCurationPolicy
{
    public function view(User $user, Resource $resource): bool
    {
        return $user->is_active && ! $resource->isIgsn() && $resource->igsnMetadata === null;
    }

    public function update(User $user, Resource $resource): bool
    {
        return $this->view($user, $resource) && $user->role !== UserRole::BEGINNER && ! $resource->landingPage?->is_tombstone;
    }
}
