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
     *
     * @param Request $request
     * @param int $resourceId
     * @return Response
     */
    public function show(Request $request, int $resourceId): Response
    {
        $previewToken = $request->query('preview');

        // Try to get from cache first (only for published pages without preview token)
        if (! $previewToken) {
            $cached = Cache::get("landing_page.{$resourceId}");
            if ($cached) {
                return $cached;
            }
        }

        // Load resource with all necessary relationships
        $resource = Resource::with([
            'authors.authorable',
            'authors.affiliations',
            'authors.roles',
            'titles',
            'descriptions',
            'licenses',
            'keywords',
            'controlledKeywords',
            'coverages',
            'dates',
            'relatedIdentifiers',
            'fundingReferences',
            'resourceType',
            'language',
        ])->findOrFail($resourceId);

        // Load landing page configuration
        $landingPage = LandingPage::where('resource_id', $resourceId)->first();

        // If preview token is provided, validate it
        if ($previewToken) {
            abort_if(
                ! $landingPage || $landingPage->preview_token !== $previewToken,
                HttpResponse::HTTP_FORBIDDEN,
                'Invalid preview token'
            );
        } else {
            // For public access, landing page must exist and be published
            abort_if(
                ! $landingPage || $landingPage->status !== 'published',
                HttpResponse::HTTP_NOT_FOUND,
                'Landing page not found or not published'
            );

            // Increment view count for published pages
            $landingPage->incrementViewCount();
        }

        // Prepare data for template
        $resourceData = $resource->toArray();
        
        // Ensure relatedIdentifiers are properly loaded
        $resourceData['related_identifiers'] = $resource->relatedIdentifiers->map(function ($relatedId) {
            return [
                'id' => $relatedId->id,
                'identifier' => $relatedId->identifier,
                'identifier_type' => $relatedId->identifier_type,
                'relation_type' => $relatedId->relation_type,
                'position' => $relatedId->position,
                'related_title' => $relatedId->related_title,
                'related_metadata' => $relatedId->related_metadata,
            ];
        })->toArray();

        // Ensure descriptions are properly loaded
        $resourceData['descriptions'] = $resource->descriptions->map(function ($desc) {
            return [
                'id' => $desc->id,
                'description' => $desc->description,
                'description_type' => $desc->description_type,
            ];
        })->toArray();

        // Ensure authors are properly loaded with roles and affiliations
        $resourceData['authors'] = $resource->authors->map(function ($author) {
            $authorData = [
                'id' => $author->id,
                'position' => $author->position,
                'email' => $author->email,
                'website' => $author->website,
                'roles' => $author->roles->pluck('name')->toArray(),
                'affiliations' => $author->affiliations->map(function ($affiliation) {
                    return [
                        'id' => $affiliation->id,
                        'value' => $affiliation->value,
                        'ror_id' => $affiliation->ror_id,
                    ];
                })->toArray(),
            ];

            // Add authorable data (Person or Institution)
            if ($author->authorable) {
                $authorData['authorable'] = [
                    'type' => class_basename($author->authorable_type),
                    'id' => $author->authorable->id,
                    'first_name' => $author->authorable->first_name ?? null,
                    'last_name' => $author->authorable->last_name ?? null,
                    'orcid' => $author->authorable->orcid ?? null,
                    'name' => $author->authorable->name ?? null,
                ];
            }

            return $authorData;
        })->toArray();

        // Ensure funding references are properly loaded
        $resourceData['funding_references'] = $resource->fundingReferences->map(function ($funding) {
            return [
                'id' => $funding->id,
                'funder_name' => $funding->funder_name,
                'funder_identifier' => $funding->funder_identifier,
                'funder_identifier_type' => $funding->funder_identifier_type,
                'award_number' => $funding->award_number,
                'award_uri' => $funding->award_uri,
                'award_title' => $funding->award_title,
                'position' => $funding->position,
            ];
        })->toArray();

        $data = [
            'resource' => $resourceData,
            'landingPage' => $landingPage->toArray(),
            'isPreview' => (bool) $previewToken,
        ];

        // Render via template system (will be implemented in Sprint 3 Step 12)
        $response = Inertia::render("LandingPages/{$landingPage->template}", $data);

        // Cache published pages for 24 hours
        if (! $previewToken && $landingPage->status === 'published') {
            Cache::put("landing_page.{$resourceId}", $response, now()->addDay());
        }

        return $response;
    }
}
