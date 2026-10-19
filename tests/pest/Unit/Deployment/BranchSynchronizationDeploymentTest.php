<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('validates main and dev with every deployment blocking workflow', function (string $file): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/'.$file));

    expect($workflow['on']['push']['branches'])->toBe(['main', 'dev'])
        ->and($workflow['on']['pull_request']['branches'])->toBe(['main', 'dev']);
})->with(['security.yml', 'tests.yml', 'mysql.yml', 'vitest.yml', 'lint.yml', 'playwright.yml']);

it('runs branch synchronization from trusted main with an environment scoped App token', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/sync-main-to-dev.yml'));
    $job = $workflow['jobs']['synchronize'];
    $steps = collect($job['steps'])->keyBy('name');

    expect($workflow['on'])->not->toHaveKeys(['pull_request', 'pull_request_target'])
        ->and($workflow['on']['push']['branches'])->toBe(['main'])
        ->and($workflow['on']['schedule'])->not->toBeEmpty()
        ->and($workflow['concurrency']['cancel-in-progress'])->toBeFalse()
        ->and($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($job['if'])->toContain("github.ref == 'refs/heads/main'")
        ->and($job['environment'])->toBe('branch-sync')
        ->and($steps->get('Checkout trusted automation')['with']['ref'])->toBe('refs/heads/main')
        ->and($steps->get('Reconcile synchronization PR')['with']['github-token'])->toBe('${{ steps.token.outputs.token }}')
        ->and($steps->get('Reconcile synchronization PR')['with']['retries'])->toBe(3);
});

it('stores artifacts before the dev-only Stage head guard and reuses recorded digests', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/publish-stage-images.yml'));
    $steps = collect($workflow['jobs']['publish']['steps'])->keyBy('name');
    $names = array_keys($steps->all());

    expect($steps->get('Confirm that the candidate is still current')['if'])->toBe("needs.validate.outputs.branch == 'dev'")
        ->and(array_search('Record immutable image artifacts', $names, true))
        ->toBeLessThan(array_search('Confirm that the candidate is still current', $names, true))
        ->and($steps->get('Record immutable image artifacts')['with']['script'])->toContain('publishArtifact(github, context.repo, record)')
        ->and($steps->get('Build and push application image')['if'])->toBe("steps.existing.outputs.exists != 'true'")
        ->and($workflow['concurrency']['group'])->toContain('github.event.workflow_run.head_branch')
        ->toContain('github.event.workflow_run.head_sha')
        ->and($workflow['concurrency']['cancel-in-progress'])->toBe("\${{ github.event.workflow_run.head_branch == 'dev' }}");
});

it('does not send development prereleases to the stable GitLab mirror', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/mirror-to-gitlab.yml'));

    expect($workflow['jobs']['verify']['if'])->toContain('!github.event.release.prerelease');
});
