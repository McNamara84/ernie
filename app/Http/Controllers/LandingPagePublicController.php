<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public Landing Page Controller
 *
 * Handles public-facing landing pages for research datasets.
 * Supports preview mode via token for draft pages.
 */
class LandingPagePublicController extends Controller
{
    /**
     * Display a public landing page for a resource
     */
    public function show(Request $request, int $resourceId): Response
    {
        $previewToken = $request->query('preview');

        // Load landing page configuration first to check status
        $landingPage = LandingPage::where('resource_id', $resourceId)->first();

        // Landing page must exist
        abort_if(! $landingPage, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

        // Check access permissions
        if (! $landingPage->isPublished()) {
            // For unpublished pages, require valid preview token
            if (! $previewToken) {
                abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
            }
            if ($previewToken !== $landingPage->preview_token) {
                abort(HttpResponse::HTTP_FORBIDDEN, 'Invalid preview token');
            }
        }

        // Increment view count only for published pages without preview token
        if ($landingPage->isPublished() && ! $previewToken) {
            $landingPage->incrementViewCount();
        }

        // Load resource with all necessary relationships
        $resource = Resource::with([
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorType',
            'contributors.affiliations',
            'titles.titleType',
            'descriptions.descriptionType',
            'rights',
            'subjects',
            'geoLocations',
            'dates.dateType',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'resourceType',
            'language',
        ])->findOrFail($resourceId);

        // Prepare data for template
        $resourceData = $resource->toArray();

        // Transform titles for frontend (expects 'title' and 'title_type' as string)
        $resourceData['titles'] = $resource->titles->map(function ($title) {
            return [
                'id' => $title->id,
                'title' => $title->value,
                'title_type' => $title->titleType?->slug,
                'language' => $title->language,
            ];
        })->toArray();

        // Ensure relatedIdentifiers are properly loaded
        $resourceData['related_identifiers'] = $resource->relatedIdentifiers->map(function ($relatedId) {
            return [
                'id' => $relatedId->id,
                'identifier' => $relatedId->identifier,
                'identifier_type' => $relatedId->identifierType->name,
                'relation_type' => $relatedId->relationType->name,
                'position' => $relatedId->position,
            ];
        })->toArray();

        // Ensure descriptions are properly loaded
        $resourceData['descriptions'] = $resource->descriptions->map(function ($desc) {
            return [
                'id' => $desc->id,
                'value' => $desc->value,
                'description_type' => $desc->descriptionType->name,
            ];
        })->toArray();

        // Ensure creators are properly loaded with affiliations
        $resourceData['creators'] = $resource->creators->map(function ($creator) {
            $creatorData = [
                'id' => $creator->id,
                'position' => $creator->position,
                'affiliations' => $creator->affiliations->map(fn (\App\Models\Affiliation $affiliation): array => [
                    'id' => $affiliation->id,
                    'name' => $affiliation->name,
                    'affiliation_identifier' => $affiliation->identifier,
                    'affiliation_identifier_scheme' => $affiliation->identifier_scheme,
                ])->toArray(),
            ];

            // Add creatorable data (Person or Institution)
            /** @var \App\Models\Person|\App\Models\Institution $creatorable */
            $creatorable = $creator->creatorable;
            $creatorData['creatorable'] = [
                'type' => class_basename($creator->creatorable_type),
                'id' => $creatorable->id,
                'given_name' => $creatorable->given_name ?? null,
                'family_name' => $creatorable->family_name ?? null,
                'name_identifier' => $creatorable->name_identifier ?? null,
                'name_identifier_scheme' => $creatorable->name_identifier_scheme ?? null,
                'name' => $creatorable->name ?? null,
            ];

            return $creatorData;
        })->toArray();

        // Ensure contributors are properly loaded with roles and affiliations
        $resourceData['contributors'] = $resource->contributors->map(function ($contributor) {
            $contributorData = [
                'id' => $contributor->id,
                'position' => $contributor->position,
                'contributor_type' => $contributor->contributorType->name,
                'affiliations' => $contributor->affiliations->map(fn (\App\Models\Affiliation $affiliation): array => [
                    'id' => $affiliation->id,
                    'name' => $affiliation->name,
                    'affiliation_identifier' => $affiliation->identifier,
                    'affiliation_identifier_scheme' => $affiliation->identifier_scheme,
                ])->toArray(),
            ];

            // Add contributorable data (Person or Institution)
            /** @var \App\Models\Person|\App\Models\Institution $contributorable */
            $contributorable = $contributor->contributorable;
            $contributorData['contributorable'] = [
                'type' => class_basename($contributor->contributorable_type),
                'id' => $contributorable->id,
                'given_name' => $contributorable->given_name ?? null,
                'family_name' => $contributorable->family_name ?? null,
                'name_identifier' => $contributorable->name_identifier ?? null,
                'name_identifier_scheme' => $contributorable->name_identifier_scheme ?? null,
                'name' => $contributorable->name ?? null,
            ];

            return $contributorData;
        })->toArray();

        // Ensure funding references are properly loaded
        $resourceData['funding_references'] = $resource->fundingReferences->map(function ($funding) {
            return [
                'id' => $funding->id,
                'funder_name' => $funding->funder_name,
                'funder_identifier' => $funding->funder_identifier,
                'funder_identifier_type' => $funding->funderIdentifierType?->name,
                'award_number' => $funding->award_number,
                'award_uri' => $funding->award_uri,
                'award_title' => $funding->award_title,
                'position' => $funding->position,
            ];
        })->toArray();

        // Ensure subjects are properly loaded
        $resourceData['subjects'] = $resource->subjects->map(function ($subject) {
            return [
                'id' => $subject->id,
                'subject' => $subject->value,
                'subject_scheme' => $subject->subject_scheme,
                'scheme_uri' => $subject->scheme_uri,
                'value_uri' => $subject->value_uri,
                'classification_code' => $subject->classification_code,
            ];
        })->toArray();

        // Ensure geoLocations coordinates are properly cast to floats for JavaScript
        $resourceData['geo_locations'] = $resource->geoLocations->map(function ($geo) {
            return [
                'id' => $geo->id,
                'place' => $geo->place,
                'point_longitude' => $geo->point_longitude !== null ? (float) $geo->point_longitude : null,
                'point_latitude' => $geo->point_latitude !== null ? (float) $geo->point_latitude : null,
                'west_bound_longitude' => $geo->west_bound_longitude !== null ? (float) $geo->west_bound_longitude : null,
                'east_bound_longitude' => $geo->east_bound_longitude !== null ? (float) $geo->east_bound_longitude : null,
                'south_bound_latitude' => $geo->south_bound_latitude !== null ? (float) $geo->south_bound_latitude : null,
                'north_bound_latitude' => $geo->north_bound_latitude !== null ? (float) $geo->north_bound_latitude : null,
                'polygon_points' => $geo->polygon_points,
            ];
        })->toArray();

        // Extract contact persons (creators marked as contact with email addresses)
        // Note: Email addresses are NOT sent to frontend for privacy
        // Using !empty() for robust validation (handles null, empty string, and falsy values)
        $resourceData['contact_persons'] = $resource->creators
            ->filter(fn ($creator) => $creator->is_contact && ! empty($creator->email))
            ->sortBy('position')
            ->values()
            ->map(function ($creator) {
                /** @var \App\Models\Person|\App\Models\Institution $creatorable */
                $creatorable = $creator->creatorable;

                // Check if it's a Person (has family_name property)
                $isPerson = $creatorable instanceof \App\Models\Person;
                $givenName = $isPerson ? $creatorable->given_name : null;
                $familyName = $isPerson ? $creatorable->family_name : null;

                // Build display name
                $name = '';
                if ($isPerson) {
                    $name = $givenName
                        ? $givenName.' '.$familyName
                        : ($familyName ?? '');
                } else {
                    $name = $creatorable->name ?? '';
                }

                return [
                    'id' => $creator->id,
                    'name' => $name,
                    'given_name' => $givenName,
                    'family_name' => $familyName,
                    'type' => class_basename($creator->creatorable_type),
                    'affiliations' => $creator->affiliations->map(fn ($aff) => [
                        'name' => $aff->name,
                        'identifier' => $aff->identifier,
                        'scheme' => $aff->identifier_scheme,
                    ])->toArray(),
                    'orcid' => ($creatorable->name_identifier_scheme ?? '') === 'ORCID'
                        ? $creatorable->name_identifier
                        : null,
                    'website' => $creator->website,
                    // NEVER send email to frontend!
                    'has_email' => true,
                ];
            })->toArray();

        $data = [
            'resource' => $resourceData,
            'landingPage' => $landingPage->toArray(),
            'isPreview' => (bool) $previewToken,
        ];

        // Use the template specified in landing page configuration
        $template = $landingPage->template ?? 'default_gfz';

        return Inertia::render("LandingPages/{$template}", $data);
    }
}
