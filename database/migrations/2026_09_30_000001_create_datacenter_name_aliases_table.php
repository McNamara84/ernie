<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datacenter_name_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('datacenter_id')->constrained('datacenters')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->index('datacenter_id');
        });

        DB::table('datacenters')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(500, function ($datacenters): void {
                $aliases = [];
                foreach ($datacenters as $datacenter) {
                    $name = trim((string) $datacenter->name);
                    $aliases[] = [
                        'datacenter_id' => (int) $datacenter->id,
                        'name' => $name,
                        'name_key' => mb_strtolower($name, 'UTF-8'),
                    ];
                }

                if ($aliases !== []) {
                    DB::table('datacenter_name_aliases')->insert($aliases);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('datacenter_name_aliases');
    }
};
