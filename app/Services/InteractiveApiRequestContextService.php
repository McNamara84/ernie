<?php

declare(strict_types=1);

namespace App\Services;

use Closure;

/** Request-scoped priority shared by API transports, never inferred from CLI/auth state. */
final class InteractiveApiRequestContextService
{
    private int $depth = 0;

    private ?float $deadline = null;

    public function __construct(private readonly int $budgetMilliseconds = 90000) {}

    public function isInteractive(): bool
    {
        return $this->depth > 0;
    }

    public function remainingMilliseconds(): int
    {
        return $this->deadline === null ? PHP_INT_MAX
            : max(0, (int) floor(($this->deadline - microtime(true)) * 1000));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        if ($this->depth === 0) {
            $this->deadline = microtime(true) + $this->budgetMilliseconds / 1000;
        }
        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth--;
            if ($this->depth === 0) {
                $this->deadline = null;
            }
        }
    }
}
