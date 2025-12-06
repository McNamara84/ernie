<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('spdx_id')->nullable()->after('identifier')->comment('SPDX License Identifier (rightsIdentifier)');
            $table->string('reference')->nullable()->after('spdx_id')->comment('URL to license text (rightsURI)');
            $table->string('details_url')->nullable()->after('reference')->comment('URL to SPDX license details');
            $table->boolean('is_deprecated_license_id')->default(false)->after('details_url');
            $table->boolean('is_osi_approved')->default(false)->after('is_deprecated_license_id');
            $table->boolean('is_fsf_libre')->default(false)->after('is_osi_approved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn([
                'spdx_id',
                'reference',
                'details_url',
                'is_deprecated_license_id',
                'is_osi_approved',
                'is_fsf_libre',
            ]);
        });
    }
};
