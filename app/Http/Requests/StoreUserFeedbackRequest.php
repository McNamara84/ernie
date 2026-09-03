<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreUserFeedbackRequest extends FormRequest
{
    public const MAX_DIAGNOSTIC_EVENTS = 10;

    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    protected function prepareForValidation(): void
    {
        $message = $this->input('message');
        $page = $this->input('page');

        $this->merge([
            'message' => is_string($message) ? trim($message) : $message,
            'page' => is_array($page)
                ? [
                    ...$page,
                    'path' => is_string($page['path'] ?? null) ? trim($page['path']) : ($page['path'] ?? null),
                    'title' => is_string($page['title'] ?? null) ? trim($page['title']) : ($page['title'] ?? null),
                ]
                : $page,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $relativePath = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (! str_starts_with($value, '/')
                || str_starts_with($value, '//')
                || str_contains($value, '?')
                || str_contains($value, '#')
                || parse_url($value, PHP_URL_PATH) !== $value) {
                $fail("The {$attribute} field must be a relative path without a query string or fragment.");
            }
        };

        return [
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'page' => ['required', 'array:path,title'],
            'page.path' => ['required', 'string', 'max:2048', $relativePath],
            'page.title' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'array:appearance,resolved_theme,viewport_width,viewport_height,device_pixel_ratio,locale,timezone'],
            'environment.appearance' => ['required', Rule::in(['light', 'dark', 'system'])],
            'environment.resolved_theme' => ['required', Rule::in(['light', 'dark'])],
            'environment.viewport_width' => ['required', 'integer', 'between:1,10000'],
            'environment.viewport_height' => ['required', 'integer', 'between:1,10000'],
            'environment.device_pixel_ratio' => ['required', 'numeric', 'between:0.1,10'],
            'environment.locale' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z0-9_-]+$/'],
            'environment.timezone' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_+\/-]+$/'],
            'diagnostics' => ['present', 'array', 'max:'.self::MAX_DIAGNOSTIC_EVENTS],
            'diagnostics.*' => ['required', 'array:type,occurred_at,path,method,status,message'],
            'diagnostics.*.type' => ['required', Rule::in(['navigation', 'http_error', 'javascript_error'])],
            'diagnostics.*.occurred_at' => ['required', 'date_format:Y-m-d\TH:i:s.v\Z'],
            'diagnostics.*.path' => ['sometimes', 'required', 'string', 'max:2048', $relativePath],
            'diagnostics.*.method' => ['sometimes', 'required', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'diagnostics.*.status' => ['sometimes', 'nullable', 'integer', 'between:100,599'],
            'diagnostics.*.message' => ['sometimes', 'required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowedTopLevelKeys = ['category', 'message', 'page', 'environment', 'diagnostics'];
            foreach (array_diff(array_keys($this->all()), $allowedTopLevelKeys) as $key) {
                $validator->errors()->add($key, "The {$key} field is not allowed.");
            }

            $events = $this->input('diagnostics');
            if (! is_array($events)) {
                return;
            }

            foreach ($events as $index => $event) {
                if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                    continue;
                }

                $required = match ($event['type']) {
                    'navigation' => ['path'],
                    'http_error' => ['method', 'path'],
                    'javascript_error' => ['message'],
                    default => [],
                };
                $allowed = match ($event['type']) {
                    'navigation' => ['type', 'occurred_at', 'path'],
                    'http_error' => ['type', 'occurred_at', 'method', 'path', 'status', 'message'],
                    'javascript_error' => ['type', 'occurred_at', 'message'],
                    default => array_keys($event),
                };

                foreach ($required as $key) {
                    if (! array_key_exists($key, $event)) {
                        $validator->errors()->add("diagnostics.{$index}.{$key}", "The {$key} field is required for this diagnostic event.");
                    }
                }

                foreach (array_diff(array_keys($event), $allowed) as $key) {
                    $validator->errors()->add("diagnostics.{$index}.{$key}", "The {$key} field is not allowed for this diagnostic event.");
                }
            }
        }];
    }

    /**
     * @return array{
     *     category: FeedbackCategory,
     *     message: string,
     *     page: array{path: string, title: string},
     *     environment: array{appearance: string, resolved_theme: string, viewport_width: int, viewport_height: int, device_pixel_ratio: float|int, locale: string, timezone: string},
     *     diagnostics: list<array<string, int|string|null>>
     * }
     */
    public function feedbackData(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        /** @var array<string, mixed> $page */
        $page = $validated['page'];
        /** @var array<string, mixed> $environment */
        $environment = $validated['environment'];
        /** @var list<array<string, int|string|null>> $diagnostics */
        $diagnostics = $validated['diagnostics'];

        return [
            'category' => FeedbackCategory::from((string) $validated['category']),
            'message' => (string) $validated['message'],
            'page' => [
                'path' => (string) $page['path'],
                'title' => (string) $page['title'],
            ],
            'environment' => [
                'appearance' => (string) $environment['appearance'],
                'resolved_theme' => (string) $environment['resolved_theme'],
                'viewport_width' => (int) $environment['viewport_width'],
                'viewport_height' => (int) $environment['viewport_height'],
                'device_pixel_ratio' => is_numeric($environment['device_pixel_ratio']) ? (float) $environment['device_pixel_ratio'] : 1,
                'locale' => (string) $environment['locale'],
                'timezone' => (string) $environment['timezone'],
            ],
            'diagnostics' => $diagnostics,
        ];
    }
}
