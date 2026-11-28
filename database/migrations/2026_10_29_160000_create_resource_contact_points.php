<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_contact_points', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('identity_key', 64);
            $table->char('variant_key', 64);
            $table->string('orcid', 19)->nullable();
            $table->string('display_name', 1000);
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('name_sort', 1000);
            $table->unique(['resource_id', 'variant_key'], 'contact_resource_variant_unique');
            $table->index(['variant_key', 'resource_id'], 'contact_variant_resource_index');
            $table->index(['identity_key', 'variant_key'], 'contact_identity_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_contact_points');
    }
};
