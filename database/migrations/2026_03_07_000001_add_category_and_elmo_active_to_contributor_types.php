<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add category and is_elmo_active columns to contributor_types table.
     *
     * Category determines whether a contributor type applies to persons,
     * institutions, or both. is_elmo_active controls ELMO API availability.
     */
    public function up(): void
    {
        Schema::table('contributor_types', function (Blueprint $table): void {
            $table->string('category', 20)->default('both')->after('slug');
            $table->boolean('is_elmo_active')->default(true)->after('is_active');
        });

        // Set categories for existing contributor types based on DataCite conventions
        $personSlugs = [
            'ContactPerson', 'DataCollector', 'DataCurator', 'DataManager',
            'Editor', 'Producer', 'ProjectLeader', 'ProjectManager',
            'ProjectMember', 'RelatedPerson', 'Researcher', 'Supervisor',
            'Translator', 'WorkPackageLeader',
        ];

        $institutionSlugs = [
            'HostingInstitution', 'RegistrationAgency',
            'RegistrationAuthority', 'ResearchGroup',
        ];

        // 'both' is the default, so no update needed for: Distributor, RightsHolder, Sponsor, Other

        DB::table('contributor_types')
            ->whereIn('slug', $personSlugs)
            ->update(['category' => 'person']);

        DB::table('contributor_types')
            ->whereIn('slug', $institutionSlugs)
            ->update(['category' => 'institution']);
    }
};
