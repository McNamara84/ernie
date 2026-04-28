<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource\Concerns;

use Illuminate\Validation\Rule;

/**
 * Shared validation rules and criteria extraction for resource listing endpoints.
 *
 * Used by IndexResourcesRequest (Inertia render) and LoadMoreResourcesRequest
 * (JSON pagination). Both expose the same query parameters and produce the same
 * structured criteria array consumed by the controller / query builder.
 */
trait ResolvesResourceListing
{
    /**
     * Sort keys recognised by the resource listing query builder.
     *
     * Note: 'publicstatus' is a computed virtual field that falls back to id sorting.
     */
    public const ALLOWED_SORT_KEYS = [
        'id',
        'doi',
        'title',
        'resourcetypegeneral',
        'first_author',
        'year',
        'curator',
        'publicstatus',
        'created_at',
        'updated_at',
    ];

    public const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    public const ALLOWED_STATUSES = ['draft', 'curation', 'review', 'published'];

    public const DEFAULT_PER_PAGE = 50;

    public const MIN_PER_PAGE = 1;

    public const MAX_PER_PAGE = 100;

    public const DEFAULT_SORT_KEY = 'updated_at';

    public const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Normalise multi-value filters (resource_type, curator, status) into arrays
     * so the `.*` rules — in particular `Rule::in(ALLOWED_STATUSES)` — apply
     * uniformly whether the client sends `?status=draft` or `?status[]=draft`.
     */
    protected function normaliseListingInput(): void
    {
        foreach (['resource_type', 'curator', 'status'] as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_array($value)) {
                $this->merge([$key => [$value]]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function listingRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:'.self::MIN_PER_PAGE, 'max:'.self::MAX_PER_PAGE],
            'sort_key' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_KEYS)],
            'sort_direction' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_DIRECTIONS)],

            'resource_type' => ['nullable', 'array'],
            'resource_type.*' => ['nullable', 'string'],
            'curator' => ['nullable', 'array'],
            'curator.*' => ['nullable', 'string'],
            'status' => ['nullable', 'array'],
            'status.*' => ['nullable', 'string', Rule::in(self::ALLOWED_STATUSES)],

            'year_from' => ['nullable', 'integer', 'between:1000,9999'],
            'year_to' => ['nullable', 'integer', 'between:1000,9999'],

            'search' => ['nullable', 'string', 'max:255'],

            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'updated_from' => ['nullable', 'date'],
            'updated_to' => ['nullable', 'date'],
        ];
    }

    /**
     * Return validated, typed listing criteria.
     *
     * @return array{
     *     page:int,
     *     perPage:int,
     *     sortKey:string,
     *     sortDirection:string,
     *     filters:array<string,mixed>
     * }
     */
    public function toCriteria(): array
    {
        $page = max(1, (int) ($this->validated('page') ?? 1));

        $perPage = (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        $sortKey = strtolower((string) ($this->validated('sort_key') ?? self::DEFAULT_SORT_KEY));
        if (! in_array($sortKey, self::ALLOWED_SORT_KEYS, true)) {
            $sortKey = self::DEFAULT_SORT_KEY;
        }

        $sortDirection = strtolower((string) ($this->validated('sort_direction') ?? self::DEFAULT_SORT_DIRECTION));
        if (! in_array($sortDirection, self::ALLOWED_SORT_DIRECTIONS, true)) {
            $sortDirection = self::DEFAULT_SORT_DIRECTION;
        }

        return [
            'page' => $page,
            'perPage' => $perPage,
            'sortKey' => $sortKey,
            'sortDirection' => $sortDirection,
            'filters' => $this->extractFilters(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractFilters(): array
    {
        $filters = [];

        $filters = $this->collectMultiValueFilter($filters, 'resource_type');
        $filters = $this->collectMultiValueFilter($filters, 'curator');
        $filters = $this->collectMultiValueFilter($filters, 'status');

        $yearFrom = $this->input('year_from');
        if ($yearFrom !== null && $yearFrom !== '' && is_numeric($yearFrom)) {
            $filters['year_from'] = (int) $yearFrom;
        }

        $yearTo = $this->input('year_to');
        if ($yearTo !== null && $yearTo !== '' && is_numeric($yearTo)) {
            $filters['year_to'] = (int) $yearTo;
        }

        $search = trim((string) $this->input('search', ''));
        if ($search !== '') {
            $filters['search'] = $search;
        }

        foreach (['created_from', 'created_to', 'updated_from', 'updated_to'] as $dateKey) {
            $value = $this->input($dateKey);
            if ($value !== null && $value !== '') {
                $filters[$dateKey] = $value;
            }
        }

        return $filters;
    }

    /**
     * Normalise a filter that may arrive as a single string or an array of strings.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function collectMultiValueFilter(array $filters, string $key): array
    {
        if (! $this->has($key)) {
            return $filters;
        }

        $value = $this->input($key);

        if (is_array($value)) {
            $value = array_values(array_filter(
                $value,
                static fn ($item): bool => $item !== null && $item !== ''
            ));

            if ($value !== []) {
                $filters[$key] = $value;
            }

            return $filters;
        }

        if ($value !== null && $value !== '') {
            $filters[$key] = [$value];
        }

        return $filters;
    }
}
