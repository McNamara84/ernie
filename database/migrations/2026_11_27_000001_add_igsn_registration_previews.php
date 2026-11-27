<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igsn_metadata', fn (Blueprint $table) => $table->timestamp('simulated_registered_at')->nullable());
        Schema::table('igsn_registration_runs', function (Blueprint $table): void {
            $table->boolean('is_simulation')->default(false);
            $table->timestamp('preview_confirmed_at')->nullable();
        });
        Schema::table('igsn_registration_items', function (Blueprint $table): void {
            $table->json('preview_payload')->nullable();
            $table->timestamp('preview_confirmed_at')->nullable();
        });

        // Earlier releases used the same badge for both DataCite environments.
        $unverified = DB::table('igsn_metadata')->where('upload_status', 'registered')
            ->whereNotIn('resource_id', $this->successfulRegistrations(false));
        (clone $unverified)->whereIn('resource_id', $this->successfulRegistrations(true))
            ->update(['simulated_registered_at' => now()]);
        $unverified->update(['upload_status' => 'unverified']);
    }

    private function successfulRegistrations(bool $testMode): Builder
    {
        $publications = DB::table('resource_doi_publications as p')
            ->join('resources as r', 'r.id', '=', 'p.resource_id')
            ->whereColumn('r.doi', 'p.doi')->where('p.status', 'succeeded')
            ->where('p.test_mode', $testMode)->select('p.resource_id');

        return $publications->union(DB::table('igsn_registration_items as i')
            ->join('igsn_registration_runs as runs', 'runs.id', '=', 'i.run_id')
            ->join('resources as r', 'r.id', '=', 'i.resource_id')
            ->whereColumn('r.doi', 'i.identifier')->where('runs.test_mode', $testMode)
            ->whereIn('i.status', ['registered', 'updated'])->select('i.resource_id'));
    }

    public function down(): void
    {
        DB::table('igsn_metadata')->where('upload_status', 'unverified')->update(['upload_status' => 'registered']);
        Schema::table('igsn_registration_items', fn (Blueprint $table) => $table->dropColumn(['preview_payload', 'preview_confirmed_at']));
        Schema::table('igsn_registration_runs', fn (Blueprint $table) => $table->dropColumn(['is_simulation', 'preview_confirmed_at']));
        Schema::table('igsn_metadata', fn (Blueprint $table) => $table->dropColumn('simulated_registered_at'));
    }
};
