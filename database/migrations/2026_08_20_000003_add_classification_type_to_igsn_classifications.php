<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igsn_classifications', function (Blueprint $table): void {
            $table->string('classification_type', 20)->nullable()->after('value');
            $table->index('classification_type');
        });
    }

    public function down(): void
    {
        Schema::table('igsn_classifications', function (Blueprint $table): void {
            $table->dropIndex(['classification_type']);
            $table->dropColumn('classification_type');
        });
    }
};
