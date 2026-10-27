<?php

declare(strict_types=1);

namespace App\Services\Iso19115;

use App\Models\Institution;
use App\Models\LandingPageLink;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Creators\ResourceCreatorNameResolverService;
use App\Services\EmbargoService;
use App\Services\LandingPageContentLinkService;
use App\Services\LandingPageDownloadAvailabilityService;
use App\Services\ResourceAccessLevelResolverService;
use App\Support\SubjectBreadcrumbPath;
use RuntimeException;

/** Direct ERNIE-to-ISO/TR 19115-4 crosswalk for the pinned mdj/1.0.0 subset. */
class Iso19115JsonExporter
{
    /** @var array<string, string> */
    private const ROLES = [
        'ContactPerson' => 'pointOfContact', 'contact-person' => 'pointOfContact',
        'DataCollector' => 'contributor', 'DataCurator' => 'custodian', 'DataManager' => 'custodian',
        'Distributor' => 'distributor', 'Editor' => 'editor', 'HostingInstitution' => 'resourceProvider',
        'Producer' => 'originator', 'ProjectLeader' => 'principalInvestigator', 'ProjectManager' => 'principalInvestigator',
        'ProjectMember' => 'collaborator', 'Researcher' => 'contributor', 'ResearchGroup' => 'collaborator',
        'RightsHolder' => 'rightsHolder', 'Sponsor' => 'sponsor', 'Supervisor' => 'collaborator',
        'WorkPackageLeader' => 'principalInvestigator',
    ];

    public function __construct(
        private readonly Iso19115ResourceProfileService $profile,
        private readonly ResourceCreatorNameResolverService $names,
        private readonly Iso19115JsonGeometryMapper $geometryMapper,
        private readonly LandingPageContentLinkService $contentLinks,
        private readonly LandingPageDownloadAvailabilityService $downloads,
        private readonly EmbargoService $embargo,
        private readonly ResourceAccessLevelResolverService $access,
    ) {}

    /** @return array<string, mixed> */
    public function export(Resource $resource): array
    {
        if (! $this->profile->supportsJson($resource)) {
            throw new RuntimeException('The resource type is not supported by ISO 19115-4.');
        }
        $resource->loadMissing(array_values(array_filter(
            $this->profile->requiredRelations(),
            static fn (string $relation): bool => ! str_starts_with($relation, 'igsn'),
        )));
        $timestamp = $resource->created_at ?? $resource->updated_at;
        if ($timestamp === null) {
            throw new RuntimeException('A persisted metadata date is required.');
        }
        $dates = ['creation' => $timestamp->copy()->utc()->format('Y-m-d\TH:i:s\Z')];
        if ($resource->updated_at !== null && ! $resource->updated_at->equalTo($timestamp)) {
            $dates['revision'] = $resource->updated_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }
        $landingPage = $resource->landingPage;
        $landingUrl = $landingPage?->isPublished() && $landingPage->doi_prefix !== null
            ? url($landingPage->getPublicPath()) : null;
        $doi = trim((string) $resource->doi);
        $metadata = [
            'metadataIdentifier' => ['code' => $landingUrl !== null ? $landingUrl.'/metadata/iso-19115-4.json' : $doi],
            'contact' => [$this->metadataContact()],
            'dateInfo' => $dates,
            'metadataStandard' => [['title' => 'ISO/TR 19115-4', 'edition' => 'mdj/1.0.0 (Preview)']],
            'metadataScope' => [['resourceScope' => $this->profile->scopeCode($resource)]],
            'identificationInfo' => $this->identification($resource, $landingUrl),
        ];
        $locale = $this->locale($resource);
        if ($locale !== null) {
            $metadata['defaultLocale'] = $locale;
            $metadata['identificationInfo']['defaultLocale'] = $locale;
        }
        if ($landingUrl !== null) {
            $metadata['metadataLinkage'] = [$this->online($landingUrl.'/metadata/iso-19115-4.json', 'download')];
            $transfers = [['onLine' => [$this->online($landingUrl, 'information')]]];
            if (! $this->embargo->isEmbargoed($resource)) {
                $content = $this->contentLinks->resolve($resource, $landingPage);
                foreach ($content['contentLinks'] as $link) {
                    $transfer = ['onLine' => [$this->online($link['url'], 'download')]];
                    $transfer['distributionFormat'] = [['formatSpecificationCitation' => ['title' => $link['mimeType']]]];
                    if ($link['contentSize'] !== null && is_numeric($link['contentSize'])) {
                        $megabytes = (float) $link['contentSize'] / 1000000;
                        if (is_finite($megabytes)) {
                            $transfer['transferSize'] = $megabytes;
                        }
                    }
                    $transfers[] = $transfer;
                }
                // ISO does not require a MIME type; retain usable links which the
                // schema.org content resolver cannot describe without a format.
                if ($this->downloads->isAvailable($landingPage)) {
                    $files = $this->downloads->usableFiles($landingPage);
                    $urls = $files->pluck('url')->all();
                    if ($files->isEmpty() && $this->downloads->isUsableUrl($landingPage->ftp_url)) {
                        $urls[] = (string) $landingPage->ftp_url;
                    }
                    foreach ($landingPage->links->where('kind', LandingPageLink::KIND_DOWNLOAD) as $link) {
                        $urls[] = $link->url;
                    }
                    $describedUrls = array_column($content['contentLinks'], 'url');
                    foreach (array_unique($urls) as $url) {
                        if ($this->safeUrl($url) && ! in_array(trim($url), $describedUrls, true)) {
                            $transfers[] = ['onLine' => [$this->online(trim($url), 'download')]];
                        }
                    }
                }
                foreach ($content['repositories'] as $repository) {
                    $transfers[] = ['onLine' => [$this->online($repository, 'information')]];
                }
            }
            $metadata['distributionInfo'] = [['transferOptions' => $this->unique($transfers)]];
        }

        $geometries = [];
        $extents = [];
        foreach ($resource->geoLocations as $location) {
            $geometry = $this->geometryMapper->geometry($location);
            if ($geometry !== null) {
                $geometries[] = $geometry;
            }
            $extent = [];
            $place = $this->text($location->place);
            if ($place !== '') {
                $extent['geographicElement'][] = [
                    'type' => 'EX_GeographicDescription', 'extentTypeCode' => true,
                    'geographicIdentifier' => ['code' => $place],
                ];
            }
            if ($geometry !== null && $location->hasBox()) {
                $extent['geographicElement'][] = [
                    'type' => 'EX_GeographicBoundingBox', 'extentTypeCode' => true,
                    'westBoundLongitude' => (float) $location->west_bound_longitude,
                    'eastBoundLongitude' => (float) $location->east_bound_longitude,
                    'southBoundLatitude' => (float) $location->south_bound_latitude,
                    'northBoundLatitude' => (float) $location->north_bound_latitude,
                ];
            }
            if ($this->text($location->location_description) !== '') {
                $extent['description'] = $this->text($location->location_description);
            }
            if ($extent !== []) {
                $extents[] = $extent;
            }
        }
        if ($extents !== []) {
            $metadata['identificationInfo']['extent'] = $this->unique($extents);
        }
        $sources = [];
        foreach ($resource->relatedIdentifiers as $related) {
            if ($related->relationType->slug === 'IsDerivedFrom' && trim($related->identifier) !== '') {
                $sources[] = ['sourceCitation' => [
                    'title' => $this->text($related->citation_label) ?: trim($related->identifier),
                    'identifier' => [['code' => trim($related->identifier), 'codeSpace' => $related->identifierType->name]],
                ]];
            }
        }
        if ($sources !== []) {
            $metadata['resourceLineage'] = [['source' => $this->unique($sources)]];
        }
        $geometries = $this->unique($geometries);

        return [
            'conformsTo' => [(string) config('iso19115.json.schema')],
            'type' => 'Feature',
            'id' => $doi,
            'geometry' => match (count($geometries)) {
                0 => null,
                1 => $geometries[0],
                default => ['type' => 'GeometryCollection', 'geometries' => $geometries],
            },
            'properties' => $metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function identification(Resource $resource, ?string $landingUrl): array
    {
        $mainTitle = $resource->titles->first(fn ($title): bool => $title->isMainTitle() && $this->text($title->value) !== '');
        $citation = ['title' => $this->text($mainTitle?->value)];
        $alternate = $resource->titles->filter(fn ($title): bool => $mainTitle === null || ! $title->is($mainTitle))
            ->map(fn ($title): string => $this->text($title->value))->filter()->unique()->values()->all();
        if ($alternate !== []) {
            $citation['alternateTitle'] = $alternate;
        }
        if ($this->text($resource->version) !== '') {
            $citation['edition'] = $this->text($resource->version);
        }
        $identifiers = [];
        if (trim((string) $resource->doi) !== '') {
            $identifiers[] = ['code' => trim((string) $resource->doi), 'codeSpace' => 'DOI'];
        }
        foreach ($resource->alternateIdentifiers as $identifier) {
            if (trim($identifier->value) !== '') {
                $identifiers[] = ['code' => trim($identifier->value), 'codeSpace' => $identifier->type];
            }
        }
        if ($identifiers !== []) {
            $citation['identifier'] = $this->unique($identifiers);
        }
        $dates = [];
        if ($resource->publication_year !== null) {
            $dates['publication'] = (string) $resource->publication_year;
        }
        $issuedDateSelected = false;
        foreach ($resource->dates->sortBy('id') as $date) {
            $type = ['Issued' => 'publication', 'Created' => 'creation', 'Available' => 'released',
                'Updated' => 'revision', 'Withdrawn' => 'unavailable', 'Accepted' => 'adopted'][$date->dateType->slug] ?? null;
            $value = $this->date($date->date_value);
            if ($type !== null && $value !== null && $date->start_date === null && $date->end_date === null
                && (! isset($dates[$type]) || ($type === 'publication' && ! $issuedDateSelected))) {
                $dates[$type] = $value;
                if ($type === 'publication') {
                    $issuedDateSelected = true;
                }
            }
        }
        if ($dates !== []) {
            $citation['date'] = $dates;
        }
        $parties = [];
        $contacts = [];
        foreach ($resource->creators as $creator) {
            $party = $this->author($creator);
            if ($party !== null) {
                $parties[] = ['role' => 'originator', 'party' => $party];
                if ($creator->is_contact) {
                    $contacts[] = ['role' => 'pointOfContact', 'party' => $party];
                }
            }
        }
        foreach ($resource->contributors as $contributor) {
            $party = $this->author($contributor);
            if ($party === null) {
                continue;
            }
            foreach ($contributor->contributorTypes as $type) {
                $role = self::ROLES[$type->slug] ?? null;
                if ($role === 'pointOfContact') {
                    $contacts[] = ['role' => $role, 'party' => $party];
                } elseif ($role !== null) {
                    $parties[] = ['role' => $role, 'party' => $party];
                }
            }
        }
        if ($resource->publisher !== null && $this->text($resource->publisher->name) !== '') {
            $publisher = $resource->publisher;
            $parties[] = ['role' => 'publisher', 'party' => [$this->party($publisher->name, true, $publisher->identifier, $publisher->identifier_scheme)]];
        }
        $credits = [];
        foreach ($resource->fundingReferences as $funding) {
            if ($this->text($funding->funder_name) === '') {
                continue;
            }
            $parties[] = ['role' => 'funder', 'party' => [$this->party($funding->funder_name, true, $funding->funder_identifier, $funding->funderIdentifierType?->slug)]];
            $award = array_filter([$this->text($funding->award_number), $this->text($funding->award_title), $this->text($funding->award_uri)]);
            if ($award !== []) {
                $credits[] = $this->text($funding->funder_name).': '.implode('; ', $award);
            }
        }
        if ($parties !== []) {
            $citation['citedResponsibleParty'] = $this->unique($parties);
        }
        if ($landingUrl !== null) {
            $citation['onlineResource'] = [$this->online($landingUrl, 'information')];
        }
        $identification = ['citation' => $citation, 'abstract' => ''];
        $supplemental = [];
        foreach ($resource->descriptions as $description) {
            $value = $this->richText($description->value);
            if ($value === '') {
                continue;
            }
            if ($description->descriptionType->slug === 'Abstract' && $identification['abstract'] === '') {
                $identification['abstract'] = $value;
            } elseif ($description->descriptionType->slug === 'SeriesInformation') {
                $identification['citation']['series'] = ['name' => $value];
            } else {
                $supplemental[] = $description->descriptionType->name.': '.$value;
            }
        }
        if ($supplemental !== []) {
            $identification['supplementalInformation'] = implode("\n\n", array_unique($supplemental));
        }
        if ($contacts !== []) {
            $identification['pointOfContact'] = $this->unique($contacts);
        }
        if ($credits !== []) {
            $identification['credit'] = array_values(array_unique($credits));
        }
        $keywords = [];
        foreach ($resource->subjects as $subject) {
            $value = $this->text($subject->value);
            if ($value === '') {
                continue;
            }
            if ($subject->subject_scheme && ! $subject->value_uri) {
                $value = SubjectBreadcrumbPath::normalize($subject->breadcrumb_path) ?? $value;
            }
            $keyword = ['keyword' => [$value]];
            if ($this->text($subject->subject_scheme) !== '') {
                $keyword['thesaurusName'] = ['title' => $this->text($subject->subject_scheme)];
                if ($this->safeUrl($subject->scheme_uri)) {
                    $keyword['thesaurusName']['onlineResource'] = [$this->online(trim((string) $subject->scheme_uri), 'information')];
                }
            }
            $keywords[] = $keyword;
        }
        if ($keywords !== []) {
            $identification['descriptiveKeywords'] = $this->unique($keywords);
        }
        $formats = [];
        foreach ($resource->formats as $format) {
            if ($this->text($format->value) !== '') {
                $formats[] = ['formatSpecificationCitation' => ['title' => $this->text($format->value)]];
            }
        }
        if ($formats !== []) {
            $identification['resourceFormat'] = $this->unique($formats);
        }
        $constraints = [];
        foreach ($resource->resourceRights as $right) {
            $name = $this->text($right->right?->name ?: $right->rights_text);
            $uri = $right->right?->uri ?: $right->rights_uri;
            $constraint = ['type' => 'MD_LegalConstraints'];
            if ($name !== '') {
                $constraint['useLimitation'] = [$name];
            }
            if ($this->safeUrl($uri)) {
                $constraint['reference'] = [['title' => $name !== '' ? $name : $uri,
                    'onlineResource' => [$this->online(trim((string) $uri), 'information')]]];
            }
            if (count($constraint) > 1) {
                $constraints[] = $constraint;
            }
        }
        $access = $this->access->resolve($resource, $resource->landingPage);
        if ($access !== null) {
            $constraints[] = ['type' => 'MD_LegalConstraints', 'accessConstraints' => ['otherRestrictions'],
                'otherConstraints' => [$access->label()],
                'reference' => [['title' => $access->label(), 'onlineResource' => [$this->online($access->coarUri(), 'information')]]]];
        }
        if ($constraints !== []) {
            $identification['resourceConstraints'] = $this->unique($constraints);
        }
        $documentation = [];
        foreach ($resource->relatedIdentifiers as $related) {
            if (! in_array($related->relationType->slug, ['IsDocumentedBy', 'IsDescribedBy'], true)) {
                continue;
            }
            $value = trim($related->identifier);
            if ($value !== '') {
                $documentation[] = ['title' => $this->text($related->citation_label) ?: $value, 'identifier' => [['code' => $value, 'codeSpace' => $related->identifierType->name]]];
            }
        }
        if ($documentation !== []) {
            $identification['additionalDocumentation'] = $this->unique($documentation);
        }

        return $identification;
    }

    /** @return list<array<string, mixed>>|null */
    private function author(ResourceCreator|ResourceContributor $author): ?array
    {
        $model = $author->getRelation($author instanceof ResourceCreator ? 'creatorable' : 'contributorable');
        if (! $model instanceof Person && ! $model instanceof Institution) {
            return null;
        }
        $name = $model instanceof Person ? $this->names->resolve($author, $model)['name'] : $model->name;
        if (trim($name) === '' || $name === 'Unknown') {
            return null;
        }
        $party = $this->party($name, $model instanceof Institution, $model->name_identifier, $model->name_identifier_scheme);
        if ($this->safeUrl($author->website)) {
            $party['contactInfo'] = [['onlineResource' => [$this->online(trim((string) $author->website), 'information')]]];
        }
        // Resource email addresses are used by the contact form, not published here.
        $parties = [];
        if ($model instanceof Person) {
            foreach ($author->affiliations as $affiliation) {
                if ($this->text($affiliation->name) !== '') {
                    $organisation = $this->party($affiliation->name, true, $affiliation->identifier, $affiliation->identifier_scheme);
                    $organisation['individual'] = [$party];
                    $parties[] = $organisation;
                }
            }
        }

        return $parties !== [] ? $this->unique($parties) : [$party];
    }

    /** @return array<string, mixed> */
    private function party(string $name, bool $organisation, ?string $identifier = null, ?string $scheme = null): array
    {
        $party = ['type' => $organisation ? 'CI_Organisation' : 'CI_Individual', 'name' => $this->text($name)];
        if ($identifier !== null && trim($identifier) !== '') {
            $id = ['code' => trim($identifier)];
            if ($scheme !== null && trim($scheme) !== '') {
                $id['codeSpace'] = trim($scheme);
            }
            $party['partyIdentifier'] = [$id];
        }

        return $party;
    }

    /** @return array<string, mixed> */
    private function metadataContact(): array
    {
        $name = (string) config('iso19115.metadata_contact.name');
        if (trim($name) === '') {
            throw new RuntimeException('The metadata contact name is required.');
        }
        $party = $this->party($name, true);
        $contact = [];
        $email = (string) config('iso19115.metadata_contact.email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $contact['address'] = [['electronicMailAddress' => [$email]]];
        }
        $website = (string) config('iso19115.metadata_contact.website');
        if ($this->safeUrl($website)) {
            $contact['onlineResource'] = [$this->online($website, 'information')];
        }
        if ($contact !== []) {
            $party['contactInfo'] = [$contact];
        }

        return ['role' => 'pointOfContact', 'party' => [$party]];
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^([0-9]{4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?$/D', $value, $matches) === 1) {
            return checkdate((int) ($matches[2] ?? 1), (int) ($matches[3] ?? 1), (int) $matches[1]) ? $value : null;
        }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) === 1) {
            try {
                new \DateTimeImmutable($value);

                return \DateTimeImmutable::getLastErrors() === false ? $value : null;
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /** @return array{language: string, characterEncoding: string}|null */
    private function locale(Resource $resource): ?array
    {
        $language = strtolower(trim((string) $resource->language?->code));
        if ($language === '') {
            return null;
        }
        $primary = explode('-', str_replace('_', '-', $language))[0];
        $language = ['en' => 'eng', 'de' => 'deu', 'fr' => 'fra', 'es' => 'spa', 'it' => 'ita',
            'pt' => 'por', 'nl' => 'nld', 'pl' => 'pol', 'ru' => 'rus', 'zh' => 'zho', 'ja' => 'jpn'][$primary] ?? $primary;

        return ['language' => $language, 'characterEncoding' => 'utf8'];
    }

    private function text(?string $value): string
    {
        return trim((string) $value);
    }

    private function richText(?string $value): string
    {
        $value = preg_replace('~<(?:br\s*/?|/p|/div|/li)>~i', "\n", (string) $value) ?? '';

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function safeUrl(?string $value): bool
    {
        return $value !== null && filter_var(trim($value), FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url(trim($value), PHP_URL_SCHEME)), ['http', 'https'], true)
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /** @return array{linkage: string, function: string} */
    private function online(string $url, string $function): array
    {
        return ['linkage' => $url, 'function' => $function];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function unique(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $unique[json_encode($item, JSON_THROW_ON_ERROR)] = $item;
        }

        return array_values($unique);
    }
}
