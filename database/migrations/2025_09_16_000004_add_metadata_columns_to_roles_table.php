<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('applies_to')->default(Role::APPLIES_TO_CONTRIBUTOR_PERSON);
            $table->boolean('is_active_in_ernie')->default(true);
            $table->boolean('is_active_in_elmo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['applies_to', 'is_active_in_ernie', 'is_active_in_elmo']);
        });
    }
};
