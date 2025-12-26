import { execSync } from 'node:child_process';

function run(command: string): void {
  execSync(command, { stdio: 'inherit' });
}

export default async function globalSetup(): Promise<void> {
  const appContainer = process.env.PLAYWRIGHT_DOCKER_APP_CONTAINER ?? 'ernie-app-dev';

  try {
    run(`docker exec ${appContainer} php artisan migrate:fresh --force --quiet`);
    run(`docker exec ${appContainer} php artisan db:seed --class=DatabaseSeeder --force --quiet`);
    run(`docker exec ${appContainer} php artisan db:seed --class=ResourceTestDataSeeder --force --quiet`);
    run(`docker exec ${appContainer} php artisan db:seed --class=PlaywrightTestSeeder --force --quiet`);
  } catch (error) {
    throw new Error(
      `Playwright Docker global setup failed. Ensure the Docker dev stack is running and the container '${appContainer}' exists.\n` +
        `Tried to run migrations and seeders (DatabaseSeeder, ResourceTestDataSeeder, PlaywrightTestSeeder).\n` +
        `Original error: ${String(error)}`,
    );
  }
}
