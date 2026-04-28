<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPage;

use App\Http\Controllers\LandingPageController;
use App\Rules\SafeUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates payloads for storing a session-based landing page preview.
 *
 * Authorization is performed in the controller via `authorize('create', LandingPage::class)`.
 */
class StoreLandingPagePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'template' => ['required', 'string', Rule::in(LandingPageController::ALLOWED_TEMPLATES)],
            'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
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
