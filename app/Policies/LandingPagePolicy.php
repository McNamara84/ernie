<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\User;

/**
 * Policy for LandingPage model authorization.
 *
 * Landing page management is restricted to users with at least Curator role.
 * Beginners can view landing pages but cannot create, update, or delete them.
 *
 * Role hierarchy for landing page management:
 * - ADMIN: Full access
 * - GROUP_LEADER: Full access
 * - CURATOR: Full access
 * - BEGINNER: View only (no create/update/delete)
 */
class LandingPagePolicy
{
    /**
     * Roles that are allowed to manage landing pages.
     *
     * @var list<UserRole>
     */
    private const MANAGEMENT_ROLES = [
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
     * Only Admin, Group Leader, and Curator roles can create landing pages.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Determine whether the user can update the landing page.
     * Only Admin, Group Leader, and Curator roles can update landing pages.
     */
    public function update(User $user, LandingPage $landingPage): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
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
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Determine whether the user can manage landing pages (general check).
     * Used for UI visibility checks via Inertia shared data.
     */
    public function manage(User $user): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }
}
