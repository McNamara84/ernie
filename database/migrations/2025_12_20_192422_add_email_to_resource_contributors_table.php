<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds email column for ContactPerson contributors.
     * This allows contact persons to receive messages from landing page visitors.
     */
    public function up(): void
    {
        Schema::table('resource_contributors', function (Blueprint $table) {
            $table->string('email')->nullable()->after('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_contributors', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
