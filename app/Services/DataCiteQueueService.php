<?php

declare(strict_types=1);

namespace App\Services;

class DataCiteQueueService
{
    public function isPersistent(): bool
    {
        $driver = $this->driver();

        return $driver !== null && ! in_array(strtolower($driver), ['sync', 'null'], true);
    }

    public function driver(): ?string
    {
        $connection = config('queue.default');
        if (! is_string($connection) || trim($connection) === '') {
            return null;
        }

        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) && trim($driver) !== '' ? trim($driver) : null;
    }

    public function queue(): string
    {
        return (string) config('datacite.queue', 'datacite');
    }
}
