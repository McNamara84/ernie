<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\LandingPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CounterEventRecorderService
{
    public function __construct(
        private readonly CounterSessionHasherService $hasher,
        private readonly CounterUserAgentClassifierService $classifier,
    ) {}

    public function landingPage(Request $request, LandingPage $page, string $event, string $target): void
    {
        if (! $page->isPublished() || ! $page->doi_prefix || $page->isExternal() || $this->isPreview($request, $page)) {
            return;
        }
        if ($event === 'request' && ($page->is_tombstone || $page->resource->resourceType?->slug === 'physical-object')) {
            return;
        }
        if (! in_array($event, ['investigation', 'request'], true)) {
            return;
        }
        $this->record($request, 'usage_events', [
            'landing_page_id' => $page->id, 'event_type' => $event, 'target_hash' => $this->hasher->target($target),
        ]);
    }

    public function search(Request $request, string $scope): void
    {
        $query = $request->query('q');
        if (! is_string($query) || ! in_array($scope, ['doi', 'igsn'], true)) {
            return;
        }
        if ($request->headers->has('X-Inertia') && $request->header('X-Ernie-Search-Intent') !== 'submit') {
            return;
        }
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));
        if ($normalized === '' || strlen($normalized) > 16_384) {
            return;
        }
        $this->record($request, 'usage_search_events', [
            'portal_scope' => $scope, 'normalized_term' => $normalized, 'term_hash' => hash('sha256', $normalized),
        ]);
    }

    public function isPreview(Request $request, LandingPage $page): bool
    {
        $token = $request->query('preview');

        return $request->attributes->get('counter.preview') === true
            || (is_string($token) && $token !== '' && is_string($page->preview_token) && hash_equals($page->preview_token, $token));
    }

    public function eligible(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->user() !== null) {
            return false;
        }
        foreach (['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (preg_match('/prefetch|prerender/i', (string) $request->header($header))) {
                return false;
            }
        }

        return ! $request->headers->has('X-Inertia-Partial-Data');
    }

    /** @param array<string, int|string> $event */
    private function record(Request $request, string $table, array $event): void
    {
        if (! $this->eligible($request)) {
            return;
        }
        try {
            DB::transaction(function () use ($request, $table, $event): void {
                // Aggregation/activation take the exclusive counterpart of this lock.
                // Concurrent recorders can share it, but cannot race a day finalization.
                $state = DB::table('usage_tracking_state')->where('id', 1)->sharedLock()->firstOrFail();
                if ($state->started_at === null || $state->paused) {
                    return;
                }
                $version = DB::table('usage_list_versions')->where('id', $state->list_version_id)->firstOrFail();
                /** @var array{robots:list<string>, machines:list<string>} $rules */
                $rules = json_decode($version->rules, true, flags: JSON_THROW_ON_ERROR);
                $access = $this->classifier->classify($request->userAgent(), $rules);
                if ($access === 'Robot') {
                    return;
                }
                $at = CarbonImmutable::now('UTC');
                DB::table($table)->insert([
                    ...$event, 'access_method' => $access, 'day' => $at->toDateString(),
                    'session_hash' => $this->hasher->hash($request, $at),
                    'occurred_at_ms' => $at->getTimestampMs(), 'list_version_id' => $version->id,
                ]);
            });
        } catch (Throwable $exception) {
            // Never log requests, fingerprints, raw SQL bindings or exception messages.
            Log::warning('COUNTER event recording failed.', ['exception_type' => $exception::class]);
            try {
                DB::transaction(function (): void {
                    DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
                    DB::table('usage_aggregation_days')->upsert(
                        [['day' => CarbonImmutable::today('UTC')->toDateString(), 'incomplete' => true]],
                        ['day'], ['incomplete'],
                    );
                    DB::table('usage_tracking_state')->where('id', 1)->increment('metrics_revision');
                });
            } catch (Throwable) {
                // A database outage must not break the public response.
            }
        }
    }
}
