<?php

declare(strict_types=1);

namespace App\Enums;

enum SchemaOrgProfile: string
{
    case CREATIVE_WORK = 'creative-work';
    case DATASET = 'dataset';
    case SOFTWARE = 'software';
    case MEDIA = 'media';
    case DESCRIBED_OBJECT = 'described-object';
}
