<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Resource;
use App\Models\ResourceDate;
use Illuminate\Support\Facades\DB;

/** The local workflow policy for a DataCite Available date and embargoed access. */
final class EmbargoService
{
    public function isEmbargoed(Resource $resource): bool
    {
        return $resource->access_level === AccessLevel::EMBARGOED;
    }

    public function availableDate(Resource $resource): ?string
    {
        // A caller may have eager-loaded only the columns needed for a list.
        // Missing end_date must not make an Available range look like a single day.
        if ($resource->relationLoaded('dates') && $resource->dates->contains(
            static fn (ResourceDate $date): bool => ! array_key_exists('end_date', $date->getAttributes()),
        )) {
            $resource->unsetRelation('dates');
        }

        $resource->loadMissing('dates.dateType');
        $dates = $resource->dates->filter(
            static fn (ResourceDate $date): bool => strcasecmp($date->dateType->slug, 'available') === 0,
        );

        if ($dates->count() !== 1) {
            return null;
        }

        $date = $dates->first();
        if ($date === null || $date->end_date !== null) {
            return null;
        }

        $value = $date->date_value ?? $date->start_date;
        if (! is_string($value) || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $matches)) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : null;
    }

    public function isDue(Resource $resource): bool
    {
        $date = $this->availableDate($resource);

        return $this->isEmbargoed($resource)
            && $date !== null
            && $date <= now(config('app.timezone'))->toDateString();
    }

    public function assertCanRegister(Resource $resource): void
    {
        if (! $this->isEmbargoed($resource)) {
            return;
        }

        $date = $this->availableDate($resource);
        if ($date === null) {
            throw new \InvalidArgumentException('Embargoed access requires exactly one valid day-precision Available date.');
        }

        if (! $this->isDue($resource)) {
            throw new \InvalidArgumentException("Embargoed resources cannot be registered before {$date}.");
        }

        $resource->loadMissing('landingPage');
        if ($resource->landingPage === null || $resource->landingPage->isExternal()) {
            throw new \InvalidArgumentException('Embargo release requires an internal landing page.');
        }

        if ($resource->landingPage->is_published) {
            throw new \InvalidArgumentException('The embargoed landing page is already published; reconcile its registration before retrying.');
        }
    }

    /** Existing identifiers may be updated only when their landing page is public. */
    public function canUpdateMetadata(Resource $resource): bool
    {
        if (! $this->isEmbargoed($resource)) {
            return true;
        }

        $resource->loadMissing('landingPage');

        return $resource->landingPage?->is_published === true;
    }

    public function assertCanUpdateMetadata(Resource $resource): void
    {
        if (! $this->canUpdateMetadata($resource)) {
            throw new \InvalidArgumentException('Embargoed resources need a published landing page before DataCite metadata can be updated. Release the embargo first.');
        }
    }

    /** Claim the one create attempt allowed before remote reconciliation. */
    public function claimRegistration(Resource $resource, string $prefix): bool
    {
        if (! $this->isEmbargoed($resource)) {
            return true;
        }

        $startedAt = now();
        $claimed = DB::table('resources')
            ->where('id', $resource->id)
            ->where('access_level', AccessLevel::EMBARGOED->value)
            ->whereNull('embargo_registration_started_at')
            ->update([
                'embargo_registration_started_at' => $startedAt,
                'embargo_registration_prefix' => $prefix,
            ]) === 1;

        if ($claimed) {
            $resource->embargo_registration_started_at = $startedAt;
            $resource->embargo_registration_prefix = $prefix;
        }

        return $claimed;
    }

    /** A definite client-side rejection did not create an identifier. */
    public function clearRejectedRegistration(Resource $resource): void
    {
        DB::table('resources')
            ->where('id', $resource->id)
            ->where('embargo_registration_prefix', $resource->embargo_registration_prefix)
            ->update([
                'embargo_registration_started_at' => null,
                'embargo_registration_prefix' => null,
            ]);
        $resource->embargo_registration_started_at = null;
        $resource->embargo_registration_prefix = null;
    }

    /** Complete the local release only after DataCite has accepted the create request. */
    public function completeRelease(Resource $resource): void
    {
        if (! $this->isEmbargoed($resource)) {
            return;
        }

        DB::transaction(function () use ($resource): void {
            $resource->access_level = AccessLevel::OPEN;
            $resource->embargo_registration_started_at = null;
            $resource->embargo_registration_prefix = null;
            $resource->save();
            // The DataCite service may have claimed the attempt through a
            // separate fresh model, so clear the database marker explicitly.
            DB::table('resources')->where('id', $resource->id)->update([
                'embargo_registration_started_at' => null,
                'embargo_registration_prefix' => null,
            ]);

            $landingPage = $resource->landingPage;
            if ($landingPage === null) {
                throw new \RuntimeException('Cannot finish embargo release without a landing page.');
            }

            $landingPage->is_published = true;
            $landingPage->published_at = now();
            $landingPage->save();
        });
    }
}
