<?php

declare(strict_types=1);

namespace App\Http\Requests\Editor;

use App\Services\Editor\EditorLoadProgressService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReportSlowEditorLoadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'stage' => ['nullable', 'string', Rule::in(EditorLoadProgressService::CLIENT_STAGES)],
            'progress' => ['nullable', 'integer', 'between:0,100'],
        ];
    }

    public function stage(): ?string
    {
        $stage = $this->validated('stage');

        return is_string($stage) ? $stage : null;
    }

    public function progress(): ?int
    {
        $progress = $this->validated('progress');

        return is_int($progress) ? $progress : null;
    }
}
