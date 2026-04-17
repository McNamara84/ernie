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
 * The default template (is_default=true) cannot be updated or deleted.
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
     * Determine whether the user can create templates (clone from default).
     */
    public function create(User $user): bool
    {
        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Determine whether the user can update the template.
     * Default templates cannot be modified.
     */
    public function update(User $user, LandingPageTemplate $template): bool
    {
        if ($template->isDefault()) {
            return false;
        }

        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Determine whether the user can delete the template.
     * Default templates and templates in use cannot be deleted.
     */
    public function delete(User $user, LandingPageTemplate $template): bool
    {
        if ($template->isDefault()) {
            return false;
        }

        return in_array($user->role, self::MANAGEMENT_ROLES, true);
    }
}
