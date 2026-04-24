<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DataCite 4.7 Related Items — carry their own inline metadata
        // (title, creators, publisher, volume, issue, pages, …) and are
        // distinct from `related_identifiers`, which only reference external
        // resources by identifier.
        Schema::create('related_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // resourceTypeGeneral reused from resource_types.slug (stored as
            // string to allow evolution without hard FK coupling)
            $table->string('related_item_type', 64);

            $table->foreignId('relation_type_id')
                ->constrained('relation_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->smallInteger('publication_year')->nullable();
            $table->string('volume', 64)->nullable();
            $table->string('issue', 64)->nullable();
            $table->string('number', 64)->nullable();
            $table->string('number_type', 32)->nullable(); // Article|Chapter|Report|Other
            $table->string('first_page', 32)->nullable();
            $table->string('last_page', 32)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->string('edition', 64)->nullable();

            // Optional inline identifier (relatedItemIdentifier / Type)
            $table->string('identifier', 2183)->nullable();
            $table->string('identifier_type', 32)->nullable();
            $table->string('related_metadata_scheme')->nullable();
            $table->string('scheme_uri', 512)->nullable();
            $table->string('scheme_type', 100)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'position']);
            $table->index('relation_type_id');
        });

        Schema::create('related_item_titles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('related_item_id')
                ->constrained('related_items')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('title', 512);
            // MainTitle | Subtitle | TranslatedTitle | AlternativeTitle
            $table->string('title_type', 32);
            $table->string('language', 8)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['related_item_id', 'position']);
        });

        Schema::create('related_item_creators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('related_item_id')
                ->constrained('related_items')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            // Personal | Organizational
            $table->string('name_type', 16)->default('Personal');
            $table->string('name', 255);
            $table->string('given_name', 255)->nullable();
            $table->string('family_name', 255)->nullable();
            $table->string('name_identifier', 255)->nullable();
            // ORCID | ROR | ISNI | …
            $table->string('name_identifier_scheme', 32)->nullable();
            $table->string('scheme_uri', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['related_item_id', 'position']);
        });

        Schema::create('related_item_creator_affiliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('related_item_creator_id');
            $table->foreign('related_item_creator_id', 'ri_creator_aff_fk')
                ->references('id')->on('related_item_creators')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('name', 255);
            $table->string('affiliation_identifier', 255)->nullable();
            $table->string('scheme', 32)->nullable();
            $table->string('scheme_uri', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['related_item_creator_id', 'position'], 'ri_creator_aff_idx');
        });

        Schema::create('related_item_contributors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('related_item_id')
                ->constrained('related_items')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('contributor_type', 64);
            $table->string('name_type', 16)->default('Personal');
            $table->string('name', 255);
            $table->string('given_name', 255)->nullable();
            $table->string('family_name', 255)->nullable();
            $table->string('name_identifier', 255)->nullable();
            $table->string('name_identifier_scheme', 32)->nullable();
            $table->string('scheme_uri', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['related_item_id', 'position']);
        });

        Schema::create('related_item_contributor_affiliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('related_item_contributor_id');
            $table->foreign('related_item_contributor_id', 'ri_contrib_aff_fk')
                ->references('id')->on('related_item_contributors')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('name', 255);
            $table->string('affiliation_identifier', 255)->nullable();
            $table->string('scheme', 32)->nullable();
            $table->string('scheme_uri', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['related_item_contributor_id', 'position'], 'ri_contrib_aff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('related_item_contributor_affiliations');
        Schema::dropIfExists('related_item_contributors');
        Schema::dropIfExists('related_item_creator_affiliations');
        Schema::dropIfExists('related_item_creators');
        Schema::dropIfExists('related_item_titles');
        Schema::dropIfExists('related_items');
    }
};
