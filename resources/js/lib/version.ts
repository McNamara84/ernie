import changelog from '@data/changelog.json';

/**
 * Extract the latest application version from the changelog data.
 */
export const latestVersion: string = Array.isArray(changelog) && changelog.length > 0 ? changelog[0].version : '0.0.0';
