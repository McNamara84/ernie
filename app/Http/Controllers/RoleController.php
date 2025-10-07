<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * Return all author roles active for Ernie.
     */
    public function authorRolesForErnie(): JsonResponse
    {
        $roles = Role::query()
            ->authors()
            ->activeInErnie()
            ->ordered()
            ->get(['id', 'name', 'slug']);

        return response()->json($roles);
    }

    /**
     * Return all author roles active for ELMO.
     */
    public function authorRolesForElmo(): JsonResponse
    {
        $roles = Role::query()
            ->authors()
            ->activeInElmo()
            ->ordered()
            ->get(['id', 'name', 'slug']);

        return response()->json($roles);
    }

    /**
     * Return contributor roles for people that are active for Ernie.
     */
    public function contributorPersonRolesForErnie(): JsonResponse
    {
        $roles = Role::query()
            ->contributorPersons()
            ->activeInErnie()
            ->ordered()
            ->get(['id', 'name', 'slug']);

        return response()->json($roles);
    }

    /**
     * Return contributor roles for people that are active for ELMO.
     */
    public function contributorPersonRolesForElmo(): JsonResponse
    {
        $roles = Role::query()
            ->contributorPersons()
            ->activeInElmo()
            ->ordered()
            ->get(['id', 'name', 'slug']);

        return response()->json($roles);
    }

    /**
     * Return contributor roles for institutions that are active for Ernie.
     */
    public function contributorInstitutionRolesForErnie(): JsonResponse
    {
        $roles = Role::query()
            ->contributorInstitutions()
            ->activeInErnie()
            ->ordered()
            ->get(['id', 'name', 'slug']);

        return response()->json($roles);
    }

    /**
     * Return contributor roles for institutions that are active for ELMO.
     */
    public function contributorInstitutionRolesForElmo(): JsonResponse
    {
        $roles = Role::query()
            ->contributorInstitutions()
            ->activeInElmo()
            ->ordered()
            ->get(['id', 'name', 'slug']);

        return response()->json($roles);
    }
}
