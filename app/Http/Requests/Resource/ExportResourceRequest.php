<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorisation gate for all resource export endpoints
 * (DataCite JSON / DataCite XML / JSON-LD).
 *
 * Delegates to the `view` policy on the resolved Resource model:
 * users who can view the resource can also export its metadata.
 */
final class ExportResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = $this->route('resource');

        return $this->user()?->can('view', $resource) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
