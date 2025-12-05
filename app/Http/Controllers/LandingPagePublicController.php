<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        // For public access, landing page must be published (preview mode removed in schema simplification)
        abort_if(
            ! $landingPage->isPublished(),
            HttpResponse::HTTP_NOT_FOUND,
            'Landing page not published'
        );

        // Try to get from cache first (only for published pages)
        $cached = Cache::get("landing_page.{$resourceId}");
        if ($cached) {
            return $cached;
        }

        // Load resource with all necessary relationships
        $resource = Resource::with([
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorType',
            'contributors.affiliations',
            'titles',
            'descriptions.descriptionType',
            'rights',
            'subjects',
            'geoLocations',
            'dates.dateType',
            'relatedIdentifiers.relatedIdentifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'resourceType',
            'language',
        ])->findOrFail($resourceId);

        // Prepare data for template
        $resourceData = $resource->toArray();

        // Ensure relatedIdentifiers are properly loaded
        $resourceData['related_identifiers'] = $resource->relatedIdentifiers->map(function ($relatedId) {
            return [
                'id' => $relatedId->id,
                'identifier' => $relatedId->related_identifier,
                'identifier_type' => $relatedId->relatedIdentifierType->name,
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
                'affiliations' => $creator->affiliations->map(function ($affiliation) {
                    return [
                        'id' => $affiliation->id,
                        'name' => $affiliation->name,
                        'affiliation_identifier' => $affiliation->affiliation_identifier,
                        'affiliation_identifier_scheme' => $affiliation->affiliation_identifier_scheme,
                    ];
                })->toArray(),
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
                'affiliations' => $contributor->affiliations->map(function ($affiliation) {
                    return [
                        'id' => $affiliation->id,
                        'name' => $affiliation->name,
                        'affiliation_identifier' => $affiliation->affiliation_identifier,
                        'affiliation_identifier_scheme' => $affiliation->affiliation_identifier_scheme,
                    ];
                })->toArray(),
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
                'subject' => $subject->subject,
                'subject_scheme' => $subject->subject_scheme,
                'scheme_uri' => $subject->scheme_uri,
                'value_uri' => $subject->value_uri,
                'classification_code' => $subject->classification_code,
            ];
        })->toArray();

        $data = [
            'resource' => $resourceData,
            'landingPage' => $landingPage->toArray(),
            'isPreview' => (bool) $previewToken,
        ];

        // Render landing page (simplified - template system removed)
        $response = Inertia::render("LandingPages/default", $data);

        // Cache published pages for 24 hours
        if (! $previewToken) {
            Cache::put("landing_page.{$resourceId}", $response, now()->addDay());
        }

        return $response;
    }
}
