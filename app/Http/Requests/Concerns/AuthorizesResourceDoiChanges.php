<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Resource;
use App\Policies\ResourcePolicy;
use Illuminate\Auth\Access\AuthorizationException;

trait AuthorizesResourceDoiChanges
{
    public const DOI_CHANGE_UNAUTHORIZED_MESSAGE = ResourcePolicy::DOI_CHANGE_UNAUTHORIZED_MESSAGE;

    /**
     * Authorize DOI changes to an existing resource while leaving malformed
     * request data to the request's validation rules.
     */
    protected function isResourceDoiChangeAuthorized(): bool
    {
        $resourceId = $this->input('resourceId');

        if (! is_int($resourceId) && ! (is_string($resourceId) && ctype_digit($resourceId))) {
            return true;
        }

        if (! $this->has('doi')) {
            return true;
        }

        $doi = $this->input('doi');

        if ($doi !== null && ! is_string($doi)) {
            return true;
        }

        $resource = Resource::query()
            ->with('landingPage')
            ->find((int) $resourceId);

        if (! $resource instanceof Resource) {
            return true;
        }

        return $this->user()?->can('changeDoi', [$resource, $doi]) === true;
    }

    #[\Override]
    protected function failedAuthorization(): never
    {
        throw new AuthorizationException(self::DOI_CHANGE_UNAUTHORIZED_MESSAGE);
    }
}
