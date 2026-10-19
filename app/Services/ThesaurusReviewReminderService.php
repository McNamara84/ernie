<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;

final class ThesaurusReviewReminderService
{
    private const KEY_PREFIX = 'thesauri_review_completed:';

    /** @return array{period: string, isDue: bool, completedAt: string|null} */
    public function status(): array
    {
        return $this->statusForPeriod($this->currentPeriod());
    }

    /**
     * A stale period returns the current status without completing it.
     *
     * @return array{period: string, isDue: bool, completedAt: string|null}
     */
    public function complete(string $period, User $user): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $currentPeriod = $this->currentPeriod($now);

        if ($period !== $currentPeriod) {
            return $this->statusForPeriod($currentPeriod);
        }

        // The unique settings key makes concurrent completions idempotent.
        // Separate quarter keys also prevent late requests overwriting a newer review.
        $completion = Setting::query()->firstOrCreate(
            ['key' => self::KEY_PREFIX.$period],
            ['value' => json_encode([
                'completed_at' => $now->toISOString(),
                'completed_by' => $user->id,
            ], JSON_THROW_ON_ERROR)],
        );

        return $this->payload($period, $completion);
    }

    private function currentPeriod(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));

        return $now->format('Y').'-Q'.$now->quarter;
    }

    /** @return array{period: string, isDue: bool, completedAt: string|null} */
    private function statusForPeriod(string $period): array
    {
        return $this->payload($period, Setting::query()->where('key', self::KEY_PREFIX.$period)->first());
    }

    /** @return array{period: string, isDue: bool, completedAt: string|null} */
    private function payload(string $period, ?Setting $completion): array
    {
        $value = json_decode((string) $completion?->getAttribute('value'), true);

        return [
            'period' => $period,
            'isDue' => $completion === null,
            'completedAt' => is_array($value) && is_string($value['completed_at'] ?? null) ? $value['completed_at'] : null,
        ];
    }
}
