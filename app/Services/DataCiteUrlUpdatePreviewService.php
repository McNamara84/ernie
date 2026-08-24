<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DataCiteUrlUpdateScope;
use Illuminate\Http\Client\ConnectionException;

class DataCiteUrlUpdatePreviewService
{
    public function __construct(
        private readonly DataCiteUrlUpdateCandidateService $candidates,
        private readonly DataCiteUrlUpdateTargetService $target,
        private readonly DataCiteMemberApiClient $client,
        private readonly DataCiteUrlUpdateQueueService $queueConnection,
    ) {}

    /** @return array<string, mixed> */
    public function build(DataCiteUrlUpdateScope $scope): array
    {
        $targetValidation = $this->target->validateTargetBase();
        $summary = $this->candidates->summarize($scope);
        $items = [];
        $canStart = $targetValidation['valid'];
        $blockingMessage = $targetValidation['message'];

        if (! $this->queueConnection->isPersistent()) {
            $canStart = false;
            $blockingMessage = 'A persistent queue connection is required for DataCite URL updates.';
        }

        foreach ($summary['sample'] as $resource) {
            $landingPage = $resource->landingPage;
            if ($landingPage === null) {
                continue;
            }

            $identifier = trim((string) $resource->doi);
            $targetUrl = $this->target->buildUrl($landingPage);
            $targetReachable = $targetValidation['valid'] && $this->target->isReachable($targetUrl);
            $beforeUrl = null;
            $remoteState = null;
            $outcome = 'ready';
            $message = null;

            if (! $targetReachable) {
                $canStart = false;
                $outcome = 'target_unreachable';
                $message = 'The new landing page is not reachable.';
            }

            if ($targetValidation['valid']) {
                try {
                    $response = $this->client->getDoi($identifier);
                    $status = $response->status();

                    if ($response->successful()) {
                        $before = $response->json('data.attributes.url');
                        $remoteStateValue = $response->json('data.attributes.state');
                        $beforeUrl = is_string($before) ? $before : null;
                        $remoteState = is_string($remoteStateValue) ? $remoteStateValue : null;

                        if ($beforeUrl !== null && $beforeUrl !== '' && $this->target->urlsEqual($beforeUrl, $targetUrl)) {
                            $outcome = 'already_current';
                            $message = 'The DataCite URL is already current.';
                        }
                    } elseif ($status === 404) {
                        $outcome = 'remote_missing';
                        $message = 'The identifier was not found at DataCite and will be skipped.';
                    } elseif (in_array($status, [401, 403], true)) {
                        $outcome = 'authentication_failed';
                        $message = 'DataCite authentication or authorization failed.';
                        $canStart = false;
                        $blockingMessage = $message;
                    } else {
                        $outcome = 'datacite_unavailable';
                        $message = "DataCite returned HTTP {$status}.";
                        $canStart = false;
                        $blockingMessage = $message;
                    }
                } catch (ConnectionException) {
                    $outcome = 'datacite_unavailable';
                    $message = 'DataCite could not be reached.';
                    $canStart = false;
                    $blockingMessage = $message;
                }
            }

            $items[] = [
                'resource_id' => $resource->id,
                'identifier' => $identifier,
                'before_url' => $beforeUrl,
                'target_url' => $targetUrl,
                'datacite_state' => $remoteState,
                'target_reachable' => $targetReachable,
                'outcome' => $outcome,
                'message' => $message,
            ];
        }

        return [
            'scope' => $scope->value,
            'scope_label' => $scope->label(),
            'total' => $summary['total'],
            'sample_count' => count($items),
            'target_base_url' => $this->target->targetBaseUrl(),
            'test_mode' => $this->client->isTestMode(),
            'datacite_endpoint' => $this->client->endpoint(),
            'can_start' => $canStart,
            'blocking_message' => $blockingMessage,
            'items' => $items,
        ];
    }
}
