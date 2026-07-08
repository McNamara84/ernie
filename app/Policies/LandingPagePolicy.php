<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\User;

/**
 * Policy for LandingPage model authorization.
 *
 * Landing page setup is available to all ERNIE roles so Beginner users can
 * complete the training workflow. Deletion remains restricted to curator-level
 * roles and above.
 *
 * Role hierarchy for landing page management:
 * - ADMIN: Create, update, delete
 * - GROUP_LEADER: Create, update, delete
 * - CURATOR: Create, update, delete
 * - BEGINNER: Create and update only
 */
class LandingPagePolicy
{
    /**
     * Roles that are allowed to create and update landing pages.
     *
     * @var list<UserRole>
     */
    private const CREATE_UPDATE_ROLES = [
        UserRole::ADMIN,
        UserRole::GROUP_LEADER,
        UserRole::CURATOR,
        UserRole::BEGINNER,
    ];

    /**
     * Roles that are allowed to delete draft landing pages.
     *
     * @var list<UserRole>
     */
    private const DELETE_ROLES = [
        UserRole::ADMIN,
        UserRole::GROUP_LEADER,
        UserRole::CURATOR,
    ];

    /**
     * Determine whether the user can view the landing page.
     * All authenticated users can view landing pages.
     */
    public function view(User $user, LandingPage $landingPage): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create landing pages.
     * All ERNIE roles can create landing pages for the training workflow.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, self::CREATE_UPDATE_ROLES, true);
    }

    /**
     * Determine whether the user can update the landing page.
     * All ERNIE roles can update landing pages for the training workflow.
     */
    public function update(User $user, LandingPage $landingPage): bool
    {
        return in_array($user->role, self::CREATE_UPDATE_ROLES, true);
    }

    /**
     * Determine whether the user can delete the landing page.
     * Only Admin, Group Leader, and Curator roles can delete landing pages.
     *
     * Note: Published landing pages cannot be deleted regardless of role.
     * This business rule is enforced in the controller, not in this policy.
     */
    public function delete(User $user, LandingPage $landingPage): bool
    {
        return in_array($user->role, self::DELETE_ROLES, true);
    }
}
