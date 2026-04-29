<?php

declare(strict_types=1);

namespace App\Http\Requests\Batch;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes and validates batch registration of regular resources at DataCite.
 *
 * Only users granted the `register-production-doi` ability may register
 * resources. The `prefix` is optional because already-registered resources
 * are updated rather than created.
 */
class RegisterResourcesRequest extends FormRequest
{
    /**
     * Maximum number of resources that can be registered in a single batch.
     *
     * Kept intentionally low because each registration performs a synchronous
     * HTTP request to the DataCite API.
     */
    public const MAX_BATCH_SIZE = 25;

    /**
     * Custom 403 message preserved from the controller's previous
     * `abort(403, ...)` contract so existing UI/API consumers keep receiving
     * the same wording instead of Laravel's generic default.
     */
    public const UNAUTHORIZED_MESSAGE = 'You are not authorized to register resources.';

    public function authorize(): bool
    {
        return $this->user()?->can('register-production-doi') === true;
    }

    /**
     * Preserve the prior controller-side 403 response message.
     */
    #[\Override]
    protected function failedAuthorization(): never
    {
        throw new AuthorizationException(self::UNAUTHORIZED_MESSAGE);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'ids.*' => ['required', 'integer', 'exists:resources,id'],
            'prefix' => ['nullable', 'string', 'max:255'],
        ];
    }
}
