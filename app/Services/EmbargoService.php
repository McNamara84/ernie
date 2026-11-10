<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Resource;
use App\Models\ResourceDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Data access policy; an embargo never prevents public metadata. */
final class EmbargoService
{
    public function isEmbargoed(Resource $resource): bool
    {
        if ($resource->landingPage?->is_tombstone) {
            return false;
        }

        return $resource->access_level === AccessLevel::EMBARGOED
            || $this->hasFutureAvailableDate($resource);
    }

    private function hasFutureAvailableDate(Resource $resource): bool
    {
        if (! $resource->exists && ! $resource->relationLoaded('dates')) {
            return false;
        }
        // Also load complete date attributes. Ambiguity must block release, not
        // make a future date invisible to the data-access guard.
        $this->availableDate($resource);
        foreach ($resource->dates as $date) {
            if (strcasecmp($date->dateType->slug, 'available') === 0) {
                $day = $this->validDay($date->date_value ?? $date->start_date);
                if ($day !== null && $day > $this->today()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function today(): string
    {
        return now(config('app.timezone'))->toDateString();
    }

    public function validDay(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $matches)) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : null;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeInput(array $data, ?Resource $existing = null): array
    {
        $dates = $data['dates'] ?? null;
        $future = false;
        if (is_array($dates)) {
            foreach ($dates as $date) {
                if (is_array($date) && is_string($date['dateType'] ?? null) && Str::kebab($date['dateType']) === 'available') {
                    $day = $this->validDay($date['startDate'] ?? null);
                    $future = $future || ($day !== null && $day > $this->today());
                }
            }
        } elseif ($existing !== null) {
            $future = ($day = $this->availableDate($existing)) !== null && $day > $this->today();
        }

        // Only the explicit release action may end an existing data embargo.
        if ($future || ($existing !== null && $this->isEmbargoed($existing))) {
            $data['accessLevel'] = AccessLevel::EMBARGOED->value;
        }

        return $data;
    }

    public function normalizeResource(Resource $resource): void
    {
        if (! $resource->landingPage?->is_tombstone && $this->hasFutureAvailableDate($resource) && $resource->access_level !== AccessLevel::EMBARGOED) {
            $resource->access_level = AccessLevel::EMBARGOED;
            $resource->save();
        }
    }

    public function availableDate(Resource $resource): ?string
    {
        if (! $resource->exists && ! $resource->relationLoaded('dates')) {
            return null;
        }
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

        return $this->validDay($value);
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

        $resource->loadMissing('landingPage');
        if ($resource->landingPage === null || $resource->landingPage->isExternal()) {
            throw new \InvalidArgumentException('Data embargoes require an internal landing page.');
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
            throw new \InvalidArgumentException('Embargoed resources need a published landing page before DataCite metadata can be updated. The data may remain under embargo.');
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
                'embargo_registration_access_level' => AccessLevel::EMBARGOED->value,
            ]) === 1;

        if ($claimed) {
            $resource->embargo_registration_started_at = $startedAt;
            $resource->embargo_registration_prefix = $prefix;
            $resource->embargo_registration_access_level = AccessLevel::EMBARGOED->value;
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
                'embargo_registration_access_level' => null,
            ]);
        $resource->embargo_registration_started_at = null;
        $resource->embargo_registration_prefix = null;
        $resource->embargo_registration_access_level = null;
    }

    /** Finish metadata publication after a confirmed create; keep the data embargo. */
    public function completeRegistration(Resource $resource): void
    {
        if (! $this->isEmbargoed($resource)) {
            return;
        }

        DB::transaction(function () use ($resource): void {
            $resource->embargo_registration_started_at = null;
            $resource->embargo_registration_prefix = null;
            $resource->embargo_registration_access_level = null;
            $resource->save();
            // The DataCite service may have claimed the attempt through a
            // separate fresh model, so clear the database marker explicitly.
            DB::table('resources')->where('id', $resource->id)->update([
                'embargo_registration_started_at' => null,
                'embargo_registration_prefix' => null,
                'embargo_registration_access_level' => null,
            ]);

            $landingPage = $resource->landingPage;
            if ($landingPage === null) {
                throw new \RuntimeException('Cannot finish metadata publication without a landing page.');
            }

            $landingPage->is_published = true;
            $landingPage->published_at = now();
            $landingPage->save();
        });
    }
}
