<?php

declare(strict_types=1);

use App\Services\SubjectBreadcrumbPathResolverService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subjects', 'breadcrumb_path')) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->text('breadcrumb_path')->nullable()->after('classification_code');
            });
        }

        $this->backfillBreadcrumbPaths();
    }

    public function down(): void
    {
        if (Schema::hasColumn('subjects', 'breadcrumb_path')) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->dropColumn('breadcrumb_path');
            });
        }
    }

    private function backfillBreadcrumbPaths(): void
    {
        $resolver = new SubjectBreadcrumbPathResolverService;

        DB::table('subjects')
            ->select(['id', 'value', 'subject_scheme', 'value_uri', 'classification_code', 'breadcrumb_path'])
            ->whereNotNull('subject_scheme')
            ->where('subject_scheme', '!=', '')
            ->where(function ($query): void {
                $query->whereNull('breadcrumb_path')
                    ->orWhere('breadcrumb_path', '');
            })
            ->orderBy('id')
            ->chunkById(100, function ($subjects) use ($resolver): void {
                foreach ($subjects as $subject) {
                    $breadcrumbPath = $resolver->resolve(
                        subjectScheme: is_string($subject->subject_scheme ?? null) ? $subject->subject_scheme : null,
                        valueUri: is_string($subject->value_uri ?? null) ? $subject->value_uri : null,
                        classificationCode: is_string($subject->classification_code ?? null) ? $subject->classification_code : null,
                        subjectValue: is_string($subject->value ?? null) ? $subject->value : null,
                    );

                    if ($breadcrumbPath === null) {
                        continue;
                    }

                    DB::table('subjects')
                        ->where('id', $subject->id)
                        ->update(['breadcrumb_path' => $breadcrumbPath]);
                }
            });
    }
};