<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changeDeleteAction('cascade');
    }

    public function down(): void
    {
        $this->changeDeleteAction('restrict');
    }

    private function changeDeleteAction(string $action): void
    {
        foreach (['usage_events', 'usage_daily_metrics', 'usage_monthly_metrics'] as $name) {
            $foreignKey = collect(Schema::getForeignKeys($name))->firstWhere('columns', ['landing_page_id']);
            if (($foreignKey['on_delete'] ?? null) === $action) {
                continue;
            }

            // Repair already-deployed tables without removing their events or aggregates.
            Schema::table($name, function (Blueprint $table) use ($action): void {
                $table->dropForeign(['landing_page_id']);
                $table->foreign('landing_page_id')->references('id')->on('landing_pages')->onDelete($action);
            });
        }
    }
};
