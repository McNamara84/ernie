<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;

/**
 * Policy for Resource model authorization.
 */
class ResourcePolicy
{
    public const DOI_CHANGE_UNAUTHORIZED_MESSAGE = 'You are not authorized to change the DOI for this resource.';

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
        if ($user->role === UserRole::ADMIN || $user->role === UserRole::GROUP_LEADER) {
            return true;
        }

        if ($user->role !== UserRole::CURATOR) {
            return false;
        }

        $resource->loadMissing([
            'landingPage',
            'titles.titleType',
            'creators',
            'rights',
            'descriptions.descriptionType',
        ]);

        return $resource->publicStatus() !== 'published';
    }

    /**
     * Determine whether the user can change the DOI of a resource.
     *
     * Changing a DOI for a resource with a published landing page is a destructive
     * operation: all existing citations, bookmarks, and external links to the old
     * URL will break (404 without redirect). Before publication, Admins, Group
     * Leaders, and Curators may correct it; after publication only Admins may do so.
     *
     * @param  User  $user  The user attempting the change
     * @param  Resource  $resource  The resource whose DOI would be changed
     * @param  string|null  $newDoi  The new DOI value (null = removing DOI)
     * @return bool True if DOI change is allowed
     */
    public function changeDoi(User $user, Resource $resource, ?string $newDoi = null): bool
    {
        if ($resource->doi === $newDoi) {
            return true;
        }

        return $this->editDoi($user, $resource);
    }

    /**
     * Determine whether the user may edit the DOI field for this resource.
     */
    public function editDoi(User $user, Resource $resource): bool
    {
        if ($user->role === UserRole::ADMIN) {
            return true;
        }

        if ($resource->doi !== null && $resource->doi !== '' && $resource->landingPage?->is_published) {
            return false;
        }

        return $user->role === UserRole::GROUP_LEADER
            || $user->role === UserRole::CURATOR;
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
