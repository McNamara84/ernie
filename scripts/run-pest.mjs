import { spawnSync } from 'node:child_process';

const composeArgs = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];
const pestArgs = process.argv.slice(2);
const shouldPreserveScreenshotChanges = process.env.KEEP_BROWSER_SCREENSHOT_CHANGES === '1';
const isUpdatingSnapshots = pestArgs.includes('--update-snapshots');
const isCapturingResourcesSelectionScreenshots =
    process.env.CAPTURE_RESOURCES_SELECTION_SCREENSHOTS === '1';

function run(command, args) {
    const result = spawnSync(command, args, {
        stdio: 'inherit',
    });

    if (result.error) {
        throw result.error;
    }

    if (result.signal) {
        process.kill(process.pid, result.signal);
    }

    if (result.status !== 0) {
        process.exit(result.status ?? 1);
    }
}

run('docker', [...composeArgs, 'up', '-d', '--wait', 'db', 'redis', 'app']);
run('docker', [...composeArgs, 'exec', '-T', 'app', 'php', './vendor/bin/pest', '--no-coverage', ...pestArgs]);

if (! shouldPreserveScreenshotChanges && ! isUpdatingSnapshots && ! isCapturingResourcesSelectionScreenshots) {
    // Pest browser runs can touch tracked baseline images; restore them unless intentionally updating.
    run('git', ['restore', '--worktree', '--', 'tests/Browser/Screenshots']);
}
