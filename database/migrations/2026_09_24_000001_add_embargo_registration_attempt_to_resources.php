<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->timestamp('embargo_registration_started_at')->nullable();
            $table->string('embargo_registration_prefix', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->dropColumn(['embargo_registration_started_at', 'embargo_registration_prefix']);
        });
    }
};
