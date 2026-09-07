<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasPortalMapRequestRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PortalMapClusterMembersRequest extends FormRequest
{
    use HasPortalMapRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->portalMapRules(),
            'cluster_id' => ['required', 'string', 'max:100', 'regex:/^z\d+(?:-t\d+)?:-?\d+:-?\d+$/'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'cluster_id' => $this->route('clusterId'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addPortalMapValidation($validator);
    }

    public function clusterId(): string
    {
        return (string) $this->validated('cluster_id');
    }

    public function page(): int
    {
        return max(1, (int) $this->validated('page', 1));
    }
}
