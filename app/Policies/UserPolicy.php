<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view the user management page.
     * Only admins and group leaders can manage users.
     */
    public function viewAny(User $user): bool
    {
        return $user->canManageUsers();
    }

    /**
     * Determine whether the user can view the model.
     * Users can view their own profile, admins and group leaders can view all users.
     */
    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->canManageUsers();
    }

    /**
     * Determine whether the user can create new users.
     * Only admins and group leaders can create users.
     */
    public function create(User $user): bool
    {
        return $user->canManageUsers();
    }

    /**
     * Determine whether the user can update the model.
     * Only admins and group leaders can update user information.
     */
    public function update(User $user, User $model): bool
    {
        // Users can update their own profile (font preferences, etc.)
        if ($user->id === $model->id) {
            return true;
        }

        // Admins and group leaders can update other users
        return $user->canManageUsers();
    }

    /**
     * Determine whether the user can change the role of another user.
     * Admins can promote to any role, group leaders cannot promote to group leader or admin.
     * Users cannot change their own role or promote user ID 1.
     */
    public function changeRole(User $user, User $targetUser, UserRole $newRole): Response
    {
        // Cannot change your own role
        if ($user->id === $targetUser->id) {
            return Response::deny('You cannot change your own role.');
        }

        // Cannot change role of user ID 1 (system admin)
        if ($targetUser->id === 1) {
            return Response::deny('The system administrator role cannot be changed.');
        }

        // Only admins and group leaders can manage users
        if (! $user->canManageUsers()) {
            return Response::deny('You do not have permission to change user roles.');
        }

        // Check if the user can promote to the target role
        if (! in_array($newRole, $user->role->getPromotableRoles(), true)) {
            return Response::deny('You cannot promote users to this role.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can deactivate another user.
     * Admins and group leaders can deactivate users, but not themselves or user ID 1.
     */
    public function deactivate(User $user, User $targetUser): Response
    {
        // Cannot deactivate yourself
        if ($user->id === $targetUser->id) {
            return Response::deny('You cannot deactivate your own account.');
        }

        // Cannot deactivate user ID 1 (system admin)
        if ($targetUser->id === 1) {
            return Response::deny('The system administrator cannot be deactivated.');
        }

        // Only admins and group leaders can manage users
        if (! $user->canManageUsers()) {
            return Response::deny('You do not have permission to deactivate users.');
        }

        // User must be active to be deactivated
        if (! $targetUser->is_active) {
            return Response::deny('This user is already deactivated.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can reactivate another user.
     * Only admins and group leaders can reactivate users.
     */
    public function reactivate(User $user, User $targetUser): Response
    {
        // Only admins and group leaders can manage users
        if (! $user->canManageUsers()) {
            return Response::deny('You do not have permission to reactivate users.');
        }

        // User must be inactive to be reactivated
        if ($targetUser->is_active) {
            return Response::deny('This user is already active.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can reset the password of another user.
     * Admins and group leaders can reset passwords, but not for user ID 1.
     */
    public function resetPassword(User $user, User $targetUser): Response
    {
        // Cannot reset password of user ID 1 (system admin) unless you are user ID 1
        if ($targetUser->id === 1 && $user->id !== 1) {
            return Response::deny('You cannot reset the system administrator password.');
        }

        // Only admins and group leaders can reset passwords
        if (! $user->canManageUsers()) {
            return Response::deny('You do not have permission to reset user passwords.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can delete the model.
     * Users cannot be deleted to preserve data integrity (resources linkage).
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     * Users cannot be permanently deleted to preserve data integrity.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
