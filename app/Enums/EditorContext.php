<?php

declare(strict_types=1);

namespace App\Enums;

enum EditorContext: string
{
    case ERNIE = 'ernie';
    case ELMO = 'elmo';
    case ELMO_MSL = 'elmo-msl';

    public function activationColumn(bool $language = false): string
    {
        $column = match ($this) {
            self::ERNIE => 'active',
            self::ELMO => 'elmo_active',
            self::ELMO_MSL => 'elmo_msl_active',
        };

        return $language ? $column : 'is_'.$column;
    }

    public function exclusionField(): string
    {
        return match ($this) {
            self::ERNIE => 'excluded_resource_type_ids',
            self::ELMO => 'elmo_excluded_resource_type_ids',
            self::ELMO_MSL => 'elmo_msl_excluded_resource_type_ids',
        };
    }
}
