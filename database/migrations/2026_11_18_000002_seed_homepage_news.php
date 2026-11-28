<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::table('homepage_news_state')->where('id', 1)->exists()) {
                return;
            }

            $now = now();
            $id = DB::table('news')->insertGetId([
                'title' => 'Welcome ELMO - our new Metadata Editor!',
                'body_markdown' => 'In November 2025, the GFZ Data Services team proudly launched the new, fully revised and modernised version of our [new metadata editor ELMO](https://dataservices.gfz.de/elmo). ELMO is not only a new web interface, but also contains many revised functionalities that increase the quality of metadata and the FAIRness of the data describing it, while at the same time simplifying the entry of information for researchers.',
                'kind' => 'regular',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('homepage_news_state')->insert([
                'id' => 1, 'active_news_id' => $id, 'revision' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Keep editorial data on rollback. The preceding schema migration owns its removal.
    }
};
