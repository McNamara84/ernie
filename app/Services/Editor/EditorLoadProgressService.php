<?php

declare(strict_types=1);

namespace App\Services\Editor;

use App\Enums\EditorLoadStage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EditorLoadProgressService
{
    public const CACHE_TTL_SECONDS = 900;

    public const SLOW_THRESHOLD_MS = 12_000;

    /** @var list<string> */
    public const CLIENT_STAGES = [
        'loader',
        'client_resource_types',
        'client_vocabularies',
        'client_ready',
    ];

    /**
     * @return array<string, int|string|null>
     */
    public function begin(int $userId, int $resourceId): array
    {
        $token = (string) Str::uuid();
        $nowMs = $this->nowMs();
        $state = [
            'token' => $token,
            'user_id' => $userId,
            'resource_id' => $resourceId,
            'status' => 'pending',
            'stage' => EditorLoadStage::INITIALIZED->value,
            'progress' => EditorLoadStage::INITIALIZED->progress(),
            'started_at_ms' => $nowMs,
            'updated_at_ms' => $nowMs,
            'error' => null,
        ];

        Cache::put($this->stateKey($token), $state, self::CACHE_TTL_SECONDS);

        return $state;
    }

    /**
     * @return array<string, int|string|null>|null
     */
    public function findForUser(string $token, int $userId, ?int $resourceId = null): ?array
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $state = Cache::get($this->stateKey($token));

        if (! is_array($state)
            || ($state['user_id'] ?? null) !== $userId
            || ($resourceId !== null && ($state['resource_id'] ?? null) !== $resourceId)) {
            return null;
        }

        /** @var array<string, int|string|null> $state */
        return $state;
    }

    /**
     * @return array<string, int|string|null>|null
     */
    public function advance(
        string $token,
        int $userId,
        int $resourceId,
        EditorLoadStage $stage,
    ): ?array {
        $state = $this->findForUser($token, $userId, $resourceId);

        if ($state === null) {
            return null;
        }

        $currentProgress = (int) ($state['progress'] ?? 0);
        if ($stage->progress() < $currentProgress) {
            return $state;
        }

        $state['status'] = $stage === EditorLoadStage::SERVER_READY ? 'server_ready' : 'loading';
        $state['stage'] = $stage->value;
        $state['progress'] = $stage->progress();
        $state['updated_at_ms'] = $this->nowMs();

        Cache::put($this->stateKey($token), $state, self::CACHE_TTL_SECONDS);
        $this->logIfSlow($token, $userId, $resourceId, $stage->value, $stage->progress(), 'server');

        return $state;
    }

    public function fail(string $token, int $userId, int $resourceId, string $message): void
    {
        $state = $this->findForUser($token, $userId, $resourceId);

        if ($state === null) {
            return;
        }

        $state['status'] = 'failed';
        $state['updated_at_ms'] = $this->nowMs();
        $state['error'] = $message;

        Cache::put($this->stateKey($token), $state, self::CACHE_TTL_SECONDS);
        $this->logIfSlow(
            $token,
            $userId,
            $resourceId,
            (string) ($state['stage'] ?? EditorLoadStage::INITIALIZED->value),
            (int) ($state['progress'] ?? 0),
            'server',
        );
    }

    public function logIfSlow(
        string $token,
        int $userId,
        int $resourceId,
        string $stage,
        int $progress,
        string $source,
    ): bool {
        $state = $this->findForUser($token, $userId, $resourceId);

        if ($state === null) {
            return false;
        }

        $durationMs = max(0, $this->nowMs() - (int) ($state['started_at_ms'] ?? $this->nowMs()));
        if ($durationMs < self::SLOW_THRESHOLD_MS) {
            return false;
        }

        if (! Cache::add($this->slowLogKey($token), true, self::CACHE_TTL_SECONDS)) {
            return false;
        }

        Log::warning('Slow Data Editor resource load', [
            'user_id' => $userId,
            'resource_id' => $resourceId,
            'duration_ms' => $durationMs,
            'stage' => $stage,
            'progress' => max(0, min(100, $progress)),
            'source' => $source,
        ]);

        return true;
    }

    private function stateKey(string $token): string
    {
        return "editor_load:{$token}";
    }

    private function slowLogKey(string $token): string
    {
        return "editor_load:{$token}:slow_logged";
    }

    private function nowMs(): int
    {
        return now()->getTimestampMs();
    }
}
