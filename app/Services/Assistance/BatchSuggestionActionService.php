<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Exceptions\BatchSuggestionValidationException;
use App\Models\User;
use Throwable;

/**
 * Validates and executes one resource-scoped review action.
 *
 * The existing assistants remain the only place where suggestions are
 * accepted or declined; this service merely coordinates those operations.
 */
final class BatchSuggestionActionService
{
    public function __construct(
        private readonly AssistantRegistrar $registrar,
    ) {}

    /**
     * @param  list<array{assistant_id: string, suggestion_id: int, relation_type_id?: int}>  $selections
     * @return array<string, mixed>
     */
    public function execute(
        string $action,
        int $resourceId,
        array $selections,
        User $user,
        ?string $reason = null,
    ): array {
        if (! in_array($action, ['accept', 'decline'], true)) {
            throw new BatchSuggestionValidationException('Unknown batch action.');
        }

        $resolved = $this->resolveSelection($action, $resourceId, $selections);
        $results = [];
        $syncedDois = [];
        $followUps = [];

        foreach ($resolved as $selection) {
            $assistant = $selection['assistant'];
            $suggestion = $selection['suggestion'];
            $suggestionId = $selection['suggestion_id'];
            $acceptanceInput = $selection['acceptance_input'];

            try {
                $result = $action === 'accept'
                    ? ($acceptanceInput === []
                        ? $assistant->acceptSuggestion($suggestionId)
                        : $assistant->acceptSuggestion($suggestionId, $acceptanceInput))
                    : $assistant->declineSuggestion($suggestionId, $user, $reason);
            } catch (Throwable $exception) {
                report($exception);
                $result = [
                    'success' => false,
                    'message' => 'The suggestion could not be processed.',
                ];
            }

            $success = (bool) ($result['success'] ?? false);
            $itemSyncedDois = array_values(array_filter(
                $result['synced_dois'] ?? [],
                static fn (mixed $doi): bool => is_string($doi) && trim($doi) !== '',
            ));
            $followUp = is_array($result['bulk_affiliation_match'] ?? null)
                ? $result['bulk_affiliation_match']
                : null;

            if ($success) {
                array_push($syncedDois, ...$itemSyncedDois);

                if ($followUp !== null && ($followUp['available'] ?? false)) {
                    $followUps[] = [
                        ...$followUp,
                        'assistant_id' => $assistant->getId(),
                        'suggestion_id' => $suggestionId,
                    ];
                }
            }

            $review = is_array($suggestion['review'] ?? null) ? $suggestion['review'] : [];
            $results[] = [
                'assistant_id' => $assistant->getId(),
                'assistant_name' => $assistant->getName(),
                'suggestion_id' => $suggestionId,
                'label' => (string) ($review['label'] ?? 'Suggestion #'.$suggestionId),
                'success' => $success,
                'message' => (string) ($result['message'] ?? ($success ? 'Processed.' : 'Failed.')),
                'synced_dois' => $itemSyncedDois,
            ];
        }

        $successCount = count(array_filter($results, static fn (array $result): bool => $result['success']));
        $failureCount = count($results) - $successCount;
        $verb = $action === 'accept' ? 'accepted' : 'declined';

        return [
            'success' => $failureCount === 0,
            'action' => $action,
            'resource_id' => $resourceId,
            'resource_label' => $this->resourceLabel($resolved, $resourceId),
            'processed_count' => count($results),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'message' => $failureCount === 0
                ? sprintf('%d suggestion(s) %s.', $successCount, $verb)
                : sprintf('%d suggestion(s) %s; %d failed.', $successCount, $verb, $failureCount),
            'synced_dois' => array_values(array_unique($syncedDois)),
            'follow_ups' => $followUps,
            'results' => $results,
        ];
    }

    /**
     * Resolve every item before the first mutation so invalid requests cannot
     * leave a partially processed selection behind.
     *
     * @param  'accept'|'decline'  $action
     * @param  list<array{assistant_id: string, suggestion_id: int, relation_type_id?: int}>  $selections
     * @return list<array{assistant: AssistantContract, suggestion: array<string, mixed>, suggestion_id: int, acceptance_input: array<string, mixed>}>
     */
    private function resolveSelection(string $action, int $resourceId, array $selections): array
    {
        $resolved = [];
        $identities = [];
        $exclusiveTargets = [];

        foreach ($selections as $selection) {
            $assistantId = $selection['assistant_id'];
            $suggestionId = $selection['suggestion_id'];
            $identity = $assistantId.':'.$suggestionId;

            if (isset($identities[$identity])) {
                throw new BatchSuggestionValidationException('The same suggestion cannot be selected twice.');
            }

            $identities[$identity] = true;
            $assistant = $this->registrar->get($assistantId);

            if ($assistant === null) {
                throw new BatchSuggestionValidationException('Unknown assistant: '.$assistantId.'.');
            }

            $suggestion = $assistant->getSuggestionForReview($suggestionId);

            if ($suggestion === null) {
                throw new BatchSuggestionValidationException('Suggestion '.$identity.' was not found.');
            }

            if ((int) ($suggestion['resource_id'] ?? 0) !== $resourceId) {
                throw new BatchSuggestionValidationException('All selected suggestions must belong to the requested resource.');
            }

            $review = is_array($suggestion['review'] ?? null) ? $suggestion['review'] : [];
            $capability = $action === 'accept' ? 'can_accept' : 'can_decline';

            if (($review[$capability] ?? false) !== true) {
                throw new BatchSuggestionValidationException(
                    $action === 'accept'
                        ? 'At least one selected suggestion can only be declined.'
                        : 'At least one selected suggestion cannot be declined.',
                );
            }

            $targetKey = $review['exclusive_target_key'] ?? null;

            if ($action === 'accept' && is_string($targetKey) && $targetKey !== '') {
                if (isset($exclusiveTargets[$targetKey])) {
                    throw new BatchSuggestionValidationException('Only one alternative per target can be accepted.');
                }

                $exclusiveTargets[$targetKey] = true;
            }

            $resolved[] = [
                'assistant' => $assistant,
                'suggestion' => $suggestion,
                'suggestion_id' => $suggestionId,
                'acceptance_input' => isset($selection['relation_type_id'])
                    ? ['relation_type_id' => $selection['relation_type_id']]
                    : [],
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array{assistant: AssistantContract, suggestion: array<string, mixed>, suggestion_id: int, acceptance_input: array<string, mixed>}>  $resolved
     */
    private function resourceLabel(array $resolved, int $resourceId): string
    {
        foreach ($resolved as $selection) {
            $doi = $selection['suggestion']['resource_doi'] ?? null;

            if (is_string($doi) && trim($doi) !== '') {
                return trim($doi);
            }
        }

        return 'Resource #'.$resourceId;
    }
}
