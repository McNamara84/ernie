<?php

declare(strict_types=1);

namespace App\Services\Assessment;

final class AssessmentQueueService
{
    public function connection(): string
    {
        return (string) config('fuji.assessment.queue_connection', 'assessment');
    }

    public function queue(): string
    {
        return (string) config('fuji.assessment.queue', 'assessments');
    }

    public function driver(): ?string
    {
        $driver = config('queue.connections.'.$this->connection().'.driver');

        return is_string($driver) && trim($driver) !== '' ? trim($driver) : null;
    }

    public function isPersistent(): bool
    {
        $driver = $this->driver();

        return $driver !== null && ! in_array(strtolower($driver), ['sync', 'null'], true);
    }
}
