<?php

declare(strict_types=1);

namespace App\Enums;

enum PublicTrafficSurface: string
{
    case LANDING_PAGE = 'landing-page';
    case PORTAL = 'portal';

    public function counterColumn(): string
    {
        return match ($this) {
            self::LANDING_PAGE => 'landing_page_unique_visitor_count',
            self::PORTAL => 'portal_unique_visitor_count',
        };
    }
}
