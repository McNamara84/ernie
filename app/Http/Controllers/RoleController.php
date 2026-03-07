<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContributorType;
use Illuminate\Http\JsonResponse;

/**
 * Controller for returning contributor types.
 *
 * In DataCite, the concept of "roles" was replaced with
 * contributorTypes, which are standardized types like "DataCurator",
 * "ProjectLeader", "ContactPerson", etc.
 */
class RoleController extends Controller
{
    /**
     * Return active contributor types for authors/creators (ERNIE).
     *
     * Note: In DataCite, creators don't have types - they are just creators.
     * This endpoint returns contributor types for backwards compatibility.
     */
    public function authorRolesForErnie(): JsonResponse
    {
        $types = ContributorType::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return ELMO-active contributor types for authors/creators.
     */
    public function authorRolesForElmo(): JsonResponse
    {
        $types = ContributorType::query()
            ->elmoActive()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return active contributor types for person contributors (ERNIE).
     */
    public function contributorPersonRolesForErnie(): JsonResponse
    {
        $types = ContributorType::query()
            ->active()
            ->forPersons()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return ELMO-active contributor types for person contributors.
     */
    public function contributorPersonRolesForElmo(): JsonResponse
    {
        $types = ContributorType::query()
            ->elmoActive()
            ->forPersons()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return active contributor types for institution contributors (ERNIE).
     */
    public function contributorInstitutionRolesForErnie(): JsonResponse
    {
        $types = ContributorType::query()
            ->active()
            ->forInstitutions()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return ELMO-active contributor types for institution contributors.
     */
    public function contributorInstitutionRolesForElmo(): JsonResponse
    {
        $types = ContributorType::query()
            ->elmoActive()
            ->forInstitutions()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }
}
