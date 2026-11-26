<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\User;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DataCiteApiService;
use App\Services\OaiPmh\OaiPmhDatestampService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class CitationLabelReviewService
{
    private const PAGE_SIZE = 16;

    public function __construct(
        private DataCiteApiService $metadata,
        private RelatedIdentifierCitationLabelService $labels,
        private RelatedWorkCitationFormatter $formatter,
        private OaiPmhDatestampService $datestamps,
        private LandingPageRenderDataCacheService $cache,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function preview(User $user, array $ids, int $cursor, ?string $reviewId): array
    {
        $resources = Resource::query()->whereKey($ids)->with(['landingPage', 'titles.titleType'])->get()->keyBy('id');
        foreach ($resources as $resource) {
            Gate::forUser($user)->authorize('update', $resource);
        }
        $published = $resources->filter(fn (Resource $resource) => $resource->landingPage?->is_published
            && ! $resource->landingPage->is_tombstone && ! $resource->landingPage->isExternal());

        $rows = RelatedIdentifier::query()->with('identifierType')
            ->whereIn('resource_id', $published->modelKeys())
            ->whereHas('identifierType', fn ($query) => $query->whereIn('slug', ['DOI', 'URL']))
            ->whereNotNull('citation_label')->where('citation_label', '!=', '')->whereNull('citation_label_parts')
            ->where('id', '>', $cursor)->orderBy('id')->limit(self::PAGE_SIZE + 1)->get();
        $page = $rows->take(self::PAGE_SIZE);
        $dois = $page->mapWithKeys(fn (RelatedIdentifier $row) => [
            $row->id => $this->labels->normalizeResolvableDoi($row->identifier, $row->identifierType->slug),
        ])->filter();
        $results = $this->reviewMetadata($dois->unique()->values()->all(), $user->id, $reviewId);

        $candidates = [];
        $skipped = ['other_type' => 0, 'changed_text' => 0, 'insufficient_metadata' => 0, 'lookup_failed' => 0];
        foreach ($page as $row) {
            $doi = $dois->get($row->id);
            if ($doi === null) {
                continue;
            }
            $match = $results[$doi];
            if (is_string($match)) {
                $skipped[$match]++;

                continue;
            }
            if ($match['old'] !== $row->citation_label) {
                $skipped['changed_text']++;

                continue;
            }
            $proposal = [
                'id' => $row->id,
                'resource_id' => $row->resource_id,
                'identifier' => $row->identifier,
                'identifier_type_id' => $row->identifier_type_id,
                'old_hash' => hash('sha256', $match['old']),
                'text' => $match['text'],
                'parts' => $match['parts'],
            ];
            $resource = $published->findOrFail($row->resource_id);
            $candidates[] = [
                'id' => $row->id, 'resource_id' => $resource->id, 'resource' => $resource->main_title ?? $resource->doi ?? 'Resource #'.$resource->id,
                'doi' => $doi, ...$match,
                'token' => Crypt::encryptString(json_encode(['reviewer' => $user->id, 'proposal' => $proposal], JSON_THROW_ON_ERROR)),
            ];
        }

        return ['candidates' => $candidates, 'skipped' => $skipped, 'next_cursor' => $rows->count() > self::PAGE_SIZE ? $page->last()?->id : null];
    }

    /**
     * Keep one metadata snapshot per DOI throughout a paged review, including lookup failures.
     *
     * @param  array<int, string>  $dois
     * @return array<string, 'lookup_failed'|'other_type'|'insufficient_metadata'|array{old: string, text: string, parts: list<array{text: string, italic: bool}>, work_type: string}>
     */
    private function reviewMetadata(array $dois, int $userId, ?string $reviewId): array
    {
        $cache = CacheKey::DOI_CITATION->repository();
        $keys = [];
        if ($reviewId !== null) {
            foreach ($dois as $doi) {
                $keys[$doi] = 'citations:review:'.$userId.':'.$reviewId.':'.hash('sha256', $doi);
            }
        }
        $cached = $keys === [] ? [] : $cache->many(array_values($keys));
        $results = [];
        $pending = [];
        foreach ($dois as $doi) {
            if (isset($keys[$doi], $cached[$keys[$doi]])) {
                $results[$doi] = $cached[$keys[$doi]];
            } else {
                $pending[] = $doi;
            }
        }
        $responses = $this->metadata->getMetadataBatch($pending, timeoutSeconds: 2.0, attempts: 1);
        $deadline = microtime(true) + 4;
        $new = [];
        foreach ($pending as $doi) {
            $response = $responses[$doi] ?? null;
            $result = 'lookup_failed';
            if ($response !== null && $response['status'] === 'resolved') {
                $metadata = $response['metadata'];
                $citation = $this->formatter->format($doi, $metadata, $deadline);
                $result = match (true) {
                    $citation === null => 'insufficient_metadata',
                    $citation->workType === null => 'other_type',
                    $citation->parts === null || ! $citation->hasSource => 'insufficient_metadata',
                    default => ['old' => $this->metadata->buildCitationFromMetadata($metadata), 'text' => $citation->text,
                        'parts' => $citation->parts, 'work_type' => $citation->workType],
                };
            }
            $results[$doi] = $result;
            if (isset($keys[$doi])) {
                $new[$keys[$doi]] = $result;
            }
        }
        if ($new !== []) {
            $cache->putMany($new, now()->addMinutes(30));
        }

        return $results;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{id: int, status: string}>
     */
    public function apply(User $user, array $tokens): array
    {
        $proposals = [];
        foreach ($tokens as $token) {
            try {
                $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|JsonException) {
                $payload = null;
            }
            if (($payload['reviewer'] ?? null) !== $user->id || ! is_array($payload['proposal'] ?? null)) {
                throw ValidationException::withMessages(['tokens' => 'Reopen the preview to review these citation labels.']);
            }
            $proposals[] = $payload['proposal'];
        }

        return DB::transaction(function () use ($user, $proposals): array {
            $results = [];
            $changed = [];
            $resources = Resource::query()->whereKey(array_column($proposals, 'resource_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($resources as $resource) {
                Gate::forUser($user)->authorize('update', $resource);
            }
            $pages = LandingPage::query()->whereIn('resource_id', $resources->modelKeys())->orderBy('id')->lockForUpdate()->get()->keyBy('resource_id');
            $rows = RelatedIdentifier::query()->whereIn('resource_id', $resources->modelKeys())
                ->whereKey(array_column($proposals, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($proposals as $proposal) {
                $landingPage = $pages->get($proposal['resource_id']);
                $row = $rows->get($proposal['id']);
                $status = 'conflict';
                if ($row !== null && $row->resource_id === $proposal['resource_id'] && $landingPage?->is_published && ! $landingPage->is_tombstone && ! $landingPage->isExternal()
                    && $row->identifier === $proposal['identifier'] && $row->identifier_type_id === $proposal['identifier_type_id']) {
                    if ($row->citation_label === $proposal['text'] && $row->citation_label_parts == $proposal['parts']) {
                        $status = 'already_applied';
                    } elseif ($row->citation_label_parts === null && hash('sha256', (string) $row->citation_label) === $proposal['old_hash']) {
                        $row->update(['citation_label' => $proposal['text'], 'citation_label_parts' => $proposal['parts']]);
                        $changed[$row->resource_id] = $landingPage->id;
                        $status = 'applied';
                    }
                }
                $results[] = ['id' => $proposal['id'], 'status' => $status];
            }
            foreach ($changed as $resourceId => $landingPageId) {
                $this->datestamps->touchResource($resourceId);
                DB::afterCommit(fn () => $this->cache->forgetById($landingPageId));
            }

            return $results;
        }, attempts: 3);
    }
}
