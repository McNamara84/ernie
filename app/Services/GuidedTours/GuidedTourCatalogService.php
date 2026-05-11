<?php

declare(strict_types=1);

namespace App\Services\GuidedTours;

class GuidedTourCatalogService
{
    /**
     * @return list<array{
     *     key: string,
     *     version: int,
     *     name: string,
     *     description: string,
     *     start_route: string,
     *     target_roles: list<string>,
     *     is_active: bool,
     *     auto_assign: bool
     * }>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'beginner-dashboard-main-menu',
                'version' => 1,
                'name' => 'Beginner Dashboard Tour',
                'description' => 'Introduces the main dashboard, navigation, and starter workflow entry points for beginner users.',
                'start_route' => 'dashboard',
                'target_roles' => ['beginner'],
                'is_active' => true,
                'auto_assign' => true,
            ],
        ];
    }
}