<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPage;

use App\Http\Requests\LandingPage\Concerns\ValidatesLandingPageContentDescriptors;
use App\Http\Controllers\LandingPageController;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Rules\SafeUrl;
use App\Rules\ResourceDigitalSize;
use App\Rules\ResourceMimeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates payloads for storing a session-based landing page preview.
 *
 * Authorization is performed in the controller via `authorize('create', LandingPage::class)`.
 */
class StoreLandingPagePreviewRequest extends FormRequest
{
    use ValidatesLandingPageContentDescriptors;

    public function authorize(): bool
    {
        return $this->user() !== null;
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
            'ftp_format_id' => ['nullable', 'integer', new ResourceMimeType($resource)],
            'ftp_size_id' => ['nullable', 'integer', new ResourceDigitalSize($resource)],
            'downloads_unavailable' => ['sometimes', 'boolean'],
            'files' => ['nullable', 'array'],
            'files.*.id' => [
                'required',
                'integer',
                Rule::exists('landing_page_files', 'id')->where(
                    'landing_page_id',
                    $landingPageId,
                ),
            ],
            'files.*.format_id' => ['nullable', 'integer', new ResourceMimeType($resource)],
            'files.*.size_id' => ['nullable', 'integer', new ResourceDigitalSize($resource)],
        ];

        $template = $this->input('template');
        $supportsLinks = $template !== 'external'
            && ! in_array($template, LandingPageController::IGSN_ONLY_TEMPLATES, true);

        if ($supportsLinks) {
            $rules['links'] = ['nullable', 'array', 'max:10'];
            $rules['links.*.url'] = ['required', new SafeUrl, 'max:2048'];
            $rules['links.*.label'] = ['required', 'string', 'max:255'];
            $rules['links.*.kind'] = ['sometimes', 'string', Rule::in(LandingPageLink::KINDS)];
            $rules['links.*.format_id'] = ['nullable', 'integer', new ResourceMimeType($resource)];
            $rules['links.*.size_id'] = ['nullable', 'integer', new ResourceDigitalSize($resource)];
            $rules['links.*.position'] = ['required', 'integer', 'min:0', 'max:9', 'distinct'];
        }

        return $rules;
    }
}
