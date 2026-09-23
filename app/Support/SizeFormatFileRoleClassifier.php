<?php

declare(strict_types=1);

namespace App\Support;

final class SizeFormatFileRoleClassifier
{
    public const PRIMARY_DATA = 'primary_data';

    public const DATA_DESCRIPTION = 'data_description';

    public const METADATA_DOCUMENT = 'metadata_document';

    /**
     * @return array{role: string, rule: string|null}
     */
    public function classify(string $filenameOrUrl, ?string $label = null): array
    {
        $filename = $this->normalize($filenameOrUrl);
        $normalizedLabel = $this->normalize((string) $label);

        if ($this->containsDataDescription($normalizedLabel)) {
            return ['role' => self::DATA_DESCRIPTION, 'rule' => 'source_label_data_description'];
        }

        if ($this->containsDataDescription($filename)) {
            return ['role' => self::DATA_DESCRIPTION, 'rule' => 'filename_data_description'];
        }

        if ($normalizedLabel !== '' && preg_match('/(?:^|[\s._-])(metadata|documentation|readme)(?:$|[\s._-])/u', $normalizedLabel) === 1) {
            return ['role' => self::METADATA_DOCUMENT, 'rule' => 'source_label_metadata_document'];
        }

        return ['role' => self::PRIMARY_DATA, 'rule' => null];
    }

    private function normalize(string $value): string
    {
        $decoded = rawurldecode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));

        return mb_strtolower(trim(str_replace('\\', '/', $decoded)));
    }

    private function containsDataDescription(string $value): bool
    {
        return $value !== '' && preg_match('/(?:^|[\/\s._-])data[\s._-]*description(?:$|[\s._-])/u', $value) === 1;
    }
}
