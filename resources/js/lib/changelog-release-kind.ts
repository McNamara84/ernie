export type ReleaseKind = 'major' | 'minor' | 'patch';

type VersionedRelease = { version: string };

/** Releases are ordered newest first; compare each release with its older predecessor. */
export function getReleaseKind(releases: VersionedRelease[], index: number): ReleaseKind {
    const current = releases[index];
    const older = releases[index + 1];

    if (!current || !older) return 'major';

    const [major, minor] = current.version.split('.');
    const [olderMajor, olderMinor] = older.version.split('.');

    if (major !== olderMajor) return 'major';
    if (minor !== olderMinor) return 'minor';
    return 'patch';
}
