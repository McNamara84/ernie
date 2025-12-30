<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles temporary landing page previews stored in session
 */
class LandingPagePreviewController extends Controller
{
    /**
    * Store preview data in session and return a preview URL
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'required|string|in:default_gfz',
            'ftp_url' => 'nullable|url|max:2048',
        ]);

        // Store preview data in session
        $sessionKey = "landing_page_preview.{$resource->id}";
        Session::put($sessionKey, [
            'template' => $validated['template'],
            'ftp_url' => $validated['ftp_url'] ?? null,
            'resource_id' => $resource->id,
        ]);

        return response()->json([
            'preview_url' => route('landing-page.preview.show', ['resource' => $resource->id]),
        ], 201);
    }

    /**
     * Show temporary preview from session
     */
    public function show(Resource $resource): Response
    {
        $sessionKey = "landing_page_preview.{$resource->id}";
        $previewData = Session::get($sessionKey);

        if (! $previewData) {
            abort(404, 'Preview session expired. Please open preview again from the setup modal.');
        }

        if (! is_array($previewData)) {
            abort(404, 'Preview session is invalid. Please open preview again from the setup modal.');
        }

        $template = is_string($previewData['template'] ?? null) ? $previewData['template'] : 'default_gfz';

        // Load the same shape used for public landing pages, because the React template expects it.
        $resource->load([
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
        ]);

        // Prepare the same frontend payload as LandingPagePublicController
        $resourceData = $resource->toArray();

        $resourceData['titles'] = $resource->titles->map(function ($title) {
            return [
                'id' => $title->id,
                'title' => $title->value,
                'title_type' => $title->titleType?->slug,
                'language' => $title->language,
            ];
        })->toArray();

        $resourceData['related_identifiers'] = $resource->relatedIdentifiers->map(function ($relatedId) {
            return [
                'id' => $relatedId->id,
                'identifier' => $relatedId->identifier,
                'identifier_type' => $relatedId->identifierType->name,
                'relation_type' => $relatedId->relationType->name,
                'position' => $relatedId->position,
            ];
        })->toArray();

        $resourceData['descriptions'] = $resource->descriptions->map(function ($desc) {
            return [
                'id' => $desc->id,
                'value' => $desc->value,
                'description_type' => $desc->descriptionType->name,
            ];
        })->toArray();

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

        $resourceData['contact_persons'] = $resource->creators
            ->filter(fn ($creator) => $creator->is_contact && $creator->email !== null && $creator->email !== '')
            ->sortBy('position')
            ->values()
            ->map(function ($creator) {
                /** @var \App\Models\Person|\App\Models\Institution $creatorable */
                $creatorable = $creator->creatorable;

                $isPerson = $creatorable instanceof \App\Models\Person;
                $givenName = $isPerson ? $creatorable->given_name : null;
                $familyName = $isPerson ? $creatorable->family_name : null;

                $name = '';
                if ($isPerson) {
                    $name = $givenName ? $givenName.' '.$familyName : ($familyName ?? '');
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
                    'has_email' => true,
                ];
            })->toArray();

        // Temporary landing page array for preview rendering.
        $tempLandingPage = [
            'id' => null,
            'resource_id' => $resource->id,
            'template' => $template,
            'ftp_url' => $previewData['ftp_url'] ?? null,
            'status' => 'draft',
            'preview_token' => null,
            'published_at' => null,
            'view_count' => 0,
        ];

        return Inertia::render("LandingPages/{$template}", [
            'resource' => $resourceData,
            'landingPage' => $tempLandingPage,
            'isPreview' => true,
        ]);
    }

    /**
     * Clear preview session
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $sessionKey = "landing_page_preview.{$resource->id}";
        Session::forget($sessionKey);

        return response()->json([
            'message' => 'Preview session cleared',
        ]);
    }
}
