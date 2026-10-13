<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'resource_types', 'title_types', 'description_types', 'rights',
        'contributor_types', 'relation_types', 'identifier_types',
        'thesaurus_settings', 'pid_settings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('is_elmo_msl_active')->default(true);
            });
            DB::table($name)->update(['is_elmo_msl_active' => DB::raw('is_elmo_active')]);
        }

        Schema::table('languages', function (Blueprint $table): void {
            $table->boolean('elmo_msl_active')->default(true);
        });
        DB::table('languages')->update(['elmo_msl_active' => DB::raw('elmo_active')]);

        Schema::table('date_types', function (Blueprint $table): void {
            $table->boolean('is_elmo_active')->default(true);
            $table->boolean('is_elmo_msl_active')->default(true);
        });
        DB::table('date_types')->update([
            'is_elmo_active' => DB::raw('is_active'),
            'is_elmo_msl_active' => DB::raw('is_active'),
        ]);
    }

    public function down(): void
    {
        Schema::table('date_types', function (Blueprint $table): void {
            $table->dropColumn(['is_elmo_active', 'is_elmo_msl_active']);
        });
        Schema::table('languages', function (Blueprint $table): void {
            $table->dropColumn('elmo_msl_active');
        });
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('is_elmo_msl_active');
            });
        }
    }
};
