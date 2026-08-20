<?php

declare(strict_types=1);

namespace App\Enums\Igsn;

enum IgsnClassificationType: string
{
    case ROCK = 'rock';
    case MINERAL = 'mineral';
    case BIOLOGY = 'biology';
}
