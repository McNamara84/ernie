<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Illuminate\Filesystem\join_paths;

#[Description('Generate Wayfinder definitions and normalize query parameter ordering')]
#[Signature('ernie:wayfinder-generate {--path=} {--skip-actions} {--skip-routes} {--with-form}')]
class GenerateWayfinderDefinitions extends Command
{
    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $exitCode = $this->call('wayfinder:generate', [
            '--path' => $this->option('path'),
            '--skip-actions' => (bool) $this->option('skip-actions'),
            '--skip-routes' => (bool) $this->option('skip-routes'),
            '--with-form' => (bool) $this->option('with-form'),
        ]);

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        return $this->normalizeWayfinderHelper()
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function normalizeWayfinderHelper(): bool
    {
        $basePath = $this->option('path') ?? join_paths(resource_path(), 'js');
        $wayfinderPath = join_paths($basePath, 'wayfinder', 'index.ts');

        if (! $this->files->exists($wayfinderPath)) {
            $this->error("Generated Wayfinder helper not found at {$wayfinderPath}.");

            return false;
        }

        $contents = $this->files->get($wayfinderPath);

        if (str_contains($contents, 'Array.from(params.entries()).sort((')) {
            return true;
        }

        $updatedContents = str_replace(
            '    const str = params.toString();',
            <<<'TS'
    const str = includeExisting
        ? new URLSearchParams(
            Array.from(params.entries()).sort(([leftKey], [rightKey]) => leftKey.localeCompare(rightKey)),
        ).toString()
        : params.toString();
TS,
            $contents,
            $count,
        );

        if ($count !== 1) {
            $this->error('Unable to normalize generated Wayfinder helper output.');

            return false;
        }

        $this->files->put($wayfinderPath, $updatedContents);

        return true;
    }
}