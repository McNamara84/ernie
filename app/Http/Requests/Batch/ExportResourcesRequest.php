<?php

declare(strict_types=1);

namespace App\Http\Requests\Batch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates batch export of resources as a ZIP archive in one of the
 * supported DataCite formats.
 */
class ExportResourcesRequest extends FormRequest
{
    /**
     * Maximum number of resources that can be exported in a single batch.
     */
    public const MAX_BATCH_SIZE = 100;

    public const FORMAT_JSON = 'datacite-json';

    public const FORMAT_XML = 'datacite-xml';

    public const FORMAT_JSONLD = 'jsonld';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'ids.*' => ['required', 'integer', 'exists:resources,id'],
            'format' => ['required', 'string', 'in:'.self::FORMAT_JSON.','.self::FORMAT_XML.','.self::FORMAT_JSONLD],
        ];
    }
}
