<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;

final class DestroyResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = $this->route('resource');

        // Guard against route-model-binding edge cases (missing parameter,
        // wildcard mismatch, custom binding returning a scalar). Without this
        // check `ResourcePolicy::delete(User, Resource)` would throw a
        // TypeError before Laravel can return a proper 403.
        if (! $resource instanceof Resource) {
            return false;
        }

        return $this->user()?->can('delete', $resource) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
