<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Support\Facades\Schema;

/** Ensures datacenters keep both built-in template assignments as fallbacks. */
final class DatacenterLandingPageTemplateAssignmentObserver
{
    /**
     * Cached to avoid repeated information_schema lookups in import and seeder processes.
     *
     * @var array<string, bool>
     */
    private static array $existingColumns = [];

    public function saving(Datacenter $datacenter): void
    {
        if ($datacenter->landing_page_template_id !== null && $datacenter->igsn_landing_page_template_id !== null) {
            return;
        }

        $hasResourceAssignment = self::assignmentColumnExists('landing_page_template_id');
        $hasIgsnAssignment = self::assignmentColumnExists('igsn_landing_page_template_id');

        if ((! $hasResourceAssignment || $datacenter->landing_page_template_id !== null)
            && (! $hasIgsnAssignment || $datacenter->igsn_landing_page_template_id !== null)) {
            return;
        }

        $templates = LandingPageTemplate::ensureSystemTemplatesExist();

        if ($hasResourceAssignment && $datacenter->landing_page_template_id === null) {
            $datacenter->landing_page_template_id = $templates[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id;
        }

        if ($hasIgsnAssignment && $datacenter->igsn_landing_page_template_id === null) {
            $datacenter->igsn_landing_page_template_id = $templates[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id;
        }
    }

    private static function assignmentColumnExists(string $column): bool
    {
        return self::$existingColumns[$column] ??= Schema::hasColumn('datacenters', $column);
    }
}
