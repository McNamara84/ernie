export const TEST_USER_EMAIL = process.env.TEST_USER_EMAIL ?? 'test@example.com';
export const TEST_USER_PASSWORD = process.env.TEST_USER_PASSWORD ?? 'password';
export const TEST_USER_GREETING = process.env.TEST_USER_GREETING ?? 'Hello Test User!';
export const INVALID_PASSWORD = process.env.INVALID_PASSWORD ?? 'wrong-password';

// Stage environment credentials (for manual testing only - NOT for CI)
export const STAGE_TEST_USERNAME = process.env.STAGE_TEST_USERNAME ?? 'stage@example.com';
export const STAGE_TEST_PASSWORD = process.env.STAGE_TEST_PASSWORD ?? 'stage-password';
