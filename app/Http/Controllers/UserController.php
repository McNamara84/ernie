<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\DeactivateUserRequest;
use App\Http\Requests\ReactivateUserRequest;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of users.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['deactivatedBy:id,name'])
            ->orderBy('id')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'is_active' => $user->is_active,
                    'deactivated_at' => $user->deactivated_at !== null ? $user->deactivated_at->toISOString() : null,
                    'deactivated_by' => $user->deactivatedBy ? [
                        'id' => $user->deactivatedBy->id,
                        'name' => $user->deactivatedBy->name,
                    ] : null,
                    'created_at' => $user->created_at->toISOString(),
                ];
            });

        /** @var User $authUser */
        $authUser = auth()->user();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'available_roles' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])->toArray(),
            'can_promote_to_group_leader' => $authUser->role->canPromoteToGroupLeader(),
        ]);
    }

    /**
     * Update the role of the specified user.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $newRole = UserRole::from($request->validated()['role']);

        $user->update([
            'role' => $newRole,
        ]);

        return redirect()->back()->with('success', "User role updated to {$newRole->label()}.");
    }

    /**
     * Deactivate the specified user.
     */
    public function deactivate(DeactivateUserRequest $request, User $user): RedirectResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $user->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivated_by' => $authUser->id,
        ]);

        return redirect()->back()->with('success', 'User has been deactivated.');
    }

    /**
     * Reactivate the specified user.
     */
    public function reactivate(ReactivateUserRequest $request, User $user): RedirectResponse
    {
        $user->update([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivated_by' => null,
        ]);

        return redirect()->back()->with('success', 'User has been reactivated.');
    }

    /**
     * Send a password reset link to the specified user.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $status = Password::sendResetLink([
            'email' => $user->email,
        ]);

        if ($status === Password::RESET_LINK_SENT) {
            return redirect()->back()->with('success', 'Password reset link has been sent to the user.');
        }

        return redirect()->back()->with('error', 'Failed to send password reset link.');
    }
}
