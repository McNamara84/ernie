<?php

declare(strict_types=1);

use App\Services\Igsn\IgsnVocabularyNormalizerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $normalizer = new IgsnVocabularyNormalizerService;

        DB::table('igsn_metadata')
            ->select(['id', 'resource_id', 'material'])
            ->orderBy('id')
            ->chunkById(250, function ($metadataRows) use ($normalizer): void {
                $resourceIds = [];
                foreach ($metadataRows as $metadata) {
                    $resourceIds[] = (int) $metadata->resource_id;
                }

                DB::transaction(function () use ($metadataRows, $normalizer, $resourceIds): void {
                    /** @var array<int, list<stdClass>> $classificationsByResource */
                    $classificationsByResource = [];

                    $classifications = DB::table('igsn_classifications')
                        ->whereIn('resource_id', array_values(array_unique($resourceIds)))
                        ->orderBy('resource_id')
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get(['id', 'resource_id', 'value', 'classification_type', 'position']);

                    foreach ($classifications as $classification) {
                        $classificationsByResource[(int) $classification->resource_id][] = $classification;
                    }

                    foreach ($metadataRows as $metadata) {
                        $this->backfillResource(
                            $normalizer,
                            $metadata,
                            $classificationsByResource[(int) $metadata->resource_id] ?? [],
                        );
                    }
                });
            });
    }

    /**
     * @param  list<stdClass>  $classifications
     */
    private function backfillResource(
        IgsnVocabularyNormalizerService $normalizer,
        stdClass $metadata,
        array $classifications,
    ): void {
        $rawMaterial = is_string($metadata->material ?? null) ? $metadata->material : null;

        try {
            $material = $normalizer->normalizeMaterial($rawMaterial);
        } catch (InvalidArgumentException) {
            Log::warning('Skipping unsupported IGSN material during vocabulary backfill.', [
                'resource_id' => (int) $metadata->resource_id,
                'material' => $rawMaterial,
            ]);

            return;
        }

        if ($rawMaterial !== $material) {
            DB::table('igsn_metadata')
                ->where('id', (int) $metadata->id)
                ->update([
                    'material' => $material,
                    'updated_at' => now(),
                ]);
        }

        $classificationType = $normalizer->classificationType($material)?->value;
        $seen = [];
        $position = 0;

        foreach ($classifications as $classification) {
            $rawValue = is_string($classification->value ?? null) ? $classification->value : '';
            $partitioned = $normalizer->partitionClassifications($material, [$rawValue]);

            if ($partitioned['rejected'] !== []) {
                $this->deleteClassification($classification, 'unsupported');

                continue;
            }

            $canonical = $partitioned['values'][0] ?? null;
            if ($canonical === null) {
                $this->deleteClassification($classification, 'empty');

                continue;
            }

            $key = mb_strtolower($canonical);
            if (isset($seen[$key])) {
                $this->deleteClassification($classification, 'duplicate');

                continue;
            }

            $seen[$key] = true;
            $currentType = is_string($classification->classification_type ?? null)
                ? $classification->classification_type
                : null;

            if ($rawValue !== $canonical
                || $currentType !== $classificationType
                || (int) $classification->position !== $position) {
                DB::table('igsn_classifications')
                    ->where('id', (int) $classification->id)
                    ->update([
                        'value' => $canonical,
                        'classification_type' => $classificationType,
                        'position' => $position,
                        'updated_at' => now(),
                    ]);
            }

            $position++;
        }
    }

    private function deleteClassification(stdClass $classification, string $reason): void
    {
        Log::warning('Removing invalid IGSN classification during vocabulary backfill.', [
            'resource_id' => (int) $classification->resource_id,
            'classification' => is_string($classification->value ?? null) ? $classification->value : null,
            'reason' => $reason,
        ]);

        DB::table('igsn_classifications')
            ->where('id', (int) $classification->id)
            ->delete();
    }

    public function down(): void
    {
        // Forward-only cleanup: discarded invalid and duplicate values cannot
        // be reconstructed safely during rollback.
    }
};
