<?php

declare(strict_types=1);

namespace App\Http\Requests\RelatedItem;

use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates payloads for reordering related items on a resource.
 *
 * Authorization requires the user to be allowed to update the parent resource,
 * mirroring the previous controller-level `authorizeAccess()` helper.
 */
class ReorderRelatedItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $resource = $this->route('resource');

        if ($user === null || ! $resource instanceof Resource) {
            return false;
        }

        return $user->can('update', $resource);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.position' => ['required', 'integer', 'min:0'],
        ];
    }
}
