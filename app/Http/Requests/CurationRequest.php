<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Resource;
use App\Policies\ResourceCurationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = $this->route('resource');
        $user = $this->user();
        if ($user === null || ! $user->is_active) {
            return false;
        }
        if (! $resource instanceof Resource) {
            return $this->isMethod('GET');
        }
        $policy = new ResourceCurationPolicy;

        return $this->isMethod('GET') ? $policy->view($user, $resource) : $policy->update($user, $resource);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $revision = ['required', 'integer', 'min:0'];
        $text = ['nullable', 'string', 'max:2000'];
        $operation = $this->route('operation');

        return match ($operation) {
            'checklist' => ['revision' => $revision, 'state' => ['required', Rule::in(['open', 'done', 'not_applicable'])], 'reason' => $text],
            'assign', 'handover' => ['revision' => $revision, 'lead' => ['required', 'integer', 'exists:users,id'], 'members' => ['sometimes', 'array', 'max:100'], 'members.*' => ['integer', 'distinct', 'exists:users,id'], 'note' => $text],
            'state' => ['revision' => $revision, 'waiting_for' => ['sometimes', 'nullable', Rule::in(['submitters', 'colleague', 'other'])], 'waiting_text' => $text, 'needs_help_text' => ['sometimes', 'nullable', 'string', 'min:1', 'max:2000'], 'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'deadline_reason' => $text],
            'note' => ['revision' => $this->isMethod('POST') ? ['sometimes', 'integer', 'min:0'] : $revision, 'body' => $this->isMethod('DELETE') ? ['nullable'] : ['required', 'string', 'max:10000'], 'mentions' => ['sometimes', 'array', 'max:100'], 'mentions.*' => ['integer', 'distinct', 'exists:users,id']],
            'location' => ['revision' => $this->isMethod('POST') ? ['sometimes', 'integer', 'min:0'] : $revision, 'type' => $this->isMethod('DELETE') ? ['nullable'] : ['required', Rule::in(['network_drive', 'sftp', 'nextcloud', 'data_server', 'external_server', 'other'])], 'location' => $this->isMethod('DELETE') ? ['nullable'] : ['required', 'string', 'max:2000']],
            default => [
                'tab' => ['sometimes', Rule::in(['progress', 'review', 'embargo', 'published', 'all'])],
                'mine' => ['sometimes', 'boolean'], 'open' => ['sometimes', 'boolean'],
                'waiting' => ['sometimes', 'boolean'], 'waiting_overdue' => ['sometimes', 'boolean'],
                'help' => ['sometimes', 'boolean'], 'overdue' => ['sometimes', 'boolean'], 'unassigned' => ['sometimes', 'boolean'],
                'curator' => ['sometimes', 'nullable', 'integer', 'exists:users,id'], 'datacenter' => ['sometimes', 'nullable', 'integer', 'exists:datacenters,id'],
                'sort' => ['sometimes', Rule::in(['activity', 'created', 'deadline', 'progress'])],
                'direction' => ['sometimes', Rule::in(['asc', 'desc'])], 'page' => ['sometimes', 'integer', 'min:1'],
                'cursor' => ['sometimes', 'nullable', 'integer', 'min:1'], 'notes_only' => ['sometimes', 'boolean'],
                'q' => ['sometimes', 'nullable', 'string', 'max:100'], 'mentions' => ['sometimes', 'boolean'],
            ],
        };
    }
}
