<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairContributorTypes();
        $this->repairGeoLocations();
        $this->migrateContributorTypeAssignments();
        $this->repairLandingPageDomains();
        $this->repairResourceInstruments();
    }

    public function down(): void
    {
        // This migration repairs installations created from an older version of
        // the squashed base schema. Reversing it would collapse multiple
        // contributor types back into one value and would therefore lose data.
    }

    private function repairContributorTypes(): void
    {
        if (! Schema::hasColumn('contributor_types', 'category')) {
            Schema::table('contributor_types', function (Blueprint $table): void {
                $table->string('category', 20)->default('both')->after('slug');
            });
        }

        if (! Schema::hasColumn('contributor_types', 'is_elmo_active')) {
            Schema::table('contributor_types', function (Blueprint $table): void {
                $table->boolean('is_elmo_active')->default(true)->after('is_active');
            });
        }
    }

    private function repairGeoLocations(): void
    {
        if (! Schema::hasColumn('geo_locations', 'geo_type')) {
            Schema::table('geo_locations', function (Blueprint $table): void {
                $table->string('geo_type', 10)->nullable()->after('resource_id');
            });
        }
    }

    private function migrateContributorTypeAssignments(): void
    {
        if (! Schema::hasTable('resource_contributor_contributor_type')) {
            Schema::create('resource_contributor_contributor_type', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('resource_contributor_id');
                $table->unsignedBigInteger('contributor_type_id');
                $table->timestamps();

                $table->foreign('resource_contributor_id', 'rc_ct_contributor_fk')
                    ->references('id')
                    ->on('resource_contributors')
                    ->cascadeOnDelete();
                $table->foreign('contributor_type_id', 'rc_ct_type_fk')
                    ->references('id')
                    ->on('contributor_types')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
                $table->unique(
                    ['resource_contributor_id', 'contributor_type_id'],
                    'rc_ct_unique',
                );
            });
        }

        if (! Schema::hasColumn('resource_contributors', 'contributor_type_id')) {
            return;
        }

        $timestamp = now();
        DB::table('resource_contributors')
            ->whereNotNull('contributor_type_id')
            ->select(['id', 'contributor_type_id'])
            ->orderBy('id')
            ->chunkById(500, function ($contributors) use ($timestamp): void {
                $rows = $contributors->map(static fn (object $contributor): array => [
                    'resource_contributor_id' => $contributor->id,
                    'contributor_type_id' => $contributor->contributor_type_id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                DB::table('resource_contributor_contributor_type')->insertOrIgnore($rows);
            });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('resource_contributors', function (Blueprint $table): void {
                $table->dropForeign(['contributor_type_id']);
            });
        }

        Schema::table('resource_contributors', function (Blueprint $table): void {
            $table->dropColumn('contributor_type_id');
        });
    }

    private function repairLandingPageDomains(): void
    {
        if (! Schema::hasTable('landing_page_domains')) {
            Schema::create('landing_page_domains', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 768)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('landing_pages', 'external_domain_id')) {
            Schema::table('landing_pages', function (Blueprint $table): void {
                $table->foreignId('external_domain_id')
                    ->nullable()
                    ->constrained('landing_page_domains')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('landing_pages', 'external_path')) {
            Schema::table('landing_pages', function (Blueprint $table): void {
                $table->string('external_path', 2048)->nullable();
            });
        }
    }

    private function repairResourceInstruments(): void
    {
        if (Schema::hasTable('resource_instruments')) {
            return;
        }

        Schema::create('resource_instruments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('instrument_pid', 512);
            $table->string('instrument_pid_type', 50)->default('Handle');
            $table->string('instrument_name', 1024);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'position']);
        });
    }
};
