<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\Size;

final class SizeFormatSuggestionAcceptanceService
{
    public function __construct(
        private readonly SizeFormatSizeParserService $sizeParser,
    ) {}

    /** @return array{success: bool, message: string} */
    public function accept(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type === 'format') {
            $formatValue = SizeFormatFormatNormalizerService::normalize($suggestion->suggested_value);

            Format::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'value' => $formatValue,
            ]);

            return [
                'success' => true,
                'message' => "Format '{$formatValue}' applied.",
            ];
        }

        if ($suggestion->target_type === 'size') {
            /** @var array{numeric_value?: string|null, unit?: string|null, type?: string|null}|null $storedParsedSize */
            $storedParsedSize = is_array($suggestion->metadata)
                ? ($suggestion->metadata['parsed_size'] ?? null)
                : null;
            $parsedSize = $storedParsedSize ?? $this->sizeParser->parse($suggestion->suggested_value);

            $size = Size::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'numeric_value' => $parsedSize['numeric_value'] ?? null,
                'unit' => $parsedSize['unit'] ?? null,
                'type' => $parsedSize['type'] ?? null,
            ]);

            return [
                'success' => true,
                'message' => "Size '{$size->export_string}' applied.",
            ];
        }

        return [
            'success' => false,
            'message' => 'Unknown suggestion type.',
        ];
    }
}
