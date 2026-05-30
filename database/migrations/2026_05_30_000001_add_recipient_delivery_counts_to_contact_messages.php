<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->unsignedInteger('recipient_count')->default(0)->after('ip_address');
            $table->unsignedInteger('delivered_recipient_count')->default(0)->after('recipient_count');
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->dropColumn(['recipient_count', 'delivered_recipient_count']);
        });
    }
};