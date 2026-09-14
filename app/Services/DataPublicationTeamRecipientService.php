<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Resolves the optional data publication team recipient from configuration.
 */
class DataPublicationTeamRecipientService
{
    public function email(bool $logInvalidConfiguration = false): ?string
    {
        $configuredEmail = config('mail.landing_page_contact_cc');

        if ($configuredEmail === null || $configuredEmail === '') {
            return null;
        }

        if (! is_string($configuredEmail)) {
            if ($logInvalidConfiguration) {
                Log::warning('Invalid Cc email address type in config');
            }

            return null;
        }

        $email = trim($configuredEmail);

        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            if ($logInvalidConfiguration) {
                Log::warning('Invalid Cc email address in config', ['cc_email' => $configuredEmail]);
            }

            return null;
        }

        return $email;
    }

    public function isAvailable(): bool
    {
        return $this->email() !== null;
    }
}
