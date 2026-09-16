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

        expect($appStorageMount['source'])->toBe('storage-data')
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

    it('uses prebuilt Stage image templates without local builds', function () use ($parsedCompose) {
        $compose = $parsedCompose('docker-compose.stage.yml');
        $services = $compose['services'] ?? null;

        if (! is_array($services)) {
            throw new RuntimeException('Stage Compose file is missing the services map.');
        }

        $expectedImages = [
            'app' => '${ERNIE_STAGE_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
            'webserver' => '${ERNIE_STAGE_NGINX_IMAGE:-ghcr.io/mcnamara84/ernie-nginx:deployment-template}',
            'queue' => '${ERNIE_STAGE_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
            'assessment-queue' => '${ERNIE_STAGE_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
            'scheduler' => '${ERNIE_STAGE_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
        ];

        foreach ($expectedImages as $serviceName => $expectedImage) {
            $service = $services[$serviceName] ?? null;

            expect($service)->toBeArray()
                ->and($service)->not->toHaveKey('build')
                ->and($service['image'] ?? null)->toBe($expectedImage)
                ->and($service['pull_policy'] ?? null)->toBe('always');
        }
    });

    it('uses prebuilt Production image templates without local builds', function () use ($parsedCompose) {
        $compose = $parsedCompose('docker-compose.prod.yml');
        $services = $compose['services'] ?? null;

        if (! is_array($services)) {
            throw new RuntimeException('Production Compose file is missing the services map.');
        }

        $expectedImages = [
            'app' => '${ERNIE_PROD_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
            'webserver' => '${ERNIE_PROD_NGINX_IMAGE:-ghcr.io/mcnamara84/ernie-nginx:deployment-template}',
            'queue' => '${ERNIE_PROD_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
            'assessment-queue' => '${ERNIE_PROD_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
            'scheduler' => '${ERNIE_PROD_APP_IMAGE:-ghcr.io/mcnamara84/ernie-app:deployment-template}',
        ];

        foreach ($expectedImages as $serviceName => $expectedImage) {
            $service = $services[$serviceName] ?? null;

            expect($service)->toBeArray()
                ->and($service)->not->toHaveKey('build')
                ->and($service['image'] ?? null)->toBe($expectedImage)
                ->and($service['pull_policy'] ?? null)->toBe('always');
        }
    });
});
