<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedbackCategory: string
{
    case PROBLEM = 'problem';
    case IDEA = 'idea';
    case PRAISE = 'praise';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PROBLEM => 'Problem',
            self::IDEA => 'Idea',
            self::PRAISE => 'Praise',
            self::OTHER => 'Other',
        };
    }
}
