<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Mail\ResourceReviewLink;
use App\Models\ContributorType;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
    public function queue(array $resourceIds, User $initiator, string $contactAddress): array
    {
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
                    Mail::to($recipient['email'])->queue(new ResourceReviewLink(
                        resourceId: $resourceId,
                        resourceTitle: $resourceTitle,
                        resourceDoi: $resource->doi,
                        reviewUrl: $reviewUrl,
                        recipientName: $recipient['name'],
                        initiatorName: $initiator->name,
                        initiatorEmail: $initiator->email,
                        contactAddress: $contactAddress,
                    ));

                    $queuedRecipients++;
                    $result['queued_messages']++;
                } catch (Throwable $exception) {
                    $result['skipped_recipients_count']++;

                    Log::error('Failed to queue resource review email', [
                        'resource_id' => $resourceId,
                        'resource_contributor_id' => $recipient['contributor_id'],
                        'error' => $exception->getMessage(),
                    ]);
                }
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
