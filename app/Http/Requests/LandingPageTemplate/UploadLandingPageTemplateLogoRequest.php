<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPageTemplate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates payloads for uploading a custom logo to a landing page template.
 *
 * Authorization is performed in the controller via `authorize('update', $landingPageTemplate)`
 * (the policy needs the route-bound model). Default-template protection also lives in
 * the controller because it returns a custom 403 JSON contract.
 */
class UploadLandingPageTemplateLogoRequest extends FormRequest
{
    /**
     * Maximum logo file size in kilobytes.
     */
    public const MAX_LOGO_SIZE_KB = 2048;

    /**
     * Allowed MIME types for logo uploads.
     *
     * @var list<string>
     */
    public const ALLOWED_LOGO_MIMES = ['png', 'jpg', 'jpeg', 'webp'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'mimes:'.implode(',', self::ALLOWED_LOGO_MIMES),
                'max:'.self::MAX_LOGO_SIZE_KB,
            ],
        ];
    }
}
