<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{old: string, new: string}> */
    private const TEMPLATE_NAMES = [
        'default_gfz' => [
            'old' => 'Default GFZ Data Services',
            'new' => 'Templates Resources',
        ],
        'default_gfz_igsn' => [
            'old' => 'Default GFZ IGSN',
            'new' => 'Templates IGSN',
        ],
    ];

    public function up(): void
    {
        $this->renameTemplates('new');
    }

    public function down(): void
    {
        $this->renameTemplates('old');
    }

    private function renameTemplates(string $target): void
    {
        DB::transaction(function () use ($target): void {
            foreach (self::TEMPLATE_NAMES as $slug => $names) {
                $conflictingSlug = DB::table('landing_page_templates')
                    ->where('name', $names[$target])
                    ->where('slug', '!=', $slug)
                    ->value('slug');

                if ($conflictingSlug !== null) {
                    throw new RuntimeException(sprintf(
                        'Cannot rename landing page copy template "%s" to "%s": template "%s" already uses that name.',
                        $slug,
                        $names[$target],
                        $conflictingSlug,
                    ));
                }
            }

            foreach (self::TEMPLATE_NAMES as $slug => $names) {
                DB::table('landing_page_templates')
                    ->where('slug', $slug)
                    ->update(['name' => $names[$target]]);
            }
        });
    }
};
