process.env.VITEST_DATACITE_TEST_SHARD = '3';
process.env.VITEST_DATACITE_TEST_SHARD_COUNT = '6';

await import('./datacite-form.test-suite');

export {};
