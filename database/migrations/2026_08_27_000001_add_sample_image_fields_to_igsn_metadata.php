<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->string('sample_image_source_url', 2048)->nullable()->after('description_json');
            $table->string('sample_image_external_url', 2048)->nullable()->after('sample_image_source_url');
            $table->string('sample_image_storage_path', 2048)->nullable()->after('sample_image_external_url');
            $table->string('sample_image_mime_type', 100)->nullable()->after('sample_image_storage_path');
            $table->unsignedBigInteger('sample_image_size')->nullable()->after('sample_image_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->dropColumn([
                'sample_image_source_url',
                'sample_image_external_url',
                'sample_image_storage_path',
                'sample_image_mime_type',
                'sample_image_size',
            ]);
        });
    }
};
