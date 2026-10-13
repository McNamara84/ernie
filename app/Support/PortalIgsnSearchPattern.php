<?php

declare(strict_types=1);

namespace App\Support;

/** The public IGSN text search treats only * as a user wildcard. */
final readonly class PortalIgsnSearchPattern
{
    public function __construct(private string $query) {}

    public function isMatchAll(): bool
    {
        return str_replace('*', '', trim($this->query)) === '';
    }

    public function likePattern(): string
    {
        return '%'.strtr(mb_strtolower($this->query), [
            '!' => '!!',
            '%' => '!%',
            '_' => '!_',
            '*' => '%',
        ]).'%';
    }

    public function matches(string $value): bool
    {
        $value = mb_strtolower($value);
        $offset = 0;
        foreach (explode('*', mb_strtolower($this->query)) as $part) {
            if ($part === '') {
                continue;
            }

            $position = mb_strpos($value, $part, $offset);
            if ($position === false) {
                return false;
            }

            $offset = $position + mb_strlen($part);
        }

        return true;
    }
}
