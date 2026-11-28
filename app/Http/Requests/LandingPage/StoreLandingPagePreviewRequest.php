<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPage;

use App\Enums\TombstoneReason;
use App\Http\Controllers\LandingPageController;
use App\Models\LandingPageLink;
use App\Models\Resource;
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'downloads_unavailable.missing' => 'Download availability is automatic. Use activate_downloads to release previously hidden downloads.',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Resource $resource */
        $resource = $this->route('resource');
        $landingPageId = (int) $resource->landingPage()->value('id');
        $rules = [
            'template' => ['required', 'string', Rule::in(LandingPageController::ALLOWED_TEMPLATES)],
            'landing_page_template_id' => ['nullable', 'integer'],
            'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
            'primary_download_label' => ['nullable', 'string', 'max:255'],
            'ftp_format_id' => ['exclude'],
            'ftp_size_id' => ['exclude'],
            'is_tombstone' => ['sometimes', 'boolean'],
            'tombstone_reason' => ['required_if:is_tombstone,true', Rule::enum(TombstoneReason::class)],
            'tombstone_statement' => ['required_if:is_tombstone,true', 'string', 'max:5000'],
            'downloads_unavailable' => ['missing'],
            'download_activation_required' => ['missing'],
            'activate_downloads' => ['sometimes', 'boolean'],
            'files' => ['nullable', 'array'],
            'files.*.id' => [
                'required',
                'integer',
                Rule::exists('landing_page_files', 'id')->where(
                    'landing_page_id',
                    $landingPageId,
                ),
            ],
            'files.*.format_id' => ['exclude'],
            'files.*.size_id' => ['exclude'],
            'files.*.label' => ['nullable', 'string', 'max:255'],
        ];

        $template = $this->input('template');
        $supportsLinks = $template !== 'external'
            && ! in_array($template, LandingPageController::IGSN_ONLY_TEMPLATES, true);

        if ($supportsLinks) {
            $rules['links'] = ['nullable', 'array', 'max:10'];
            $rules['links.*.id'] = ['sometimes', 'integer', 'distinct', Rule::exists('landing_page_links', 'id')->where('landing_page_id', $landingPageId)];
            $rules['links.*.url'] = ['required', new SafeUrl, 'max:2048'];
            $rules['links.*.label'] = ['required', 'string', 'max:255'];
            $rules['links.*.kind'] = ['sometimes', 'string', Rule::in(LandingPageLink::KINDS)];
            $rules['links.*.format_id'] = ['exclude'];
            $rules['links.*.size_id'] = ['exclude'];
            $rules['links.*.position'] = ['required', 'integer', 'min:0', 'max:9', 'distinct'];
        }

        return $rules;
    }
}
