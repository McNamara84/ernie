<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Both supported engines enforce the same row-local publication rules.
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $options = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        DB::statement("CREATE TABLE news (
            id {$id},
            title VARCHAR(255) NOT NULL,
            body_markdown LONGTEXT NOT NULL,
            kind VARCHAR(20) NOT NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            is_scheduled BOOLEAN NOT NULL DEFAULT 0,
            archived_at DATETIME NULL,
            created_by_user_id {$foreignId} NULL,
            updated_by_user_id {$foreignId} NULL,
            revision INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT news_publication_shape CHECK (
                ((kind = 'regular' AND starts_at IS NULL AND ends_at IS NULL AND is_scheduled = 0)
                OR (kind = 'temporary' AND starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at))
                AND is_scheduled IN (0, 1) AND (archived_at IS NULL OR is_scheduled = 0)
                AND revision > 0
            )
        ){$options}");
        DB::statement('CREATE INDEX news_schedule_lookup ON news (kind, is_scheduled, archived_at, starts_at, ends_at)');
        DB::statement("CREATE TABLE homepage_news_state (
            id INTEGER PRIMARY KEY,
            active_news_id {$foreignId} NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            CONSTRAINT homepage_news_singleton CHECK (id = 1 AND revision > 0),
            FOREIGN KEY (active_news_id) REFERENCES news(id) ON DELETE RESTRICT
        ){$options}");
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_news_state');
        Schema::dropIfExists('news');
    }
};
