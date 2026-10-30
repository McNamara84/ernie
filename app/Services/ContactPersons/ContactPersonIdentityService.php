<?php

declare(strict_types=1);

namespace App\Services\ContactPersons;

use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Creators\ResourceCreatorNameResolverService;
use App\Support\OrcidNormalizer;

final readonly class ContactPersonIdentityService
{
    public function __construct(private ResourceCreatorNameResolverService $names) {}

    /** @return array{identity_key:string, variant_key:string, orcid:?string, display_name:string, email:?string, email_normalized:?string, name_sort:string} */
    public function point(Person $person, ResourceCreator|ResourceContributor $relation): array
    {
        $scheme = trim((string) $person->name_identifier_scheme);
        $orcid = strtoupper(OrcidNormalizer::extractBareId((string) $person->name_identifier));
        if (($scheme !== '' && strcasecmp($scheme, 'ORCID') !== 0) || ! OrcidNormalizer::isValid($orcid)) {
            $orcid = null;
        }

        $identity = $orcid === null ? 'person:'.$person->id : 'orcid:'.$orcid;
        $name = $this->names->resolve($relation, $person)['name'];
        $email = $this->names->filled($relation->email);
        $normalizedEmail = $email === null ? null : mb_strtolower($email);

        return [
            'identity_key' => $identity,
            'variant_key' => hash('sha256', json_encode([1, $identity, $name, $normalizedEmail], JSON_THROW_ON_ERROR)),
            'orcid' => $orcid,
            'display_name' => $name,
            'email' => $email,
            'email_normalized' => $normalizedEmail,
            'name_sort' => mb_strtolower($name),
        ];
    }
}
