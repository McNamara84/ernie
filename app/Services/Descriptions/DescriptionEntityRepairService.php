<?php

declare(strict_types=1);

namespace App\Services\Descriptions;

use App\Models\Description;
use App\Support\DescriptionTextNormalizer;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DescriptionEntityRepairService
{
    public function __construct(private readonly DescriptionTextNormalizer $normalizer) {}

    /**
     * @param  list<string>  $dois
     * @return array{
     *     scanned: int,
     *     changed: int,
     *     unchanged: int,
     *     skipped: int,
     *     errors: int,
     *     records: list<array{
     *         description_id: int,
     *         resource_id: int,
     *         doi: string,
     *         status: string,
     *         replacements: int,
     *         message: string
     *     }>
     * }
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        array $dois = [],
    ): array {
        $result = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'errors' => 0,
            'records' => [],
        ];

        $query = Description::query()
            ->with('resource:id,doi')
            ->where('id', '>', max(0, $afterId));

        $normalizedDois = $this->normalizeDois($dois);
        if ($normalizedDois !== []) {
            $query->whereHas(
                'resource',
                static fn ($resourceQuery) => $resourceQuery->whereIn(DB::raw('LOWER(doi)'), $normalizedDois),
            );
        }

        foreach ($query->lazyById(250) as $description) {
            if ($limit > 0 && $result['scanned'] >= $limit) {
                break;
            }

            $result['scanned']++;
            $resourceId = (int) $description->resource_id;
            $doi = (string) ($description->resource->doi ?? '');

            try {
                $original = (string) $description->value;
                $normalized = $this->normalizer->normalize($original);

                if ($normalized['replacements'] === 0) {
                    $result['unchanged']++;
                    $result['records'][] = $this->record(
                        $description->id,
                        $resourceId,
                        $doi,
                        'unchanged',
                        0,
                        'No encoded angle brackets found.',
                    );

                    continue;
                }

                if (! $apply) {
                    $result['changed']++;
                    $result['records'][] = $this->record(
                        $description->id,
                        $resourceId,
                        $doi,
                        'would_update',
                        $normalized['replacements'],
                        'Dry run; no data was changed.',
                    );

                    continue;
                }

                $updated = Description::query()
                    ->whereKey($description->id)
                    ->where('value', $original)
                    ->update(['value' => $normalized['value']]);

                if ($updated === 0) {
                    $result['skipped']++;
                    $result['records'][] = $this->record(
                        $description->id,
                        $resourceId,
                        $doi,
                        'skipped',
                        0,
                        'Description changed concurrently and was not overwritten.',
                    );

                    continue;
                }

                $result['changed']++;
                $result['records'][] = $this->record(
                    $description->id,
                    $resourceId,
                    $doi,
                    'updated',
                    $normalized['replacements'],
                    'Encoded angle brackets normalized.',
                );
            } catch (Throwable $exception) {
                report($exception);
                $result['errors']++;
                $result['records'][] = $this->record(
                    $description->id,
                    $resourceId,
                    $doi,
                    'error',
                    0,
                    $exception->getMessage(),
                );
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $dois
     * @return list<string>
     */
    private function normalizeDois(array $dois): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (string $doi): string {
                $normalized = trim($doi);
                $normalized = preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', $normalized) ?? $normalized;
                $normalized = preg_replace('/^doi:\s*/i', '', $normalized) ?? $normalized;

                return strtolower(trim($normalized));
            },
            $dois,
        ))));
    }

    /**
     * @return array{description_id: int, resource_id: int, doi: string, status: string, replacements: int, message: string}
     */
    private function record(
        int $descriptionId,
        int $resourceId,
        string $doi,
        string $status,
        int $replacements,
        string $message,
    ): array {
        return [
            'description_id' => $descriptionId,
            'resource_id' => $resourceId,
            'doi' => $doi,
            'status' => $status,
            'replacements' => $replacements,
            'message' => $message,
        ];
    }
}
