<?php

declare(strict_types=1);

namespace App\Enums;

enum CitationLabelResolutionMode: string
{
    case BEST_EFFORT = 'best-effort';
    case EXHAUSTIVE = 'exhaustive';

    public function satisfies(self $requested): bool
    {
        return $this === self::EXHAUSTIVE || $this === $requested;
    }
}
