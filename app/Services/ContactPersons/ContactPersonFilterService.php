<?php

declare(strict_types=1);

namespace App\Services\ContactPersons;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class ContactPersonFilterService
{
    /** @param Builder<Resource> $query */
    public function apply(Builder $query, ?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $query->whereExists(static function (QueryBuilder $contacts) use ($key): void {
            $contacts->selectRaw('1')->from('resource_contact_points as contact')
                ->whereColumn('contact.resource_id', 'resources.id')->where('contact.variant_key', $key);
        });
    }

    /** @return array{key:string, name:?string, email:?string, orcid:?string}|null */
    public function describe(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        $point = DB::table('resource_contact_points')->where('variant_key', $key)->orderBy('resource_id')->first();

        return [
            'key' => $key,
            'name' => $point?->display_name,
            'email' => $point?->email,
            'orcid' => $point?->orcid,
        ];
    }
}
