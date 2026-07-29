<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Size;
use App\Services\SizeFormat\DigitalContentSizeService;
use App\Services\SizeFormat\SizeFormatFormatNormalizerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->string('access_level', 32)->nullable()->index();
        });

        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->foreignId('ftp_format_id')->nullable()->constrained('formats')->nullOnDelete();
            $table->foreignId('ftp_size_id')->nullable()->constrained('sizes')->nullOnDelete();
        });

        Schema::table('landing_page_files', function (Blueprint $table): void {
            $table->foreignId('format_id')->nullable()->constrained('formats')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
        });

        Schema::table('landing_page_links', function (Blueprint $table): void {
            $table->foreignId('format_id')->nullable()->constrained('formats')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
        });

        $physicalObjectTypeId = DB::table('resource_types')
            ->whereRaw('LOWER(slug) = ?', ['physical-object'])
            ->value('id');

        $nonIgsnResources = DB::table('resources');
        if (is_numeric($physicalObjectTypeId)) {
            $nonIgsnResources->where(function ($query) use ($physicalObjectTypeId): void {
                $query->whereNull('resource_type_id')
                    ->orWhere('resource_type_id', '!=', (int) $physicalObjectTypeId);
            });
        }
        $nonIgsnResources->update(['access_level' => AccessLevel::OPEN->value]);

        $metadataOnlyResourceIds = DB::table('landing_pages')
            ->where('downloads_unavailable', true)
            ->pluck('resource_id');

        if ($metadataOnlyResourceIds->isNotEmpty()) {
            $query = DB::table('resources')->whereIn('id', $metadataOnlyResourceIds);
            if (is_numeric($physicalObjectTypeId)) {
                $query->where(function ($resourceQuery) use ($physicalObjectTypeId): void {
                    $resourceQuery->whereNull('resource_type_id')
                        ->orWhere('resource_type_id', '!=', (int) $physicalObjectTypeId);
                });
            }
            $query->update(['access_level' => AccessLevel::METADATA_ONLY->value]);
        }

        if (is_numeric($physicalObjectTypeId)) {
            DB::table('igsn_metadata')
                ->join('resources', 'resources.id', '=', 'igsn_metadata.resource_id')
                ->where('resources.resource_type_id', (int) $physicalObjectTypeId)
                ->select(['resources.id', 'igsn_metadata.sample_access'])
                ->orderBy('resources.id')
                ->chunkById(250, function ($rows): void {
                    foreach ($rows as $row) {
                        $level = AccessLevel::fromSampleAccess(
                            is_string($row->sample_access) ? $row->sample_access : null,
                        );

                        if ($level !== null) {
                            DB::table('resources')
                                ->where('id', (int) $row->id)
                                ->update(['access_level' => $level->value]);
                        }
                    }
                }, 'resources.id', 'id');
        }

        $this->backfillContentDescriptors(
            is_numeric($physicalObjectTypeId) ? (int) $physicalObjectTypeId : null,
        );
    }

    private function backfillContentDescriptors(?int $physicalObjectTypeId): void
    {
        DB::table('landing_pages')
            ->select(['id', 'resource_id', 'ftp_url'])
            ->orderBy('id')
            ->chunkById(100, function ($landingPages) use ($physicalObjectTypeId): void {
                foreach ($landingPages as $landingPage) {
                    $fileIds = DB::table('landing_page_files')
                        ->where('landing_page_id', (int) $landingPage->id)
                        ->orderBy('position')
                        ->pluck('id');
                    $downloadLinkIds = DB::table('landing_page_links')
                        ->where('landing_page_id', (int) $landingPage->id)
                        ->where('kind', 'download')
                        ->orderBy('position')
                        ->pluck('id');

                    $targets = [];
                    if ($fileIds->isNotEmpty()) {
                        foreach ($fileIds as $fileId) {
                            $targets[] = ['table' => 'landing_page_files', 'id' => (int) $fileId];
                        }
                    } elseif (is_string($landingPage->ftp_url) && trim($landingPage->ftp_url) !== '') {
                        $targets[] = ['table' => 'landing_pages', 'id' => (int) $landingPage->id];
                    }

                    foreach ($downloadLinkIds as $linkId) {
                        $targets[] = ['table' => 'landing_page_links', 'id' => (int) $linkId];
                    }

                    $formatIds = DB::table('formats')
                        ->where('resource_id', (int) $landingPage->resource_id)
                        ->orderBy('id')
                        ->get(['id', 'value'])
                        ->filter(static function ($format): bool {
                            $mimeType = SizeFormatFormatNormalizerService::normalize((string) $format->value);

                            return preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/i', $mimeType) === 1;
                        })
                        ->pluck('id');

                    if ($formatIds->count() === 1) {
                        foreach ($targets as $target) {
                            $column = $target['table'] === 'landing_pages' ? 'ftp_format_id' : 'format_id';
                            DB::table($target['table'])
                                ->where('id', $target['id'])
                                ->whereNull($column)
                                ->update([$column => (int) $formatIds->first()]);
                        }
                    }

                    $resourceTypeId = DB::table('resources')
                        ->where('id', (int) $landingPage->resource_id)
                        ->value('resource_type_id');

                    if ($physicalObjectTypeId !== null && (int) $resourceTypeId === $physicalObjectTypeId) {
                        continue;
                    }

                    $eligibleSizes = Size::query()
                        ->where('resource_id', (int) $landingPage->resource_id)
                        ->orderBy('id')
                        ->get()
                        ->filter(static fn (Size $size): bool => app(DigitalContentSizeService::class)->toBytes($size) !== null);

                    if ($targets !== [] && count($targets) === 1 && $eligibleSizes->count() === 1) {
                        $target = $targets[0];
                        /** @var Size $size */
                        $size = $eligibleSizes->first();
                        $column = $target['table'] === 'landing_pages' ? 'ftp_size_id' : 'size_id';
                        DB::table($target['table'])
                            ->where('id', $target['id'])
                            ->whereNull($column)
                            ->update([$column => $size->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('landing_page_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('size_id');
            $table->dropConstrainedForeignId('format_id');
        });

        Schema::table('landing_page_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('size_id');
            $table->dropConstrainedForeignId('format_id');
        });

        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ftp_size_id');
            $table->dropConstrainedForeignId('ftp_format_id');
        });

        Schema::table('resources', function (Blueprint $table): void {
            $table->dropIndex(['access_level']);
            $table->dropColumn('access_level');
        });
    }
};
