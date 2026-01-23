<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->foreignId('resource_creator_id')
                ->nullable()
                ->constrained('resource_creators')
                ->nullOnDelete();
            $table->boolean('send_to_all')->default(false);
            $table->string('sender_name');
            $table->string('sender_email');
            $table->text('message');
            $table->boolean('copy_to_sender')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'created_at']);
            $table->index(['ip_address', 'created_at']); // For rate-limiting
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
