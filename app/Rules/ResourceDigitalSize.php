<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Resource;
use App\Models\Size;
use App\Services\SizeFormat\DigitalContentSizeService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ResourceDigitalSize implements ValidationRule
{
    public function __construct(private readonly Resource $resource) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail('The selected size is invalid.');

            return;
        }

        $size = Size::query()
            ->where('resource_id', $this->resource->id)
            ->find((int) $value);

        if ($size === null || ! app(DigitalContentSizeService::class)->isEligible($size, $this->resource)) {
            $fail('The selected size must be a digital size belonging to this non-IGSN resource.');
        }
    }
}
