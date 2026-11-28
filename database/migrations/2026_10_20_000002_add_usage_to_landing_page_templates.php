<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('landing_page_templates')->orderBy('id')->eachById(function (object $template): void {
            $left = json_decode($template->left_column_order ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $right = json_decode($template->right_column_order ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $hidden = json_decode($template->hidden_sections ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            if (! in_array('usage', [...$left, ...$right, ...$hidden], true)) {
                $right[] = 'usage';
                DB::table('landing_page_templates')->where('id', $template->id)
                    ->update(['right_column_order' => json_encode($right, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('landing_page_templates')->orderBy('id')->eachById(function (object $template): void {
            $updates = [];
            foreach (['left_column_order', 'right_column_order', 'hidden_sections'] as $column) {
                $sections = json_decode($template->{$column} ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $updates[$column] = json_encode(array_values(array_diff($sections, ['usage'])), JSON_THROW_ON_ERROR);
            }
            DB::table('landing_page_templates')->where('id', $template->id)->update($updates);
        });
    }
};
