<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPageTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;

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

    public const MIN_LOGO_WIDTH = 960;

    public const MIN_LOGO_HEIGHT = 192;

    public const RECOMMENDED_LOGO_WIDTH = 1200;

    public const RECOMMENDED_LOGO_HEIGHT = 240;

    public const MAX_LOGO_WIDTH = 1920;

    public const MAX_LOGO_HEIGHT = 384;

    public const LOGO_ASPECT_RATIO = 5;

    public const LOGO_ASPECT_RATIO_LABEL = '5:1';

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
     * @return array<string, list<string|Dimensions>>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'mimes:'.implode(',', self::ALLOWED_LOGO_MIMES),
                'max:'.self::MAX_LOGO_SIZE_KB,
                Rule::dimensions()
                    ->minWidth(self::MIN_LOGO_WIDTH)
                    ->minHeight(self::MIN_LOGO_HEIGHT)
                    ->maxWidth(self::MAX_LOGO_WIDTH)
                    ->maxHeight(self::MAX_LOGO_HEIGHT)
                    ->ratio(self::LOGO_ASPECT_RATIO),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.dimensions' => sprintf(
                'The header logo must use a %s aspect ratio and measure between %d x %d and %d x %d pixels. The recommended size is %d x %d pixels.',
                self::LOGO_ASPECT_RATIO_LABEL,
                self::MIN_LOGO_WIDTH,
                self::MIN_LOGO_HEIGHT,
                self::MAX_LOGO_WIDTH,
                self::MAX_LOGO_HEIGHT,
                self::RECOMMENDED_LOGO_WIDTH,
                self::RECOMMENDED_LOGO_HEIGHT,
            ),
        ];
    }

    /**
     * @return array{
     *     minWidth: int,
     *     minHeight: int,
     *     recommendedWidth: int,
     *     recommendedHeight: int,
     *     maxWidth: int,
     *     maxHeight: int,
     *     aspectRatio: string,
     *     maxSizeKb: int,
     *     formats: list<string>
     * }
     */
    public static function uploadConstraints(): array
    {
        return [
            'minWidth' => self::MIN_LOGO_WIDTH,
            'minHeight' => self::MIN_LOGO_HEIGHT,
            'recommendedWidth' => self::RECOMMENDED_LOGO_WIDTH,
            'recommendedHeight' => self::RECOMMENDED_LOGO_HEIGHT,
            'maxWidth' => self::MAX_LOGO_WIDTH,
            'maxHeight' => self::MAX_LOGO_HEIGHT,
            'aspectRatio' => self::LOGO_ASPECT_RATIO_LABEL,
            'maxSizeKb' => self::MAX_LOGO_SIZE_KB,
            'formats' => ['PNG', 'JPG', 'JPEG', 'WebP'],
        ];
    }
}
