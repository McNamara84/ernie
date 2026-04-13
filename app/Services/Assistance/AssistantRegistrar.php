<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use Illuminate\Support\Facades\Log;

/**
 * Registry for assistant modules discovered via auto-discovery.
 *
 * This singleton scans modules/assistants/{name}/manifest.json at application boot,
 * instantiates each assistant class via the Laravel container (enabling DI),
 * and provides a registry for the controller and middleware to query.
 *
 * Usage:
 *   $registrar->getAll()          → all registered assistants
 *   $registrar->get('orcid-...')  → one specific assistant or null
 *   $registrar->totalPendingCount() → sidebar badge number
 */
class AssistantRegistrar
{
    /** @var array<string, AssistantContract> */
    private array $assistants = [];

    /**
     * Scan the modules/assistants/ directory and register all valid modules.
     *
     * Modules are discovered by looking for manifest.json files in immediate
     * subdirectories of the given base path.
     */
    public function discoverModules(string $basePath): void
    {
        if (! is_dir($basePath)) {
            return;
        }

        $manifests = glob($basePath . '/*/manifest.json');

        if ($manifests === false) {
            return;
        }

        foreach ($manifests as $manifestPath) {
            try {
                $this->registerFromManifest($manifestPath);
            } catch (\Throwable $e) {
                Log::warning("Failed to register assistant from {$manifestPath}: {$e->getMessage()}");
            }
        }

        // Sort by sortOrder
        uasort($this->assistants, fn (AssistantContract $a, AssistantContract $b) => $a->getManifest()->sortOrder <=> $b->getManifest()->sortOrder);
    }

    /**
     * Register assistants from a pre-resolved list of manifest paths (cached boot).
     *
     * @param  list<string>  $manifestPaths
     */
    public function registerFromPaths(array $manifestPaths): void
    {
        foreach ($manifestPaths as $manifestPath) {
            try {
                $this->registerFromManifest($manifestPath);
            } catch (\Throwable $e) {
                Log::warning("Failed to register assistant from {$manifestPath}: {$e->getMessage()}");
            }
        }

        uasort($this->assistants, fn (AssistantContract $a, AssistantContract $b) => $a->getManifest()->sortOrder <=> $b->getManifest()->sortOrder);
    }

    /**
     * Register an assistant from a manifest.json file path.
     *
     * Skips registration if another assistant with the same ID is already registered.
     */
    private function registerFromManifest(string $manifestPath): void
    {
        $manifest = AssistantManifest::fromFile($manifestPath);

        if (isset($this->assistants[$manifest->id])) {
            Log::warning("Duplicate assistant ID '{$manifest->id}' in {$manifestPath} — skipping (already registered).");

            return;
        }

        $class = $manifest->assistantClass;

        if (! class_exists($class)) {
            throw new \RuntimeException("Assistant class not found: {$class}");
        }

        /** @var object $instance */
        $instance = app()->make($class);

        if (! $instance instanceof AssistantContract) {
            throw new \RuntimeException(
                "Class {$class} must implement " . AssistantContract::class,
            );
        }

        $this->assistants[$manifest->id] = $instance;
    }

    /**
     * Manually register an assistant (useful for testing).
     */
    public function register(AssistantContract $assistant): void
    {
        $this->assistants[$assistant->getId()] = $assistant;
    }

    /**
     * Get all registered assistants, sorted by sortOrder.
     *
     * @return array<string, AssistantContract>
     */
    public function getAll(): array
    {
        return $this->assistants;
    }

    /**
     * Get a specific assistant by its ID.
     */
    public function get(string $id): ?AssistantContract
    {
        return $this->assistants[$id] ?? null;
    }

    /**
     * Check if an assistant with the given ID is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->assistants[$id]);
    }

    /**
     * Get the total number of pending suggestions across all registered assistants.
     *
     * Delegates to each assistant's countPending() so that only registered
     * modules contribute to the total. With 3–5 assistants, this is a
     * negligible number of simple COUNT queries.
     */
    public function totalPendingCount(): int
    {
        $total = 0;

        foreach ($this->assistants as $assistant) {
            $total += $assistant->countPending();
        }

        return $total;
    }
}
