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

    private function isZipContentSuggestion(AssistantSuggestion $suggestion): bool
    {
        $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];

        return ($metadata['probe_method'] ?? null) === 'ZIP_CONTENT_LISTING';
    }

    private function removeZipContainerFormats(int $resourceId): void
    {
        Format::where('resource_id', $resourceId)
            ->get()
            ->each(function (Format $format): void {
                if (SizeFormatFormatNormalizerService::normalize((string) $format->value) === 'application/zip') {
                    $format->delete();
                }
            });
    }

    /** @return array{success: bool, message: string} */
    public function accept(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type === 'format') {
            $formatValue = SizeFormatFormatNormalizerService::normalize($suggestion->suggested_value);

            Format::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'value' => $formatValue,
            ]);

            if ($formatValue !== 'application/zip' && $this->isZipContentSuggestion($suggestion)) {
                $this->removeZipContainerFormats($suggestion->resource_id);
            }

            return [
                'success' => true,
                'message' => "Format '{$formatValue}' applied.",
            ];
        }

        if ($suggestion->target_type === 'size') {
            $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];
            $storedParsedSize = $metadata['parsed_size'] ?? null;
            $parsedSize = is_array($storedParsedSize)
                ? $storedParsedSize
                : $this->sizeParser->parse($suggestion->suggested_value);

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
