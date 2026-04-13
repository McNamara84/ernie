<?php

declare(strict_types=1);

namespace App\Services\Assistance;

/**
 * Value object representing a parsed assistant manifest.json file.
 *
 * Each assistant module has a manifest.json that defines its configuration:
 * identity, display settings, route prefix, cache keys, status messages, etc.
 *
 * This class validates required fields and provides typed access to all values.
 */
readonly class AssistantManifest
{
    /**
     * @param  string  $id  Unique identifier (kebab-case, used for registration and routing)
     * @param  string  $name  Display name in UI
     * @param  string  $description  Short description / subtitle
     * @param  string  $icon  Lucide icon name (e.g. "User", "Building2")
     * @param  string  $version  Semver version string
     * @param  string  $assistantClass  Fully-qualified PHP class name
     * @param  string  $routePrefix  URL segment for dynamic routes
     * @param  string  $lockKey  Cache lock key for concurrent discovery prevention
     * @param  string  $cacheKeyPrefix  Prefix for job status cache entries
     * @param  int  $sortOrder  Display order on page (lower = higher)
     * @param  array<string, string>  $statusLabels  Custom status messages
     * @param  array<string, string>  $emptyState  "All done" text
     * @param  string|null  $cardComponent  Custom TSX filename in module folder (null = generic card)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $icon,
        public string $version,
        public string $assistantClass,
        public string $routePrefix,
        public string $lockKey,
        public string $cacheKeyPrefix,
        public int $sortOrder = 100,
        public array $statusLabels = [],
        public array $emptyState = [],
        public ?string $cardComponent = null,
    ) {}

    /**
     * Parse a manifest.json file and return an AssistantManifest instance.
     *
     * @throws \InvalidArgumentException If the file is missing, invalid JSON, or missing required fields
     */
    public static function fromFile(string $path): self
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("Manifest file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \InvalidArgumentException("Could not read manifest file: {$path}");
        }

        try {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Invalid JSON in manifest file {$path}: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($data)) {
            throw new \InvalidArgumentException("Manifest file must contain a JSON object: {$path}");
        }

        $required = ['id', 'name', 'description', 'icon', 'version', 'assistant_class', 'route_prefix', 'lock_key', 'cache_key_prefix'];
        $missing = array_diff($required, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                "Manifest is missing required fields: " . implode(', ', $missing) . " in {$path}"
            );
        }

        // Validate id: must be kebab-case, max 64 chars
        $id = (string) $data['id'];
        if (! preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $id) || strlen($id) > 64) {
            throw new \InvalidArgumentException(
                "Manifest 'id' must be kebab-case (lowercase, hyphens only) and max 64 characters, got '{$id}' in {$path}"
            );
        }

        // Validate route_prefix: must be a safe URL segment
        $routePrefix = (string) $data['route_prefix'];
        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $routePrefix) || strlen($routePrefix) > 64) {
            throw new \InvalidArgumentException(
                "Manifest 'route_prefix' must be a safe URL segment (lowercase, hyphens/underscores) and max 64 characters, got '{$routePrefix}' in {$path}"
            );
        }

        $defaultStatusLabels = [
            'checking' => "Starting {$data['name']} discovery...",
            'completed_with_results' => "{$data['name']} completed: {count} new suggestion(s) found.",
            'completed_empty' => "{$data['name']} completed: No new suggestions found.",
            'failed' => "{$data['name']} failed: {error}",
            'already_running' => "A {$data['name']} job is already running.",
        ];

        $defaultEmptyState = [
            'title' => 'All up to date!',
            'description' => 'No suggestions found. Click "Check" to search again.',
        ];

        return new self(
            id: $id,
            name: (string) $data['name'],
            description: (string) $data['description'],
            icon: (string) $data['icon'],
            version: (string) $data['version'],
            assistantClass: (string) $data['assistant_class'],
            routePrefix: $routePrefix,
            lockKey: (string) $data['lock_key'],
            cacheKeyPrefix: (string) $data['cache_key_prefix'],
            sortOrder: (int) ($data['sort_order'] ?? 100),
            statusLabels: array_merge($defaultStatusLabels, (array) ($data['status_labels'] ?? [])),
            emptyState: array_merge($defaultEmptyState, (array) ($data['empty_state'] ?? [])),
            cardComponent: isset($data['card_component']) ? (string) $data['card_component'] : null,
        );
    }

    /**
     * Convert the manifest to an array for Inertia props.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'version' => $this->version,
            'routePrefix' => $this->routePrefix,
            'sortOrder' => $this->sortOrder,
            'statusLabels' => $this->statusLabels,
            'emptyState' => $this->emptyState,
            'cardComponent' => $this->cardComponent,
        ];
    }
}
