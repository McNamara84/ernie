<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Size data is now exclusively stored in the `sizes` table (DataCite property #13).
     * The redundant `size` and `size_unit` columns in `igsn_metadata` are removed
     * to follow the DRY principle and support multiple size specifications per resource.
     *
     * @see https://github.com/McNamara84/ernie/issues/488
     */
    public function up(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->dropColumn(['size', 'size_unit']);
        });
    }
};
