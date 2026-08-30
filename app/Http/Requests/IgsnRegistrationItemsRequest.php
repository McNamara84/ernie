<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\IgsnRegistrationItemStatus;
use App\Models\IgsnRegistrationRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class IgsnRegistrationItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->route('registrationRun');

        return $run instanceof IgsnRegistrationRun
            && $this->user() !== null
            && Gate::forUser($this->user())->allows('manage-igsn-registration-run', $run);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(IgsnRegistrationItemStatus::class)],
            'issues' => ['nullable', 'boolean'],
        ];
    }

    public function status(): ?IgsnRegistrationItemStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? IgsnRegistrationItemStatus::tryFrom($status) : null;
    }

    public function issuesOnly(): bool
    {
        return $this->boolean('issues');
    }
}
