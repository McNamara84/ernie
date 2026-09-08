<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentScope: string
{
    case RESOURCE = 'resource';
    case IGSN = 'igsn';

    public function label(): string
    {
        return $this === self::IGSN ? 'IGSNs' : 'Resources';
    }

    public function singularLabel(): string
    {
        return $this === self::IGSN ? 'IGSN' : 'Resource';
    }
}
