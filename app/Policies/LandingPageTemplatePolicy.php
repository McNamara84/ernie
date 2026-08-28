<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LandingPageTemplate;
use App\Models\User;

/**
 * Policy for LandingPageTemplate model authorization.
 *
 * Only Admin and Group Leader roles can manage landing page templates.
 * A built-in copy template (is_default=true) can only receive narrowly scoped
 * display-limit updates; immutable fields remain protected by the controller.
 */
class LandingPageTemplatePolicy
{
    /**
     * Roles that are allowed to manage landing page templates.
     *
     * @var list<UserRole>
     */
    private const MANAGEMENT_ROLES = [
        UserRole::ADMIN,
        UserRole::GROUP_LEADER,
    ];

    /**
     * Determine whether the user can view the template list.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Determine whether the user can create templates (clone from a copy template).
     */
    public function create(User $user): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Determine whether the user can update the template.
     * Built-in copy template field restrictions are enforced by the controller.
     */
    public function update(User $user, LandingPageTemplate $template): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Determine whether the user can delete the template.
     * Built-in copy templates cannot be deleted.
     * In-use check is enforced in the controller's destroy() method.
     */
    public function delete(User $user, LandingPageTemplate $template): bool
    {
        if ($template->isDefault()) {
            return false;
        }

        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }
}
