<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['igsn_registration_runs', 'datacite_url_update_runs', 'resource_tombstone_transitions'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json('activity_actor')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['igsn_registration_runs', 'datacite_url_update_runs', 'resource_tombstone_transitions'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('activity_actor'));
        }
    }
};
