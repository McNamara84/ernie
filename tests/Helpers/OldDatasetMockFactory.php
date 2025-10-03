<?php

namespace Tests\Helpers;

/**
 * Factory class for creating mock OldDataset objects for testing.
 * 
 * This class provides a reusable way to create mock dataset objects
 * that behave like real OldDataset models without requiring database access.
 */
class OldDatasetMockFactory
{
    /**
     * Create a mock OldDataset object with the given attributes.
     *
     * @param array<string, mixed> $attributes
     * @return object
     */
    public static function make(array $attributes = []): object
    {
        return new class($attributes) implements \JsonSerializable {
            public int $id;
            public string $identifier;
            public string $resourcetypegeneral;
            public string $curator;
            public string $title;
            public string $created_at;
            public string $updated_at;
            public string $publicstatus;
            public string $publisher;
            public int $publicationyear;
            public ?array $licenses = null;

            public function __construct(array $attributes)
            {
                $defaults = [
                    'id' => 1,
                    'identifier' => '10.1234/example',
                    'resourcetypegeneral' => 'Dataset',
                    'curator' => 'Test Curator',
                    'title' => 'Test Dataset',
                    'created_at' => '2024-01-01 10:00:00',
                    'updated_at' => '2024-01-01 10:00:00',
                    'publicstatus' => 'published',
                    'publisher' => 'Test Publisher',
                    'publicationyear' => 2024,
                ];

                $merged = array_merge($defaults, $attributes);

                foreach ($merged as $key => $value) {
                    $this->$key = $value;
                }
            }

            public function getLicenses(): array
            {
                return $this->licenses ?? [];
            }

            public function jsonSerialize(): array
            {
                return [
                    'id' => $this->id,
                    'identifier' => $this->identifier,
                    'resourcetypegeneral' => $this->resourcetypegeneral,
                    'curator' => $this->curator,
                    'title' => $this->title,
                    'created_at' => $this->created_at,
                    'updated_at' => $this->updated_at,
                    'publicstatus' => $this->publicstatus,
                    'publisher' => $this->publisher,
                    'publicationyear' => $this->publicationyear,
                    'licenses' => $this->licenses ?? [],
                ];
            }
        };
    }

    /**
     * Create multiple mock OldDataset objects.
     *
     * @param int $count
     * @param array<string, mixed> $attributes
     * @return array<object>
     */
    public static function makeMany(int $count, array $attributes = []): array
    {
        $datasets = [];
        for ($i = 0; $i < $count; $i++) {
            $datasets[] = self::make(array_merge($attributes, [
                'id' => ($attributes['id'] ?? 1) + $i,
            ]));
        }
        return $datasets;
    }
}
