<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPage;

use App\Http\Controllers\LandingPageController;
use App\Rules\SafeUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates payloads for updating an existing landing page configuration.
 *
 * Authorization is performed in the controller via `authorize('update', $landingPage)`
 * because the policy needs the route-bound landing page model to evaluate access.
 * This request focuses purely on input validation, including conditional rules
 * for templates that support additional links.
 */
class UpdateLandingPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'template' => ['sometimes', 'string', Rule::in(LandingPageController::ALLOWED_TEMPLATES)],
            'landing_page_template_id' => ['nullable', 'integer', 'exists:landing_page_templates,id'],
            'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
            'links' => ['nullable', 'array', 'max:10'],
            'links.*.url' => ['required', new SafeUrl, 'max:2048'],
            'links.*.label' => ['required', 'string', 'max:255'],
            'links.*.position' => ['required', 'integer', 'min:0', 'max:9', 'distinct'],
            'external_domain_id' => ['required_if:template,external', 'integer', 'exists:landing_page_domains,id'],
            'external_path' => ['required_if:template,external', 'string', 'max:2048'],
            'is_published' => 'sometimes|boolean',
            'status' => 'sometimes|string|in:draft,published',
        ];

        return $rules;
    }
}
