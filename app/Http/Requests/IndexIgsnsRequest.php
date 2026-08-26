<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexIgsnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'datacenter_id' => [
                'nullable',
                'integer',
                Rule::exists('datacenters', 'id'),
                Rule::prohibitedIf(fn (): bool => $this->boolean('without_datacenter')),
            ],
            'without_datacenter' => ['nullable', 'boolean'],
        ];
    }

    public function datacenterId(): ?int
    {
        $datacenterId = $this->validated('datacenter_id');

        return $datacenterId === null || $datacenterId === ''
            ? null
            : (int) $datacenterId;
    }

    public function withoutDatacenter(): bool
    {
        return $this->boolean('without_datacenter');
    }
}
