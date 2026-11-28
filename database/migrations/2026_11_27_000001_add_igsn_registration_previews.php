<?php

declare(strict_types=1);

use App\Support\IgsnIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
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
        DB::transaction(function (): void {
            DB::table('igsn_metadata as m')->join('resources as r', 'r.id', '=', 'm.resource_id')
                ->whereIn('m.upload_status', ['registered', 'unverified'])
                ->select('m.resource_id', 'r.doi')->lockForUpdate()
                ->chunkById(500, $this->backfillRegistrations(...), 'm.resource_id', 'resource_id');
        });
    }

    /** @param Collection<int, stdClass> $samples */
    private function backfillRegistrations(Collection $samples): void
    {
        $dois = $samples->pluck('doi', 'resource_id')
            ->map(fn (?string $doi): string => IgsnIdentifier::forRegistration((string) $doi));
        $resourceIds = $dois->keys();
        $items = DB::table('igsn_registration_items as i')
            ->join('igsn_registration_runs as runs', 'runs.id', '=', 'i.run_id')
            ->whereIntegerInRaw('i.resource_id', $resourceIds)->whereIn('i.status', ['registered', 'updated'])
            ->select('i.resource_id', 'i.identifier', 'runs.test_mode');
        $matches = DB::table('resource_doi_publications')
            ->whereIntegerInRaw('resource_id', $resourceIds)->where('status', 'succeeded')
            ->select('resource_id', 'doi as identifier', 'test_mode')->unionAll($items)->get()
            ->filter(function (stdClass $record) use ($dois): bool {
                $doi = $dois->get($record->resource_id, '');

                return $doi !== '' && strcasecmp($doi, IgsnIdentifier::forRegistration((string) $record->identifier)) === 0;
            });

        $withoutLiveRegistration = DB::table('igsn_metadata')->whereIntegerInRaw(
            'resource_id', $resourceIds->diff($matches->where('test_mode', false)->pluck('resource_id')),
        );
        // Reapplying the migration can restore sandbox evidence for a sample already marked unverified.
        (clone $withoutLiveRegistration)->whereIntegerInRaw('resource_id', $matches->where('test_mode', true)->pluck('resource_id'))
            ->update(['simulated_registered_at' => now()]);
        $withoutLiveRegistration->where('upload_status', 'registered')->update(['upload_status' => 'unverified']);
    }

    public function down(): void
    {
        // Unverified samples can also originate after the backfill. Keep their registration status on rollback.
        Schema::table('igsn_registration_items', fn (Blueprint $table) => $table->dropColumn(['preview_payload', 'preview_confirmed_at']));
        Schema::table('igsn_registration_runs', fn (Blueprint $table) => $table->dropColumn(['is_simulation', 'preview_confirmed_at']));
        Schema::table('igsn_metadata', fn (Blueprint $table) => $table->dropColumn('simulated_registered_at'));
    }
};
