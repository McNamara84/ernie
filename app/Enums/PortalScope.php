<?php

declare(strict_types=1);

namespace App\Enums;

enum PortalScope: string
{
    case DOI = 'doi';
    case IGSN = 'igsn';

    public const PHYSICAL_SAMPLE_RESOURCE_TYPE = 'physical-object';

    public function title(): string
    {
        return match ($this) {
            self::DOI => 'Data Portal',
            self::IGSN => 'IGSN Portal',
        };
    }

    public function basePath(): string
    {
        return match ($this) {
            self::DOI => '/doi-search',
            self::IGSN => '/igsn-search',
        };
    }

    public function showsResourceTypeFilter(): bool
    {
        return $this === self::DOI;
    }

    /**
     * @return array{kind: string, title: string, basePath: string, showResourceTypeFilter: bool}
     */
    public function frontendDescriptor(): array
    {
        return [
            'kind' => $this->value,
            'title' => $this->title(),
            'basePath' => $this->basePath(),
            'showResourceTypeFilter' => $this->showsResourceTypeFilter(),
        ];
    }
}
