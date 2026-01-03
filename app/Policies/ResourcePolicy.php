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
     * Determine whether the user can change the DOI of a resource.
     *
     * Changing a DOI for a resource with a published landing page is a destructive
     * operation: all existing citations, bookmarks, and external links to the old
     * URL will break (404 without redirect). This method prevents such changes
     * unless the user has Admin privileges.
     *
     * @param  User  $user  The user attempting the change
     * @param  Resource  $resource  The resource whose DOI would be changed
     * @param  string|null  $newDoi  The new DOI value (null = removing DOI)
     * @return bool True if DOI change is allowed
     */
    public function changeDoi(User $user, Resource $resource, ?string $newDoi = null): bool
    {
        // If DOI isn't actually changing, allow it
        if ($resource->doi === $newDoi) {
            return true;
        }

        // If there's no published landing page, DOI changes are always safe
        $landingPage = $resource->landingPage;
        if (! $landingPage || ! $landingPage->is_published) {
            return true;
        }

        // Only admins can change DOI on published landing pages
        // This is a destructive operation that breaks existing URLs
        return $user->role === UserRole::ADMIN;
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
