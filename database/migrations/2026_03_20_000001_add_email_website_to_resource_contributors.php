<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add email and website columns to resource_contributors table.
     *
     * These fields store contact information for contributors with the
     * "Contact Person" role. Not exported to DataCite (schema limitation).
     */
    public function up(): void
    {
        Schema::table('resource_contributors', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('position');
            $table->string('website')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('resource_contributors', function (Blueprint $table): void {
            $table->dropColumn(['email', 'website']);
        });
    }
};
