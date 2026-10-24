<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Models\ResourceCurationEvent;
use App\Support\Curation\CurationChecklist;

final class CurationEventDescriptionService
{
    public function describe(ResourceCurationEvent $event): string
    {
        $payload = $event->payload;
        $label = CurationChecklist::items()[$payload['key'] ?? '']['label'] ?? 'Checklist item';

        return match ($event->type) {
            'curation.started' => 'Curation started. The current resource state was observed; earlier work is not recorded.',
            'checklist.changed' => $label.': '.str_replace('_', ' ', $payload['state']).(! empty($payload['reason']) ? ' — '.$payload['reason'] : ''),
            'curators.changed', 'curation.handover' => 'Lead: '.($payload['lead_name'] ?? 'updated').'. Responsible curators: '.implode(', ', $payload['member_names'] ?? []).'.',
            'review.queued' => 'Review email queued. Delivery is not confirmed.',
            'location.saved' => 'Data location saved: '.($payload['after']['location'] ?? ''),
            'location.deleted' => 'Data location removed: '.($payload['before']['location'] ?? ''),
            'resource.changed' => $this->resourceChanges($payload),
            'state.changed' => $this->stateChanges($payload),
            default => '',
        };
    }

    /** @param array<string, mixed> $payload */
    private function resourceChanges(array $payload): string
    {
        $messages = [];
        $before = $payload['before'] ?? [];
        $after = $payload['snapshot'] ?? [];
        if (($before['status'] ?? null) !== ($after['status'] ?? null)) {
            $messages[] = 'Resource status: '.($before['status'] ?? 'unknown').' → '.($after['status'] ?? 'unknown').'.';
        }
        foreach ($after['automatic'] ?? [] as $key => $done) {
            if (($before['automatic'][$key] ?? null) !== $done) {
                $messages[] = (CurationChecklist::items()[$key]['label'] ?? $key).': '.($done ? 'done' : 'open').'.';
            }
        }

        return implode(' ', $messages);
    }

    /** @param array<string, mixed> $payload */
    private function stateChanges(array $payload): string
    {
        $messages = [];
        foreach (['waiting_for' => 'Waiting for', 'waiting_text' => 'Waiting details', 'needs_help_text' => 'Help needed', 'deadline' => 'Deadline', 'deadline_reason' => 'Deadline reason'] as $key => $label) {
            if (($payload['before'][$key] ?? null) !== ($payload['after'][$key] ?? null)) {
                $messages[] = $label.': '.($payload['after'][$key] ?? 'cleared').'.';
            }
        }

        return implode(' ', $messages);
    }
}
