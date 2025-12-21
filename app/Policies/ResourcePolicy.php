<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;

/**
 * Policy for Resource model authorization.
 */
class ResourcePolicy
{
    /**
     * Determine whether the user can view any resources.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the resource.
     */
    public function view(User $user, Resource $resource): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create resources.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the resource.
     */
    public function update(User $user, Resource $resource): bool
    {
        return true;
    }

    /**
     * Determine whether the user can delete the resource.
     */
    public function delete(User $user, Resource $resource): bool
    {
        // Only admins and group leaders can delete resources
        return $user->role === UserRole::ADMIN
            || $user->role === UserRole::GROUP_LEADER;
    }

    /**
     * Determine whether the user can import resources from DataCite.
     *
     * Only Admin and Group Leader users can perform bulk imports
     * from the DataCite API.
     */
    public function importFromDataCite(User $user): bool
    {
        return $user->role === UserRole::ADMIN
            || $user->role === UserRole::GROUP_LEADER;
    }
}
