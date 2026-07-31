<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessLevel: string
{
    case OPEN = 'open';
    case RESTRICTED = 'restricted';
    case EMBARGOED = 'embargoed';
    case METADATA_ONLY = 'metadata-only';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open access',
            self::RESTRICTED => 'Restricted access',
            self::EMBARGOED => 'Embargoed access',
            self::METADATA_ONLY => 'Metadata only access',
        };
    }

    public function coarUri(): string
    {
        return match ($this) {
            self::OPEN => 'http://purl.org/coar/access_right/c_abf2',
            self::RESTRICTED => 'http://purl.org/coar/access_right/c_16ec',
            self::EMBARGOED => 'http://purl.org/coar/access_right/c_f1cf',
            self::METADATA_ONLY => 'http://purl.org/coar/access_right/c_14cb',
        };
    }

    public function coarIdentifier(): string
    {
        return match ($this) {
            self::OPEN => 'c_abf2',
            self::RESTRICTED => 'c_16ec',
            self::EMBARGOED => 'c_f1cf',
            self::METADATA_ONLY => 'c_14cb',
        };
    }

    public static function coarScheme(): string
    {
        return 'COAR Access Rights';
    }

    public static function coarSchemeUri(): string
    {
        return 'http://purl.org/coar/access_right/';
    }

    public function isAccessibleForFree(): bool
    {
        return $this === self::OPEN;
    }

    public static function fromCoarUri(?string $uri): ?self
    {
        if ($uri === null || trim($uri) === '') {
            return null;
        }

        $normalized = preg_replace('#^https://#i', 'http://', trim($uri));

        foreach (self::cases() as $level) {
            if (strcasecmp($normalized ?? '', $level->coarUri()) === 0) {
                return $level;
            }
        }

        return null;
    }

    public static function fromSampleAccess(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[_-]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return match ($normalized) {
            'open', 'public', 'free', 'unrestricted', 'open access' => self::OPEN,
            'restricted', 'limited', 'restricted access' => self::RESTRICTED,
            'embargo', 'embargoed', 'embargoed access' => self::EMBARGOED,
            'metadata only', 'metadata only access' => self::METADATA_ONLY,
            default => null,
        };
    }
}
