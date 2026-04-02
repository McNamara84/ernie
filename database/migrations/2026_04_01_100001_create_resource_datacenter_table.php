<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_datacenter', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('datacenter_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['resource_id', 'datacenter_id']);
            $table->index('datacenter_id');
            $table->index('resource_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_datacenter');
    }
};
