<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_assessment_refreshes', function (Blueprint $table): void {
            $table->foreignId('resource_id')->primary()->constrained('resources')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('service_attempts')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_assessment_refreshes');
    }
};
