<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPageDomain;

use App\Rules\SafeDomainUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates payloads for creating a new landing page domain.
 *
 * Authorization is enforced upstream by the `access-editor-settings` route
 * middleware (Admin and Group Leader only); this request only verifies that
 * an authenticated user is present and normalises the `domain` input.
 */
class StoreLandingPageDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalize the domain before validation: trim whitespace and ensure a
     * trailing slash so `max:768` and `unique:` checks apply to the stored
     * form (matches the previous controller logic).
     */
    protected function prepareForValidation(): void
    {
        $domain = trim((string) $this->input('domain'));

        if ($domain !== '' && ! str_ends_with($domain, '/')) {
            $domain .= '/';
        }

        $this->merge(['domain' => $domain]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', new SafeDomainUrl, 'max:768', 'unique:landing_page_domains,domain'],
        ];
    }
}
