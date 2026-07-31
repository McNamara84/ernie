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
 * Validates payloads for updating an existing landing page configuration.
 *
 * Authorization is performed in the controller via `authorize('update', $landingPage)`
 * because the policy needs the route-bound landing page model to evaluate access.
 * This request focuses purely on input validation, including conditional rules
 * for templates that support additional links.
 */
class UpdateLandingPageRequest extends FormRequest
{
    use ValidatesLandingPageContentDescriptors;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        /** @var Resource $resource */
        $resource = $this->route('resource');
        $landingPageId = (int) $resource->landingPage()->value('id');
        $rules = [
            'template' => ['sometimes', 'string', Rule::in(LandingPageController::ALLOWED_TEMPLATES)],
            'landing_page_template_id' => ['nullable', 'integer', 'exists:landing_page_templates,id'],
            'ftp_url' => ['nullable', new SafeUrl, 'max:2048'],
            'ftp_format_id' => ['nullable', 'integer', new ResourceMimeType($resource)],
            'ftp_size_id' => ['nullable', 'integer', new ResourceDigitalSize($resource)],
            'downloads_unavailable' => ['sometimes', 'boolean'],
            'links' => ['nullable', 'array', 'max:10'],
            'links.*.url' => ['required', new SafeUrl, 'max:2048'],
            'links.*.label' => ['required', 'string', 'max:255'],
            'links.*.kind' => ['sometimes', 'string', Rule::in(LandingPageLink::KINDS)],
            'links.*.format_id' => ['nullable', 'integer', new ResourceMimeType($resource)],
            'links.*.size_id' => ['nullable', 'integer', new ResourceDigitalSize($resource)],
            'links.*.position' => ['required', 'integer', 'min:0', 'max:9', 'distinct'],
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
            'external_domain_id' => ['required_if:template,external', 'integer', 'exists:landing_page_domains,id'],
            'external_path' => ['required_if:template,external', 'string', 'max:2048'],
            'is_published' => 'sometimes|boolean',
            'status' => 'sometimes|string|in:draft,published',
        ];

        return $rules;
    }
}
