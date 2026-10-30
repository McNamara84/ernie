<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Services\Creators\ResourceCreatorNameResolverService;

final readonly class CurationProjectionService
{
    public function __construct(private CurationService $curation) {}

    /** @return array<string, mixed> */
    public function values(Resource $resource): array
    {
        $summary = $this->curation->summary($resource);
        $state = $resource->curationState;
        $contact = $resource->contributors->first(fn ($contributor): bool => $contributor->contributorTypes->contains('slug', 'ContactPerson'))?->contributorable;
        $creator = $resource->creators->first();
        // A polymorphic target can have been deleted while its creator row remains.
        /** @var Person|Institution|null $creatorParty */
        $creatorParty = $creator?->creatorable;
        $name = $contact === null ? ($creator === null ? '' : ($creatorParty instanceof Person ? app(ResourceCreatorNameResolverService::class)->resolve($creator, $creatorParty)['name'] : ($creatorParty->name ?? '')))
            : ($contact->name ?? trim(($contact->given_name ?? '').' '.($contact->family_name ?? '')));

        return [
            'curation_started' => $state !== null && $this->curation->eligible($resource),
            'curation_progress' => $summary['progress'], 'curation_manual_open' => $summary['manual_open'],
            'curation_open' => $summary['open'], 'curation_activity_at' => $state?->last_activity_at,
            'curation_published_at' => $resource->landingPage?->published_at,
            'curation_waiting_since' => $state?->waiting_since, 'curation_needs_help' => $state?->needs_help_text !== null,
            'curation_deadline' => $state?->deadline, 'curation_contact' => $name,
        ];
    }
}
