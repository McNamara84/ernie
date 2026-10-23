import { spawnSync } from 'node:child_process';

import type { TestInfo } from '@playwright/test';

type Fixture = {
    tag: string;
    title: string;
    id: number;
    actor: number;
    next: number;
    beginner: number;
    email: string;
    beginnerEmail: string;
    password: string;
};

export function curationFixture(testInfo: TestInfo, action: 'create' | 'clean', data?: Fixture, count = 1): Fixture {
    const docker = ['playwright.devstack.config.ts', 'playwright.docker.config.ts'].some((config) => testInfo.config.configFile?.endsWith(config));
    const args = ['-d', 'memory_limit=2G', 'tests/playwright/fixtures/curation-state.php', action, String(count)];
    const result = spawnSync(
        docker ? 'docker' : 'php',
        docker ? ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml', 'exec', '-T', 'app', 'php', ...args] : args,
        { input: data ? JSON.stringify(data) : undefined, encoding: 'utf8', timeout: 120_000 },
    );
    if (result.error || result.status !== 0) throw result.error ?? new Error(result.stderr || result.stdout);
    return action === 'create' ? (JSON.parse(result.stdout) as Fixture) : data!;
}
