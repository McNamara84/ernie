<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the contact_messages table for logging contact form submissions
     * from landing page visitors to dataset contact persons.
     */
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('sender_name');
            $table->string('sender_email');
            $table->json('recipient_contributor_ids'); // Array of ResourceContributor IDs
            $table->text('message');
            $table->string('ip_address', 45)->nullable(); // IPv6 support
            $table->boolean('honeypot_triggered')->default(false);
            $table->boolean('send_copy_to_sender')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'created_at']);
            $table->index(['ip_address', 'created_at']); // For rate limiting
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
