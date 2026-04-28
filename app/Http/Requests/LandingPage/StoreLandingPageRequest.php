<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPage;

use App\Http\Controllers\LandingPageController;
use App\Rules\SafeUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates payloads for creating a landing page configuration for a resource.
 *
 * Authorization is performed in the controller via `authorize('create', LandingPage::class)`
 * because the existing tests rely on the controller's policy-based 403 contract.
 * This request focuses purely on input validation.
 */
class StoreLandingPageRequest extends FormRequest
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
            'template' => ['required', 'string', Rule::in(LandingPageController::ALLOWED_TEMPLATES)],
            'landing_page_template_id' => ['nullable', 'integer', 'exists:landing_page_templates,id'],
            'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
            'external_domain_id' => ['required_if:template,external', 'integer', 'exists:landing_page_domains,id'],
            'external_path' => ['required_if:template,external', 'string', 'max:2048'],
            'is_published' => 'boolean',
            'status' => 'sometimes|string|in:draft,published',
        ];

        $template = $this->input('template');
        $supportsLinks = $template !== 'external'
            && ! in_array($template, LandingPageController::IGSN_ONLY_TEMPLATES, true);

        if ($supportsLinks) {
            $rules['links'] = ['nullable', 'array', 'max:10'];
            $rules['links.*.url'] = ['required', new SafeUrl, 'max:2048'];
            $rules['links.*.label'] = ['required', 'string', 'max:255'];
            $rules['links.*.position'] = ['required', 'integer', 'min:0', 'max:9', 'distinct'];
        }

        return $rules;
    }
}
