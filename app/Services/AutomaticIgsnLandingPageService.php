<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AutomaticIgsnLandingPageService
{
    /**
     * Create the published internal landing page for a newly imported IGSN.
     *
     * A null template id deliberately preserves automatic datacenter template
     * inheritance, with the built-in IGSN template as the persisted fallback.
     *
     * @return array{landing_page: LandingPage, created: bool}
     */
    public function createPublished(Resource $resource): array
    {
        $resource->loadMissing('titles.titleType');

        try {
            $result = DB::transaction(function () use ($resource): array {
                $existing = LandingPage::query()
                    ->where('resource_id', $resource->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return ['landing_page' => $existing, 'created' => false];
                }

                $landingPage = new LandingPage([
                    'resource_id' => $resource->id,
                    'template' => 'default_gfz_igsn',
                    'landing_page_template_id' => null,
                    'ftp_url' => null,
                    'downloads_unavailable' => true,
                    'is_published' => true,
                    'published_at' => now(),
                ]);
                $landingPage->setRelation('resource', $resource);
                $landingPage->save();

                return ['landing_page' => $landingPage, 'created' => true];
            });
        } catch (QueryException $exception) {
            // A concurrent import can win the unique resource_id insert. Treat
            // that as an idempotent success while preserving all other errors.
            $landingPage = LandingPage::query()->where('resource_id', $resource->id)->first();

            if ($landingPage === null) {
                throw $exception;
            }

            $result = ['landing_page' => $landingPage, 'created' => false];
        }

        return $result;
    }
}
