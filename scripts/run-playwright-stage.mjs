import { spawnSync } from 'node:child_process';

const allow = process.env.ERNIE_ALLOW_STAGE_TESTS === 'true' || process.env.ERNIE_ALLOW_STAGE_TESTS === '1';

if (!allow) {
  console.error(
    '[ernie] Stage tests are disabled by default.\n' +
      'Set ERNIE_ALLOW_STAGE_TESTS=true (or 1) to enable them, e.g.:\n' +
      '  ERNIE_ALLOW_STAGE_TESTS=true npm run test:e2e:stage\n'
  );
  process.exit(1);
}

const npxCmd = process.platform === 'win32' ? 'npx.cmd' : 'npx';
const extraArgs = process.argv.slice(2);

const result = spawnSync(
  npxCmd,
  ['playwright', 'test', '--config=playwright.stage.config.ts', ...extraArgs],
  { stdio: 'inherit' }
);

process.exit(result.status ?? 1);
