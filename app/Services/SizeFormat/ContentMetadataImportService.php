<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

final readonly class ContentMetadataImportService
{
    public function __construct(private SizeFormatSizeParserService $sizeParser) {}

    /** @return list<array<string, mixed>> */
    public function formats(mixed $values): array
    {
        $rows = [];
        $seen = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $key = SizeFormatFormatNormalizerService::normalize($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $row = ['value' => trim($value)];
                $rows[] = ResourceContentMetadataService::signedImport('format', $row);
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function sizes(mixed $values): array
    {
        $rows = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if (preg_match('/\A(\d+(?:\.\d+)?)\s+(.+?)\s*\[([^\]]+)\]\z/', $value, $match) === 1) {
                $row = ['numeric_value' => $match[1], 'unit' => trim($match[3]), 'type' => trim($match[2])];
            } else {
                $row = $this->sizeParser->parse($value);
            }
            if ($row['numeric_value'] !== null && ResourceContentMetadataService::number($row['numeric_value']) === null) {
                // Preserve text that cannot fit the structured decimal column.
                $row = ['numeric_value' => null, 'unit' => $value, 'type' => null];
            }
            $number = ResourceContentMetadataService::number($row['numeric_value']);
            $rows[] = [...ResourceContentMetadataService::signedImport('size', $row),
                'editable' => $number !== null && $number !== '0' && in_array(ResourceContentMetadataService::unit($row['unit']), ResourceContentMetadataService::UNITS, true),
            ];
        }

        return $rows;
    }
}
