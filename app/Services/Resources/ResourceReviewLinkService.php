<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Mail\ResourceReviewLink;
use App\Mail\ResourceReviewLinkMigration;
use App\Models\ContributorType;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceReviewLinkDispatch;
use App\Models\User;
use App\Services\Curation\CurationService;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validate review-link selections and queue one message per resource/recipient.
 */
final readonly class ResourceReviewLinkService
{
    /**
     * Relations needed by Resource::publicStatus() and recipient resolution.
     *
     * @var list<string>
     */
    private const RELATIONS = [
        'landingPage',
        'titles.titleType',
        'creators',
        'rights',
        'dates.dateType',
        'descriptions.descriptionType',
        'contributors.contributorable',
        'contributors.contributorTypes',
    ];

    /**
     * @param  list<int>  $resourceIds
     * @return array{
     *     queued_messages:int,
     *     successful_resources:list<array{id:int,queued_recipients:int}>,
     *     failed_resources:list<array{id:int,reason:string}>,
     *     skipped_recipients_count:int
     * }
     */
    public function queue(array $resourceIds, User $initiator, string $contactAddress, ?string $operationId = null): array
    {
        return $this->queueWithMessageFactory(
            $resourceIds,
            static fn (
                int $resourceId,
                string $resourceTitle,
                ?string $resourceDoi,
                string $reviewUrl,
                string $recipientName,
            ): Mailable => new ResourceReviewLink(
                resourceId: $resourceId,
                resourceTitle: $resourceTitle,
                resourceDoi: $resourceDoi,
                reviewUrl: $reviewUrl,
                recipientName: $recipientName,
                initiatorName: $initiator->name,
                initiatorEmail: $initiator->email,
                contactAddress: $contactAddress,
            ),
            $initiator,
            'review',
            $operationId,
        );
    }

    /**
     * @param  list<int>  $resourceIds
     * @return array{
     *     queued_messages:int,
     *     successful_resources:list<array{id:int,queued_recipients:int}>,
     *     failed_resources:list<array{id:int,reason:string}>,
     *     skipped_recipients_count:int
     * }
     */
    public function queueMigration(array $resourceIds, string $contactAddress, ?User $initiator = null, ?string $operationId = null): array
    {
        return $this->queueWithMessageFactory(
            $resourceIds,
            static fn (
                int $resourceId,
                string $resourceTitle,
                ?string $resourceDoi,
                string $reviewUrl,
                string $recipientName,
            ): Mailable => new ResourceReviewLinkMigration(
                resourceId: $resourceId,
                resourceTitle: $resourceTitle,
                resourceDoi: $resourceDoi,
                reviewUrl: $reviewUrl,
                recipientName: $recipientName,
                contactAddress: $contactAddress,
            ),
            $initiator,
            'migration',
            $operationId,
        );
    }

    /**
     * @param  list<int>  $resourceIds
     * @param  Closure(int, string, ?string, string, string): Mailable  $messageFactory
     * @return array{
     *     queued_messages:int,
     *     successful_resources:list<array{id:int,queued_recipients:int}>,
     *     failed_resources:list<array{id:int,reason:string}>,
     *     skipped_recipients_count:int
     * }
     */
    private function queueWithMessageFactory(array $resourceIds, Closure $messageFactory, ?User $initiator, string $kind, ?string $operationId): array
    {
        $curation = app(CurationService::class);
        $curationAvailable = $curation->available();
        $operationId ??= (string) Str::uuid();
        $resources = Resource::query()
            ->with(self::RELATIONS)
            ->whereIn('id', $resourceIds)
            ->get()
            ->keyBy('id');

        $invalidSelections = [];

        foreach ($resourceIds as $resourceId) {
            $resource = $resources->get($resourceId);

            if (! $resource instanceof Resource) {
                $invalidSelections[] = "#{$resourceId} no longer exists";

                continue;
            }

            if ($resource->publicStatus() !== 'review') {
                $invalidSelections[] = "#{$resourceId} is not in review";

                continue;
            }

            if ($resource->landingPage?->preview_url === null) {
                $invalidSelections[] = "#{$resourceId} has no review link";
            }
        }

        if ($invalidSelections !== []) {
            throw ValidationException::withMessages([
                'ids' => [
                    'Review links can only be sent when every selected resource is in review and has a review link. '.
                    'Invalid selection: '.implode(', ', $invalidSelections).'.',
                ],
            ]);
        }

        $result = [
            'queued_messages' => 0,
            'successful_resources' => [],
            'failed_resources' => [],
            'skipped_recipients_count' => 0,
        ];

        foreach ($resourceIds as $resourceId) {
            /** @var Resource $resource */
            $resource = $resources->get($resourceId);
            $skippedBefore = $result['skipped_recipients_count'];
            $recipients = [];
            foreach ($resource->contributors as $contributor) {
                if (! $this->isContactPerson($contributor)) {
                    continue;
                }

                $email = trim((string) $contributor->email);

                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $result['skipped_recipients_count']++;

                    continue;
                }

                $recipientKey = strtolower($email);

                if (array_key_exists($recipientKey, $recipients)) {
                    continue;
                }

                $recipients[$recipientKey] = [
                    'contributor_id' => $contributor->id,
                    'email' => $email,
                    'name' => $this->contributorName($contributor),
                ];
            }

            if ($recipients === []) {
                $result['failed_resources'][] = [
                    'id' => $resourceId,
                    'reason' => 'No ContactPerson contributor with a valid email address.',
                ];

                continue;
            }

            $queuedRecipients = 0;
            $resourceTitle = trim((string) ($resource->getMainTitleAttribute() ?? $resource->titles->first()?->value));
            if ($resourceTitle === '') {
                $resourceTitle = "Resource #{$resourceId}";
            }

            $reviewUrl = $resource->landingPage?->preview_url;
            assert(is_string($reviewUrl));

            foreach ($recipients as $recipient) {
                try {
                    $message = $messageFactory(
                        $resourceId,
                        $resourceTitle,
                        $resource->doi,
                        $reviewUrl,
                        $recipient['name'],
                    );
                    $queued = $curationAvailable ? DB::transaction(function () use ($recipient, $message, $resource, $operationId, $initiator, $kind, $curation): bool {
                        Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
                        $dispatch = ResourceReviewLinkDispatch::firstOrNew(['resource_id' => $resource->id, 'operation_id' => $operationId]);
                        $recipientKey = hash('sha256', strtolower($recipient['email']));
                        $keys = $dispatch->queued_recipient_keys ?? [];
                        // A receipt can represent a partially completed operation.
                        // Retry every recipient, skipping only confirmed queue writes.
                        if (in_array($recipientKey, $keys, true)) {
                            return false;
                        }
                        assert($message instanceof ResourceReviewLink || $message instanceof ResourceReviewLinkMigration);
                        Mail::to($recipient['email'])->queue($message->onConnection('curation'));
                        $new = ! $dispatch->exists;
                        $dispatch->fill(['kind' => $kind, 'sent_by' => $initiator?->id, 'queued_at' => $dispatch->queued_at ?? now(), 'queued_recipients' => ($dispatch->queued_recipients ?? 0) + 1, 'queued_recipient_keys' => [...$keys, $recipientKey]])->save();
                        if ($new && $resource->curationState()->exists()) {
                            $curation->event($resource, 'review.queued', $initiator, ['operation_id' => $operationId, 'kind' => $kind, 'queued_at' => $dispatch->queued_at->toISOString()]);
                        }
                        $curation->reconcile($resource->id);
                        app(ResourceListingProjectionRefreshService::class)->schedule($resource->id);

                        return true;
                    }) : $this->queueWithoutCuration($recipient['email'], $message);

                    $queuedRecipients++;
                    $result['queued_messages'] += $queued ? 1 : 0;
                } catch (Throwable $exception) {
                    $result['skipped_recipients_count']++;

                    Log::error('Failed to queue resource review email', [
                        'resource_id' => $resourceId,
                        'resource_contributor_id' => $recipient['contributor_id'],
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if ($curationAvailable) {
                ResourceReviewLinkDispatch::where('resource_id', $resourceId)->where('operation_id', $operationId)
                    ->update(['skipped_recipients' => $result['skipped_recipients_count'] - $skippedBefore]);
            }
            if ($queuedRecipients === 0) {
                $result['failed_resources'][] = [
                    'id' => $resourceId,
                    'reason' => 'Review email could not be queued for any ContactPerson contributor.',
                ];

                continue;
            }

            $result['successful_resources'][] = [
                'id' => $resourceId,
                'queued_recipients' => $queuedRecipients,
            ];
        }

        return $result;
    }

    private function queueWithoutCuration(string $email, Mailable $message): bool
    {
        // Preserve the existing mail connection/queue until receipts can be
        // committed atomically with jobs on the curation connection.
        Mail::to($email)->queue($message);

        return true;
    }

    private function isContactPerson(ResourceContributor $contributor): bool
    {
        return $contributor->contributorTypes->contains(
            static fn (ContributorType $type): bool => $type->slug === 'ContactPerson'
        );
    }

    private function contributorName(ResourceContributor $contributor): string
    {
        $contributorable = $contributor->contributorable;

        if ($contributorable instanceof Person) {
            return trim(implode(' ', array_filter([
                $contributorable->given_name,
                $contributorable->family_name,
            ]))) ?: 'Contact Person';
        }

        return trim($contributorable->name) ?: 'Contact Person';
    }
}
