<?php

declare(strict_types=1);

namespace App\Http\Requests\Batch;

use App\Enums\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes and validates batch deletion of IGSN resources.
 *
 * Only administrators may delete IGSNs.
 */
class DestroyIgsnsRequest extends FormRequest
{
    /**
     * Maximum number of IGSNs that can be deleted in a single batch operation.
     * Matches MAX_PER_PAGE in IgsnController to align with UI pagination.
     */
    public const MAX_BATCH_SIZE = 100;

    /**
     * Custom 403 message preserved from the controller's previous
     * `abort(403, ...)` contract so existing UI/API consumers keep receiving
     * the same wording instead of Laravel's generic default.
     */
    public const UNAUTHORIZED_MESSAGE = 'You are not authorized to delete IGSNs.';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->role === UserRole::ADMIN;
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
        ];
    }
}
