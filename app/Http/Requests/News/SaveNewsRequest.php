<?php

declare(strict_types=1);

namespace App\Http\Requests\News;

use App\Enums\NewsKind;
use App\Services\News\NewsLocalTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-news') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body_markdown' => ['required', 'string', 'max:100000'],
            'kind' => ['required', Rule::enum(NewsKind::class)],
            'revision' => [$this->route('news') === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'required_if:kind,temporary', 'prohibited_unless:kind,temporary', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'required_if:kind,temporary', 'prohibited_unless:kind,temporary', 'date_format:Y-m-d\TH:i'],
            'starts_at_offset' => ['nullable', 'prohibited_unless:kind,temporary', Rule::in(['+01:00', '+02:00'])],
            'ends_at_offset' => ['nullable', 'prohibited_unless:kind,temporary', Rule::in(['+01:00', '+02:00'])],
        ];
    }

    /** @return array{title: string, body_markdown: string, kind: NewsKind, starts_at: CarbonImmutable|null, ends_at: CarbonImmutable|null} */
    public function newsData(): array
    {
        /** @var array{title: string, body_markdown: string, kind: string, starts_at?: string|null, ends_at?: string|null, starts_at_offset?: string|null, ends_at_offset?: string|null} $data */
        $data = $this->validated();
        $kind = NewsKind::from($data['kind']);
        $clock = app(NewsLocalTimeService::class);

        return [
            'title' => $data['title'], 'body_markdown' => $data['body_markdown'], 'kind' => $kind,
            'starts_at' => $kind === NewsKind::TEMPORARY ? $clock->parse((string) ($data['starts_at'] ?? ''), $data['starts_at_offset'] ?? null, 'starts_at') : null,
            'ends_at' => $kind === NewsKind::TEMPORARY ? $clock->parse((string) ($data['ends_at'] ?? ''), $data['ends_at_offset'] ?? null, 'ends_at') : null,
        ];
    }
}
