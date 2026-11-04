export const stages = ['environment', 'composer', 'backend', 'mysql', 'phpstan'];
export const labels = { environment: 'Environment', composer: 'Composer', backend: 'Backend', mysql: 'MySQL', phpstan: 'PHPStan' };

const excludedDirectories = new Set(['.git', '.tmp', '.release-candidate', '.vitest', 'node_modules', 'vendor', 'coverage', 'playwright-report', 'test-results', '.trivy-cache']);
const environmentTemplates = new Set(['.env.example', '.env.docker.example', '.env.production', '.env.testing']);

export function includeSnapshotPath(relative) {
    if (excludedDirectories.has(relative.split('/')[0])) return false;
    if (/^\.env(?:[.-]|$)/.test(relative)) return environmentTemplates.has(relative);
    if (/^storage\/app\//.test(relative)) return relative.startsWith('storage/app/testing/') || relative.endsWith('/.gitignore');
    return !/^storage\/(?:logs|framework|inertia-devtools)(?:\/|$)/.test(relative)
        && !/^public\/(?:build|hot)(?:\/|$)/.test(relative)
        && !/^docker\/traefik\/certs\/.*\.(?:key|crt)$/.test(relative);
}

export function selectRelease(stable, qa) {
    if (/^8\.6\.\d+$/.test(stable?.version) && stable.date && stable.source?.length) {
        return { version: stable.version, channel: 'stable' };
    }
    if (stable?.error !== 'Unknown version') throw new Error('Unrecognized stable PHP release metadata.');
    const candidates = Object.entries(qa ?? {}).flatMap(([base, entry]) => {
        const release = entry?.release;
        if (!/^8\.6\.\d+$/.test(base) || entry.active !== true || release?.enabled === false
            || release?.type !== 'RC' || !Number.isInteger(release.number) || release.number < 1
            || !/^[a-f0-9]{64}$/.test(release.sha256_xz ?? '')) return [];
        return [{ version: `${base}RC${release.number}`, channel: 'RC', patch: Number(base.split('.')[2]), rc: release.number }];
    }).sort((a, b) => b.patch - a.patch || b.rc - a.rc);
    if (!candidates.length) throw new Error('No published PHP 8.6 release candidate is available.');
    const { version, channel } = candidates[0];
    return { version, channel };
}

export function runtimePins(compose) {
    const mysql = compose.match(/\bmysql:9\.7\.\d+@sha256:[a-f0-9]{64}\b/g);
    const redis = compose.match(/\bredis:[\w.-]+@sha256:[a-f0-9]{64}\b/g);
    if (!mysql?.length || new Set(mysql).size !== 1 || !redis?.length || new Set(redis).size !== 1) {
        throw new Error('Expected one pinned MySQL 9.7 server and Redis runtime in the development Compose file.');
    }
    return { mysql: mysql[0], redis: redis[0] };
}

export function resultOf(report) {
    const first = stages.find((name) => report?.stages?.[name]?.status !== 'passed');
    if (!first) {
        const metadata = report?.metadata;
        if (report.schemaVersion !== 1 || !/^8\.6\.\d+(?:RC\d+)?$/.test(metadata?.php ?? '')
            || !/^php:8\.6\.\d+(?:RC\d+)?-fpm-trixie@sha256:[a-f0-9]{64}$/.test(metadata?.image ?? '')
            || !/^[a-f0-9]{64}$/.test(metadata?.lockSha256 ?? '') || !/^9\.7\.\d+/.test(metadata?.mysql ?? '')) {
            return { status: 'unavailable', label: 'unavailable – Evidence', blocker: 'evidence' };
        }
        return { status: 'compatible', label: 'compatible', blocker: '' };
    }
    if (report?.stages?.[first]?.status === 'incompatible') {
        return { status: 'incompatible', label: `incompatible – ${labels[first]}`, blocker: first };
    }
    return { status: 'unavailable', label: `unavailable – ${labels[first]}`, blocker: first };
}

export function skipFollowing(report, stage) {
    for (const name of stages.slice(stages.indexOf(stage) + 1)) {
        report.stages[name] = { status: 'skipped', reason: `Blocked by ${labels[stage]}.` };
    }
}

const escape = (value) => String(value ?? '—').replace(/[<>|`]/g, (char) => ({ '<': '&lt;', '>': '&gt;', '|': '&#124;', '`': '&#96;' })[char]).replace(/[\r\n]+/g, ' ');

export function summaryOf(report) {
    const result = resultOf(report);
    const metadata = report.metadata ?? {};
    return [
        `# PHP 8.6: ${result.label}`,
        '',
        'This check is advisory. A green check records the result; only **compatible** means every required stage passed.',
        '',
        '| Evidence | Value |', '| --- | --- |',
        ...Object.entries(metadata).map(([key, value]) => `| ${escape(key)} | ${escape(value)} |`),
        '', '| Stage | Result | Seconds | Detail |', '| --- | --- | --- | --- |',
        ...stages.map((name) => {
            const stage = report.stages?.[name] ?? { status: 'pending', reason: 'No result was received.' };
            return `| ${labels[name]} | ${escape(stage.status)} | ${escape(stage.seconds)} | ${escape(stage.reason)} |`;
        }),
        '', 'Later stages run only after all earlier stages pass. Browser tests and production promotion are outside this check.',
        '',
    ].join('\n');
}

export function hasTestFailure(text) {
    return /<(?:failure|error)(?:\s|>)/.test(text) || /\b(?:failures|errors)="[1-9]\d*"/.test(text);
}

export function platformFailures(stdout) {
    try {
        const rows = JSON.parse(stdout);
        return Array.isArray(rows) ? rows.filter((row) => typeof row.name === 'string' && ['failed', 'missing'].includes(row.status)) : [];
    } catch { return []; }
}

export function jsonLdFailure(log) {
    for (const line of log.split(/\r?\n/)) {
        try {
            const evidence = JSON.parse(line);
            if (evidence?.type === 'jsonld-semantic-failure' && typeof evidence.message === 'string') return evidence.message;
        } catch { /* Ordinary command output is not structured failure evidence. */ }
    }
    return undefined;
}

export function hasPhpstanFailure(log) {
    const start = log?.indexOf('{"totals"') ?? -1;
    if (start < 0) return false;
    try {
        const result = JSON.parse(log.slice(start, log.lastIndexOf('}') + 1));
        // Global errors mean the analysis is incomplete, even if some workers reported file diagnostics.
        if (result?.totals?.errors !== 0 || !Array.isArray(result.errors) || result.errors.length > 0
            || !Number.isInteger(result.totals.file_errors) || result.totals.file_errors <= 0
            || !result.files || typeof result.files !== 'object' || Array.isArray(result.files)) return false;
        return Object.values(result.files).some((file) => Array.isArray(file?.messages)
            && file.messages.some((diagnostic) => typeof diagnostic?.message === 'string' && diagnostic.message.trim().length > 0));
    } catch { return false; }
}

export function extensionBuildFailure(text) {
    if (/Killed signal|out of memory|No space left on device/i.test(text)) return undefined;
    const match = text.match(/\/usr\/src\/php\/ext\/([a-z0-9_-]+)\/[^\r\n]+?:\d+:\d+: error: (?:implicit declaration of function [^\r\n]*(?:ZEND_|zend_|zval_)[^\r\n]*|[^\r\n]*incompatible[^\r\n]*)/);
    return match ? { extension: match[1], diagnostic: match[0] } : undefined;
}
