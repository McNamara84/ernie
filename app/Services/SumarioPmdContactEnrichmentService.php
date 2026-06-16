<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Support\UriHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SumarioPmdContactEnrichmentService
{
    private const CONNECTION = 'metaworks';

    private const CONTACT_FIELD_MAX_LENGTH = 255;

    public function enrich(Resource $resource, string $doi): bool
    {
        try {
            $contacts = $this->loadContacts($doi);
        } catch (\Throwable $exception) {
            Log::warning('SUMARIO contact lookup failed', [
                'doi' => $doi,
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($contacts === []) {
            return false;
        }

        $resource->loadMissing([
            'creators.creatorable',
            'contributors.contributorable',
        ]);

        $updated = false;

        foreach ($contacts as $contact) {
            foreach ($resource->creators as $creator) {
                if (! $this->matchesEntity($creator->creatorable, $contact)) {
                    continue;
                }

                if ($this->updateCreatorContact($creator, $contact)) {
                    $updated = true;
                }
            }

            foreach ($resource->contributors as $contributor) {
                if (! $this->matchesEntity($contributor->contributorable, $contact)) {
                    continue;
                }

                if ($this->updateContributorContact($contributor, $contact)) {
                    $updated = true;
                }
            }
        }

        if ($updated) {
            $resource->touch();
        }

        return $updated;
    }

    /**
     * @return list<array{order: int, name: string|null, firstname: string|null, lastname: string|null, email: string|null, website: string|null}>
     */
    private function loadContacts(string $doi): array
    {
        $oldResource = DB::connection(self::CONNECTION)
            ->table('resource')
            ->where('identifier', $doi)
            ->select('id')
            ->first();

        if ($oldResource === null) {
            return [];
        }

        $rows = DB::connection(self::CONNECTION)
            ->table('contactinfo as ci')
            ->join('resourceagent as ra', function ($join): void {
                $join->on('ci.resourceagent_resource_id', '=', 'ra.resource_id')
                    ->on('ci.resourceagent_order', '=', 'ra.order');
            })
            ->where('ci.resourceagent_resource_id', $oldResource->id)
            ->where(function ($query): void {
                $query->whereNotNull('ci.email')
                    ->orWhereNotNull('ci.website');
            })
            ->orderBy('ci.resourceagent_order')
            ->get([
                'ci.resourceagent_order as order',
                'ra.name',
                'ra.firstname',
                'ra.lastname',
                'ci.email',
                'ci.website',
            ]);

        $contacts = [];

        foreach ($rows as $row) {
            $email = $this->validatedEmail($row->email ?? null, $doi);
            $website = $this->validatedWebsite($row->website ?? null, $doi);

            if ($email === null && $website === null) {
                continue;
            }

            $contacts[] = [
                'order' => (int) $row->order,
                'name' => $this->filledString($row->name ?? null),
                'firstname' => $this->filledString($row->firstname ?? null),
                'lastname' => $this->filledString($row->lastname ?? null),
                'email' => $email,
                'website' => $website,
            ];
        }

        return $contacts;
    }

    /**
     * @param  array{order: int, name: string|null, firstname: string|null, lastname: string|null, email: string|null, website: string|null}  $contact
     */
    private function updateCreatorContact(ResourceCreator $creator, array $contact): bool
    {
        $creator->forceFill([
            'is_contact' => true,
            'email' => $contact['email'] ?? $creator->email,
            'website' => $contact['website'] ?? $creator->website,
        ])->save();

        return $creator->wasChanged(['is_contact', 'email', 'website']);
    }

    /**
     * @param  array{order: int, name: string|null, firstname: string|null, lastname: string|null, email: string|null, website: string|null}  $contact
     */
    private function updateContributorContact(ResourceContributor $contributor, array $contact): bool
    {
        $contributor->forceFill([
            'email' => $contact['email'] ?? $contributor->email,
            'website' => $contact['website'] ?? $contributor->website,
        ])->save();

        return $contributor->wasChanged(['email', 'website']);
    }

    /**
     * @param  array{order: int, name: string|null, firstname: string|null, lastname: string|null, email: string|null, website: string|null}  $contact
     */
    private function matchesEntity(?Model $entity, array $contact): bool
    {
        if ($entity instanceof Person) {
            return $this->namesOverlap($this->personNameCandidates($entity), $this->contactNameCandidates($contact));
        }

        if ($entity instanceof Institution) {
            return $this->namesOverlap([$entity->name], $this->contactNameCandidates($contact));
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function personNameCandidates(Person $person): array
    {
        $candidates = [];

        if ($person->family_name !== null && $person->given_name !== null) {
            $candidates[] = "{$person->family_name}, {$person->given_name}";
            $candidates[] = "{$person->given_name} {$person->family_name}";
        }

        $candidates[] = $person->full_name;

        return $candidates;
    }

    /**
     * @param  array{order: int, name: string|null, firstname: string|null, lastname: string|null, email: string|null, website: string|null}  $contact
     * @return list<string>
     */
    private function contactNameCandidates(array $contact): array
    {
        $candidates = [];

        if ($contact['name'] !== null) {
            $candidates[] = $contact['name'];
        }

        if ($contact['lastname'] !== null && $contact['firstname'] !== null) {
            $candidates[] = "{$contact['lastname']}, {$contact['firstname']}";
            $candidates[] = "{$contact['firstname']} {$contact['lastname']}";
        }

        return $candidates;
    }

    /**
     * @param  list<string|null>  $left
     * @param  list<string|null>  $right
     */
    private function namesOverlap(array $left, array $right): bool
    {
        $normalisedLeft = array_filter(array_map($this->normaliseName(...), $left));
        $normalisedRight = array_filter(array_map($this->normaliseName(...), $right));

        return array_intersect($normalisedLeft, $normalisedRight) !== [];
    }

    private function normaliseName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $normalised = mb_strtolower(trim($name), 'UTF-8');
        $normalised = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalised) ?: $normalised;
        $normalised = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalised) ?? '';
        $normalised = preg_replace('/\s+/', ' ', $normalised) ?? '';

        return trim($normalised);
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function validatedEmail(mixed $value, string $doi): ?string
    {
        $email = $this->filledString($value);

        if ($email === null) {
            return null;
        }

        if (
            mb_strlen($email) > self::CONTACT_FIELD_MAX_LENGTH
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            Log::warning('Skipping invalid SUMARIO contact email', [
                'doi' => $doi,
            ]);

            return null;
        }

        return $email;
    }

    private function validatedWebsite(mixed $value, string $doi): ?string
    {
        $website = $this->filledString($value);

        if ($website === null) {
            return null;
        }

        $uri = UriHelper::parse($website);
        $scheme = strtolower($uri?->getScheme() ?? '');
        $host = trim($uri?->getHost() ?? '');

        if (
            mb_strlen($website) > self::CONTACT_FIELD_MAX_LENGTH
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
        ) {
            Log::warning('Skipping invalid SUMARIO contact website', [
                'doi' => $doi,
            ]);

            return null;
        }

        return $website;
    }
}
