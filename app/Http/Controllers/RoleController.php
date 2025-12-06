<?php

namespace App\Http\Controllers;

use App\Models\ContributorType;
use Illuminate\Http\JsonResponse;

/**
 * Controller for returning contributor types.
 *
 * In DataCite 4.6, the concept of "roles" was replaced with
 * contributorTypes, which are standardized types like "DataCurator",
 * "ProjectLeader", "ContactPerson", etc.
 */
class RoleController extends Controller
{
    /**
     * Return all contributor types (for authors/creators).
     *
     * Note: In DataCite 4.6, creators don't have types - they are just creators.
     * This endpoint returns contributor types for backwards compatibility.
     */
    public function authorRolesForErnie(): JsonResponse
    {
        $types = ContributorType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return all contributor types for ELMO.
     */
    public function authorRolesForElmo(): JsonResponse
    {
        $types = ContributorType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return contributor types for people that are active for Ernie.
     */
    public function contributorPersonRolesForErnie(): JsonResponse
    {
        $types = ContributorType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return contributor types for people that are active for ELMO.
     */
    public function contributorPersonRolesForElmo(): JsonResponse
    {
        $types = ContributorType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return contributor types for institutions that are active for Ernie.
     */
    public function contributorInstitutionRolesForErnie(): JsonResponse
    {
        $types = ContributorType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return contributor types for institutions that are active for ELMO.
     */
    public function contributorInstitutionRolesForElmo(): JsonResponse
    {
        $types = ContributorType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }
}
