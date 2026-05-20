<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

describe('landing page template logo deployment regression guard', function () {
    $deploymentFileContents = static function (string $relativePath): string {
        $contents = file_get_contents(base_path($relativePath));

        if ($contents === false) {
            throw new RuntimeException("Failed to read deployment file: {$relativePath}");
        }

        return $contents;
    };

    $parsedCompose = static function (string $relativePath) use ($deploymentFileContents): array {
        $parsed = Yaml::parse($deploymentFileContents($relativePath));

        if (! is_array($parsed)) {
            throw new RuntimeException("Compose file did not parse to an array: {$relativePath}");
        }

        return $parsed;
    };

    $serviceVolumeMount = static function (array $service, string $targetPath): array {
        $volumes = $service['volumes'] ?? null;

        if (! is_array($volumes)) {
            throw new RuntimeException("Service is missing a volumes list for target path: {$targetPath}");
        }

        foreach ($volumes as $volume) {
            if (! is_string($volume)) {
                continue;
            }

            $segments = explode(':', $volume);

            if (count($segments) < 2) {
                continue;
            }

            $source = $segments[0];
            $target = $segments[1];
            $mode = $segments[2] ?? null;

            if ($target === $targetPath) {
                return [
                    'source' => $source,
                    'target' => $target,
                    'mode' => $mode,
                ];
            }
        }

        throw new RuntimeException("No volume mount found for target path: {$targetPath}");
    };

    it('creates the public storage symlink in the final nginx image', function () use ($deploymentFileContents) {
        $dockerfile = $deploymentFileContents('Dockerfile');

        expect($dockerfile)
            ->toContain('FROM nginx:')
            ->toContain(' AS nginx')
            ->toContain('COPY --from=app /var/www/html/public /var/www/html/public')
            ->toContain('COPY --from=app /var/www/html/storage /var/www/html/storage')
            ->toContain('ln -s ../storage/app/public /var/www/html/public/storage');
    });

    it('keeps app and webserver on the shared storage volume in stage and production', function (string $composeFile) use ($parsedCompose, $serviceVolumeMount) {
        $compose = $parsedCompose($composeFile);
        $services = $compose['services'] ?? null;

        if (! is_array($services)) {
            throw new RuntimeException("Compose file is missing the services map: {$composeFile}");
        }

        $app = $services['app'] ?? null;
        $webserver = $services['webserver'] ?? null;

        if (! is_array($app) || ! is_array($webserver)) {
            throw new RuntimeException("Compose file is missing the app or webserver service: {$composeFile}");
        }

        $appStorageMount = $serviceVolumeMount($app, '/var/www/html/storage');
        $webserverStorageMount = $serviceVolumeMount($webserver, '/var/www/html/storage');

        expect($app['build']['dockerfile'] ?? null)
            ->toBe('Dockerfile')
            ->and($webserver['build']['dockerfile'] ?? null)->toBe('Dockerfile')
            ->and($appStorageMount['source'])->toBe('storage-data')
            ->and($webserverStorageMount['source'])->toBe('storage-data')
            ->and($appStorageMount['source'])->toBe($webserverStorageMount['source'])
            ->and($appStorageMount['target'])->toBe('/var/www/html/storage')
            ->and($webserverStorageMount['target'])->toBe('/var/www/html/storage')
            ->and($appStorageMount['mode'])->toBeNull()
            ->and($webserverStorageMount['mode'])->toBe('ro');
    })->with([
        'stage' => 'docker-compose.stage.yml',
        'production' => 'docker-compose.prod.yml',
    ]);
});