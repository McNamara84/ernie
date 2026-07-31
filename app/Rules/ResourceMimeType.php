<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Format;
use App\Models\Resource;
use App\Services\SizeFormat\SizeFormatFormatNormalizerService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ResourceMimeType implements ValidationRule
{
    public function __construct(private readonly Resource $resource) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail('The selected format is invalid.');

            return;
        }

        $format = Format::query()
            ->where('resource_id', $this->resource->id)
            ->find((int) $value);

        $mimeType = $format === null
            ? ''
            : SizeFormatFormatNormalizerService::normalize($format->value);

        if ($format === null || preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/i', $mimeType) !== 1) {
            $fail('The selected format must be a valid MIME type belonging to this resource.');
        }
    }
}
