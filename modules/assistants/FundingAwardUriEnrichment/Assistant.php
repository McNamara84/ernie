<?php

declare(strict_types=1);

namespace Modules\Assistants\FundingAwardUriEnrichment;

use App\Contracts\AcceptsDeclineInput;
use App\Contracts\ReportsDiscoveryDetails;
use App\Models\AssistantSuggestion;
use App\Models\User;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\FundingAwardUri\FundingAwardUriDiscoveryService;
use App\Services\FundingAwardUri\FundingAwardUriReviewService;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class Assistant extends GenericTableAssistant implements AcceptsDeclineInput, ReportsDiscoveryDetails
{
    public function __construct(private readonly FundingAwardUriDiscoveryService $discovery, private readonly FundingAwardUriReviewService $review)
    {
        parent::__construct();
    }

    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /** @param Closure(string): void $onProgress */
    protected function discover(Closure $onProgress): int
    {
        return $this->discovery->discover($onProgress);
    }

    /** @return array<string, int|string|bool|null> */
    public function discoveryDetails(): array
    {
        return $this->discovery->report();
    }

    /** @return array{success: bool, message: string} */
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return ['success' => false, 'message' => 'Review the current award URI preview with an authorized reviewer.'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function acceptWithReviewer(Model $suggestion, array $input, ?User $reviewer): array
    {
        return $suggestion instanceof AssistantSuggestion
            ? $this->review->review($suggestion, $reviewer, $input, 'accepted')
            : ['success' => false, 'message' => 'Suggestion not found.'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function declineSuggestionWithInput(int $id, User $user, ?string $reason, array $input): array
    {
        $suggestion = $this->findById($id);

        return $suggestion instanceof AssistantSuggestion
            ? $this->review->review($suggestion, $user, $input, 'declined', $reason)
            : ['success' => false, 'message' => 'Suggestion not found.'];
    }

    /** @return array<string, mixed> */
    public function declineSuggestion(int $id, User $user, ?string $reason): array
    {
        return $this->declineSuggestionWithInput($id, $user, $reason, []);
    }

    /** @return array<string, mixed> */
    protected function transform(Model $suggestion): array
    {
        $item = parent::transform($suggestion);
        if ($suggestion instanceof AssistantSuggestion) {
            $inspection = $this->review->inspect($suggestion);
            $item['metadata']['review_status'] = $inspection['status'];
            $item['metadata']['live_current'] = $inspection['current'];
        }

        return $item;
    }

    /** @param array<string, mixed> $item
     * @return array{can_accept: bool, can_decline: bool, exclusive_target_key: string|null, label: string}
     */
    protected function reviewMetadata(Model $suggestion, array $item): array
    {
        return [
            'can_accept' => ($item['metadata']['review_status'] ?? null) === null && (bool) config('funding_award_uri.enabled'),
            'can_decline' => (bool) config('funding_award_uri.enabled'),
            // Discovery emits one unambiguous proposal per funding reference.
            'exclusive_target_key' => null,
            'label' => (string) ($item['suggested_label'] ?? 'Review award URI'),
        ];
    }
}
