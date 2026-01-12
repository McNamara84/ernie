<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeNewUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Handles the welcome flow for new user accounts.
 *
 * This controller manages the password setup process for newly created users.
 * It uses signed URLs instead of password reset tokens for enhanced security.
 */
class WelcomeController extends Controller
{
    /**
     * Show the password setup form for new users.
     *
     * Displays either the password setup form (if link is valid) or
     * the expired link page with resend option (if link has expired).
     */
    public function show(Request $request, User $user): Response|RedirectResponse
    {
        // Check if URL signature is valid
        if (! $request->hasValidSignature()) {
            return Inertia::render('auth/welcome-expired', [
                'email' => $user->email,
            ]);
        }

        // Check if user already has set their password
        if ($user->password_set_at !== null) {
            return redirect()->route('login')
                ->with('status', 'Your password has already been set. Please log in.');
        }

        return Inertia::render('auth/welcome', [
            'email' => $user->email,
            'userId' => $user->id,
        ]);
    }

    /**
     * Set the password for a new user.
     *
     * Validates the signed URL and password, then updates the user's password.
     * After successful password setup, redirects to login page.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        // Verify signature again for security
        if (! $request->hasValidSignature()) {
            return redirect()->route('login')
                ->with('error', 'This link has expired. Please request a new welcome email.');
        }

        // Prevent setting password twice
        if ($user->password_set_at !== null) {
            return redirect()->route('login')
                ->with('status', 'Your password has already been set. Please log in.');
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
        ]);

        return redirect()->route('login')
            ->with('status', 'Your password has been set successfully. Please log in.');
    }

    /**
     * Resend the welcome email to a user.
     *
     * Only sends email if user exists and hasn't set their password yet.
     * Always returns success message to prevent email enumeration attacks.
     */
    public function resend(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Only resend if user exists and hasn't set password yet
        if ($user !== null && $user->password_set_at === null) {
            try {
                Mail::to($user->email)->send(new WelcomeNewUser($user));
            } catch (TransportExceptionInterface $e) {
                Log::error('Failed to resend welcome email - mail transport error', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to resend welcome email - unexpected error', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
            }
        }

        // Always show success to prevent email enumeration
        return redirect()->route('login')
            ->with('status', "If your account exists and hasn't been activated yet, a new welcome email has been sent.");
    }
}
