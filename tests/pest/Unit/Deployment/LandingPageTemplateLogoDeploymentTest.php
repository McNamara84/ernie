<?php

declare(strict_types=1);

function deploymentFileContents(string $relativePath): string
{
    $contents = file_get_contents(base_path($relativePath));

    if ($contents === false) {
        throw new RuntimeException("Failed to read deployment file: {$relativePath}");
    }

    return $contents;
}

describe('landing page template logo deployment regression guard', function () {
    it('creates the public storage symlink in the final nginx image', function () {
        $dockerfile = deploymentFileContents('Dockerfile');

        expect($dockerfile)
            ->toContain('FROM nginx:1.29.8-alpine')
            ->toContain('COPY --from=app /var/www/html/public /var/www/html/public')
            ->toContain('COPY --from=app /var/www/html/storage /var/www/html/storage')
            ->toContain('ln -s ../storage/app/public /var/www/html/public/storage');
    });

    it('keeps app and webserver on the shared storage volume in stage and production', function (string $composeFile) {
        $compose = deploymentFileContents($composeFile);

        expect($compose)
            ->toContain('- storage-data:/var/www/html/storage')
            ->toContain('- storage-data:/var/www/html/storage:ro')
            ->toContain('dockerfile: Dockerfile');
    })->with([
        'stage' => 'docker-compose.stage.yml',
        'production' => 'docker-compose.prod.yml',
    ]);
});