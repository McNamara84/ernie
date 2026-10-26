<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use App\Models\FundingReference;
use App\Models\Resource;
use Normalizer;

final class AwardMetadata
{
    public const string ASSISTANT_ID = 'funding-award-uri-enrichment';

    public static function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim(Normalizer::normalize($value, Normalizer::FORM_C) ?: $value);
    }

    public static function number(mixed $value): ?string
    {
        $number = self::text($value);

        return preg_match('/^[\pL\pN][\pL\pN.\/_-]{0,254}$/uD', $number)
            && ! in_array(mb_strtolower($number), ['n/a', 'na', 'none', 'unknown', 'null', 'tbd'], true) ? $number : null;
    }

    public static function uri(mixed $value): ?string
    {
        $uri = self::text($value);
        if (strlen($uri) > 512 || preg_match('/[\s<>"\\\\]/u', $uri)) {
            return null;
        }
        if (preg_match('~^https://doi\.org/(10\.\d{4,9}/[^?#]+)$~iD', $uri, $match)) {
            return 'https://doi.org/'.strtolower($match[1]);
        }
        if (preg_match('~^https://cordis\.europa\.eu/project/id/[0-9]+$~D', $uri)) {
            return $uri;
        }

        return null;
    }

    /** @param array<array-key, mixed> $value */
    public static function hash(array $value): string
    {
        $normalize = static function (array $items) use (&$normalize): array {
            if (! array_is_list($items)) {
                ksort($items);
            }
            foreach ($items as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $items;
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public static function snapshot(FundingReference $funding): array
    {
        return [
            'id' => $funding->id, 'resource_id' => $funding->resource_id,
            'funder_name' => $funding->funder_name, 'funder_identifier' => $funding->funder_identifier,
            'funder_identifier_type_id' => $funding->funder_identifier_type_id, 'scheme_uri' => $funding->scheme_uri,
            'award_number' => $funding->award_number, 'award_title' => $funding->award_title,
            'award_uri' => $funding->award_uri,
        ];
    }

    public static function identity(FundingReference $funding, FunderIdentity $funder): string
    {
        return self::hash(['version' => 1, 'funder' => $funder->crossrefId,
            'number' => self::number($funding->award_number), 'title' => self::title($funding->award_title)]);
    }

    public static function title(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', self::text($value)) ?? '');
    }

    /** @param array<string, mixed> $metadata */
    public static function fingerprint(array $metadata): string
    {
        unset($metadata['review_fingerprint'], $metadata['review_status'], $metadata['decline_status'], $metadata['live_current']);

        return self::hash($metadata);
    }

    public static function writable(Resource $resource): bool
    {
        return $resource->embargo_registration_started_at === null && ! ($resource->landingPage->is_tombstone ?? false);
    }
}
