<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rename('PID4INST (b2inst)', 'PIDINST (B2INST + DataCite)');
    }

    public function down(): void
    {
        $this->rename('PIDINST (B2INST + DataCite)', 'PID4INST (b2inst)');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('pid_settings')->where('type', 'pid4inst')->where('display_name', $from)->update(['display_name' => $to]);
    }
};
