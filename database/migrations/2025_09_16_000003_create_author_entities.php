<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->string('orcid')->nullable()->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->timestamps();
        });

        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('ror_id')->nullable();
            $table->timestamps();
            $table->unique(['name', 'ror_id']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('resource_authors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->morphs('authorable');
            $table->unsignedInteger('position')->default(0);
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->timestamps();
            $table->index(['resource_id', 'position']);
        });

        Schema::create('author_role', function (Blueprint $table): void {
            $table->foreignId('resource_author_id')->constrained('resource_authors')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['resource_author_id', 'role_id']);
        });

        Schema::create('affiliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_author_id')->constrained('resource_authors')->cascadeOnDelete();
            $table->string('value');
            $table->string('ror_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliations');
        Schema::dropIfExists('author_role');
        Schema::dropIfExists('resource_authors');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('persons');
    }
};
