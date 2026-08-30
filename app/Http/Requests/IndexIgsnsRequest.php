<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexIgsnsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 100;

    public const PER_PAGE_OPTIONS = [10, 100, 1000];

    public const LEGACY_PER_PAGE_OPTIONS = [25, 50];

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
            'per_page' => ['nullable', 'integer', Rule::in([...self::PER_PAGE_OPTIONS, ...self::LEGACY_PER_PAGE_OPTIONS])],
            'datacenter_id' => [
                'nullable',
                'integer',
                Rule::exists('datacenters', 'id'),
                Rule::prohibitedIf(fn (): bool => $this->boolean('without_datacenter')),
            ],
            'without_datacenter' => ['nullable', 'boolean'],
        ];
    }

    public function perPage(): int
    {
        $perPage = (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true)
            ? $perPage
            : self::DEFAULT_PER_PAGE;
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
