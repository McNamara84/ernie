<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait ValidatesContentMetadata
{
    /** @return array<string, list<string>> */
    private function contentMetadataRules(): array
    {
        return [
            'contentMetadataChanged' => ['sometimes', 'boolean'],
            'contentMetadataFingerprint' => ['sometimes', 'nullable', 'string', 'size:64'],
            'formats' => ['sometimes', 'array', 'max:'.self::MAX_REPEATABLE_METADATA_ITEMS],
            'formats.*' => ['array:id,value,importKey'],
            'formats.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'formats.*.value' => ['present', 'nullable', 'string', 'max:255'],
            'formats.*.importKey' => ['sometimes', 'string', 'max:8192'],
            'sizes' => ['sometimes', 'array', 'max:'.self::MAX_REPEATABLE_METADATA_ITEMS],
            'sizes.*' => ['array:id,numeric_value,unit,type,importKey'],
            'sizes.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'sizes.*.numeric_value' => ['present', 'nullable', 'string', 'max:64'],
            'sizes.*.unit' => ['present', 'nullable', 'string', 'max:255'],
            'sizes.*.type' => ['present', 'nullable', 'string', 'max:100'],
            'sizes.*.importKey' => ['sometimes', 'string', 'max:8192'],
        ];
    }
}
