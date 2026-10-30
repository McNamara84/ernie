/** SWHID 1.2 with ERNIE's strict qualifier validation (shared fixtures with PHP). */
export const SWHID_PROFILE = 'ernie-swhid-v1';
export const SWHID_RESOLVER = 'https://archive.softwareheritage.org/';
export const SWHID_OBJECT_LABELS = { cnt: 'File', dir: 'Directory', rev: 'Commit', rel: 'Release', snp: 'Snapshot' } as const;
export type SwhidObjectType = keyof typeof SWHID_OBJECT_LABELS;
export type SwhidQualifier = 'origin' | 'visit' | 'anchor' | 'path' | 'lines' | 'bytes';
export interface ParsedSwhid {
    normalized: string;
    core: string;
    objectType: SwhidObjectType;
    qualifiers: Partial<Record<SwhidQualifier, string>>;
}
export interface SwhidError {
    code: string;
    field: string;
    message: string;
}

const RESOLVER_PREFIX = /^https?:\/\/(?:archive\.softwareheritage\.org\/(?:browse\/)?|(?:identifiers\.org|n2t\.net)\/)(?=swh:)/i;
const CORE = /^swh:1:(cnt|dir|rev|rel|snp):[0-9a-f]{40}(?![\s\S])/;
const QUALIFIERS: readonly string[] = ['origin', 'visit', 'anchor', 'path', 'lines', 'bytes'];
// Explicit shared whitespace set: JS trim and PHP trim otherwise disagree.
/* eslint-disable no-control-regex -- Explicit character sets reject controls and normalize outer whitespace consistently with PHP. */
const OUTER_SPACE =
    /^[\u0009-\u000d\u0020\u0085\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff]+|[\u0009-\u000d\u0020\u0085\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff]+$/g;
const INVALID_IRI_CHARACTER = /[\s\u0000-\u0020\u007f-\u009f<>"\\^`{|}]|%(?![0-9a-f]{2})/iu;
/* eslint-enable no-control-regex */
const IRI_PART = "(?:[A-Za-z0-9._~!$&'()*+,=:@%\\/-]|[^\\u0000-\\u00a0\\ud800-\\udfff])";

export function normalizeSwhid(value: string): string {
    const bare = value.replace(OUTER_SPACE, '').replace(RESOLVER_PREFIX, '');
    const separator = bare.indexOf(';');
    const core = separator < 0 ? bare : bare.slice(0, separator);
    // Only the ASCII core alphabet is case-insensitive, including malformed candidates.
    const normalizedCore = /^swh:/i.test(core) ? core.replace(/[A-Z]/g, (letter) => letter.toLowerCase()) : core;
    const withoutSlash =
        /^swh:1:(?:cnt|dir|rev|rel|snp):[0-9a-f]{40}\/$/.test(normalizedCore) && separator < 0 ? normalizedCore.slice(0, -1) : normalizedCore;
    return withoutSlash + (separator < 0 ? '' : bare.slice(separator));
}

export function isSwhidCandidate(value: string): boolean {
    return /^swh:/i.test(normalizeSwhid(value));
}

function isOrigin(value: string): boolean {
    if (INVALID_IRI_CHARACTER.test(value)) return false;
    // RFC 3987 components, without decoding/serializing the supplied IRI.
    const match = value.match(/^([A-Za-z][A-Za-z0-9+.-]*):(?:\/\/([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$/u);
    if (!match) return false;
    const [, scheme, authority, path, query = '', fragment = ''] = match;
    if (!new RegExp(`^${IRI_PART}*$`, 'u').test(path)) return false;
    if (![query, fragment].every((part) => new RegExp(`^(?:${IRI_PART}|[?])*$`, 'u').test(part))) return false;
    const host = authority?.match(/^(?:[^\s/?#@[\]]*@)?(\[[^[\]]+\]|[^\s:/?#@[\]]*)(?::[0-9]*)?$/u)?.[1];
    if (authority !== undefined && host === undefined) return false;
    if (host?.startsWith('[')) {
        const literal = host.slice(1, -1);
        if (!/^v[0-9a-f]+\.[a-z0-9._~!$&'()*+,=:-]+$/i.test(literal)) {
            try {
                new URL(`http://${host}/`);
            } catch {
                return false;
            }
        }
    }
    return !/^(?:https?|ftp)$/i.test(scheme) || (host !== undefined && host !== '');
}

function isAbsolutePath(value: string): boolean {
    // Escaped octets need not be UTF-8 (archive paths can contain arbitrary bytes).
    return /^(?:\/|%2f)/i.test(value) && !INVALID_IRI_CHARACTER.test(value) && new RegExp(`^${IRI_PART}+$`, 'u').test(value);
}

function validRange(value: string, lines: boolean): boolean {
    if (!/^[0-9]+(?:-[0-9]+)?$/.test(value)) return false;
    const [start, end = start] = value.split('-').map((part) => part.replace(/^0+(?=\d)/, ''));
    return !(lines && start === '0') && (start.length < end.length || (start.length === end.length && start <= end));
}

export function validateSwhid(value: string): { valid: boolean; normalized: string; parsed: ParsedSwhid | null; errors: SwhidError[] } {
    const normalized = normalizeSwhid(value);
    const fail = (code: string, field: string, message: string) => ({ valid: false, normalized, parsed: null, errors: [{ code, field, message }] });
    const [core, ...parts] = normalized.split(';');
    const match = core.match(CORE);
    if (!match) {
        const components = core.split(':');
        if (components[0] === 'swh' && components[1] !== '1') {
            return fail('unsupported_version', 'identifier', 'Invalid SWHID version. Expected swh:1.');
        }
        if (components[0] === 'swh' && components[1] === '1' && !Object.hasOwn(SWHID_OBJECT_LABELS, components[2] ?? '')) {
            return fail('unsupported_object_type', 'identifier', 'Invalid SWHID object type. Expected cnt, dir, rev, rel or snp.');
        }
        return fail(
            'invalid_core',
            'identifier',
            'Invalid SWHID. Expected swh:1:<object type>:<40 hexadecimal characters>, optionally followed by qualifiers.',
        );
    }
    const objectType = match[1] as SwhidObjectType;
    const qualifiers: ParsedSwhid['qualifiers'] = {};
    for (const part of parts) {
        const separator = part.indexOf('=');
        const key = separator < 0 ? part : part.slice(0, separator);
        if (!QUALIFIERS.includes(key))
            return fail('unknown_qualifier', `qualifiers.${key}`, 'Unknown SWHID qualifier. Expected origin, visit, anchor, path, lines or bytes.');
        if (Object.hasOwn(qualifiers, key)) return fail('duplicate_qualifier', `qualifiers.${key}`, `SWHID qualifier ${key} must occur only once.`);
        const qualifierValue = separator < 0 ? '' : part.slice(separator + 1);
        if (!qualifierValue || INVALID_IRI_CHARACTER.test(qualifierValue))
            return fail(
                'invalid_qualifier_value',
                `qualifiers.${key}`,
                `Invalid SWHID ${key} value. Use a non-empty value with valid percent encoding and no whitespace.`,
            );
        qualifiers[key as SwhidQualifier] = qualifierValue;
    }
    for (const key of ['origin', 'path', 'visit', 'anchor'] as const) {
        const qualifierValue = qualifiers[key];
        if (qualifierValue === undefined) continue;
        const valid =
            key === 'origin'
                ? isOrigin(qualifierValue)
                : key === 'path'
                  ? isAbsolutePath(qualifierValue)
                  : key === 'visit'
                    ? /^swh:1:snp:[0-9a-f]{40}$/.test(qualifierValue)
                    : /^swh:1:(?:dir|rev|rel|snp):[0-9a-f]{40}$/.test(qualifierValue);
        if (!valid)
            return fail(
                'invalid_qualifier_value',
                `qualifiers.${key}`,
                `Invalid SWHID ${key}. Expected ${key === 'origin' ? 'an absolute URI/IRI' : key === 'path' ? 'an absolute path' : key === 'visit' ? 'a snapshot core SWHID' : 'a directory, commit, release or snapshot core SWHID'}.`,
            );
    }
    for (const [key, dependency] of [
        ['visit', 'origin'],
        ['anchor', 'path'],
    ] as const) {
        if (qualifiers[key] !== undefined && qualifiers[dependency] === undefined)
            return fail('missing_qualifier_dependency', `qualifiers.${key}`, `SWHID qualifier ${key} requires ${dependency}.`);
    }
    if (qualifiers.lines !== undefined && qualifiers.bytes !== undefined)
        return fail('conflicting_fragment_qualifiers', 'qualifiers', 'Use either lines or bytes, not both.');
    for (const key of ['lines', 'bytes'] as const) {
        const qualifierValue = qualifiers[key];
        if (qualifierValue === undefined) continue;
        if (objectType !== 'cnt')
            return fail('invalid_qualifier_value', `qualifiers.${key}`, `SWHID qualifier ${key} is only valid for files (cnt).`);
        if (!validRange(qualifierValue, key === 'lines'))
            return fail(
                'invalid_range',
                `qualifiers.${key}`,
                `Invalid SWHID ${key}. Expected N or N-M, starting at ${key === 'lines' ? '1' : '0'}, with N no greater than M.`,
            );
    }
    return { valid: true, normalized, parsed: { normalized, core, objectType, qualifiers }, errors: [] };
}

export function parseSwhid(value: string): ParsedSwhid | null {
    return validateSwhid(value).parsed;
}

export function isValidSwhid(value: string): boolean {
    return validateSwhid(value).valid;
}

export function resolveSwhidUrl(value: string): string | null {
    const parsed = parseSwhid(value);
    return parsed ? SWHID_RESOLVER + parsed.normalized : null;
}

export function getSwhidComparisonKey(value: string): string | null {
    const parsed = parseSwhid(value);
    if (!parsed) return null;
    const entries = Object.entries(parsed.qualifiers).sort(([a], [b]) => a.localeCompare(b));
    return (
        parsed.core +
        entries
            .map(([key, entry]) => {
                // Compare decoded bytes, including non-UTF-8 paths; never translate '+'.
                const encoded = new TextEncoder().encode(entry);
                const bytes: number[] = [];
                for (let index = 0; index < encoded.length; index++) {
                    if (encoded[index] === 37) {
                        bytes.push(Number.parseInt(String.fromCharCode(encoded[index + 1], encoded[index + 2]), 16));
                        index += 2;
                    } else bytes.push(encoded[index]);
                }
                return `;${key}=${bytes.map((byte) => byte.toString(16).padStart(2, '0')).join('')}`;
            })
            .join('')
    );
}

export function getSwhidQualifierLabel(parsed: ParsedSwhid): string {
    const decode = (value: string) => {
        try {
            return decodeURIComponent(value);
        } catch {
            return value;
        }
    };
    return (['origin', 'path', 'lines', 'bytes'] as const)
        .flatMap((key) => {
            const value = parsed.qualifiers[key];
            if (value === undefined) return [];
            return [
                key === 'origin'
                    ? `Origin: ${decode(value)}`
                    : key === 'path'
                      ? `Path: ${decode(value)}`
                      : `${key === 'lines' ? 'Lines' : 'Bytes'} ${value.replace('-', '–')}`,
            ];
        })
        .join(' · ');
}
