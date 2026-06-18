<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\Size;

final class SizeFormatSuggestionAcceptanceService
{
    public function __construct(
        private readonly SizeFormatSizeParser $sizeParser,
    ) {}

    /** @return array{success: bool, message: string} */
    public function accept(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type === 'format') {
            Format::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'value' => $suggestion->suggested_value,
            ]);

            return [
                'success' => true,
                'message' => "Format '{$suggestion->suggested_value}' applied.",
            ];
        }

        if ($suggestion->target_type === 'size') {
            /** @var array{numeric_value?: string|null, unit?: string|null, type?: string|null}|null $storedParsedSize */
            $storedParsedSize = is_array($suggestion->metadata)
                ? ($suggestion->metadata['parsed_size'] ?? null)
                : null;
            $parsedSize = $storedParsedSize ?? $this->sizeParser->parse($suggestion->suggested_value);

            Size::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'numeric_value' => $parsedSize['numeric_value'] ?? null,
                'unit' => $parsedSize['unit'] ?? null,
                'type' => $parsedSize['type'] ?? null,
            ]);

            return [
                'success' => true,
                'message' => "Size '{$suggestion->suggested_value}' applied.",
            ];
        }

        return [
            'success' => false,
            'message' => 'Unknown suggestion type.',
        ];
    }
}
