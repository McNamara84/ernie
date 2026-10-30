import cases from '@tests/fixtures/identifiers/swhid-cases.json';
import { describe, expect, it } from 'vitest';

import {
    getSwhidComparisonKey,
    getSwhidQualifierLabel,
    isSwhidCandidate,
    isValidSwhid,
    normalizeSwhid,
    parseSwhid,
    resolveSwhidUrl,
    validateSwhid,
} from '@/lib/swhid';

describe('SWHID shared PHP/TypeScript contract', () => {
    it.each(cases.cases)('$name', ({ input, normalized, candidate, valid, error }) => {
        expect(isSwhidCandidate(input)).toBe(candidate);
        expect(normalizeSwhid(input)).toBe(normalized);
        expect(normalizeSwhid(normalized)).toBe(normalized);
        const result = validateSwhid(input);
        expect(result.valid).toBe(valid);
        expect(isValidSwhid(input)).toBe(valid);
        expect(result.normalized).toBe(normalized);
        expect(result.errors[0]?.code ?? null).toBe(error);
        expect(parseSwhid(input)).toEqual(result.parsed);
        expect(resolveSwhidUrl(input)).toBe(valid ? `https://archive.softwareheritage.org/${normalized}` : null);
        if (valid) {
            expect(result.parsed?.core).toBe(normalized.split(';')[0]);
            expect(result.errors).toEqual([]);
        } else {
            expect(result.parsed).toBeNull();
            expect(result.errors[0].field).toBeTruthy();
            expect(result.errors[0].message).toBeTruthy();
            expect(getSwhidComparisonKey(input)).toBeNull();
        }
    });

    it.each(cases.equivalent)('compares equivalent values: %s', (first, second) => {
        expect(getSwhidComparisonKey(first)).not.toBeNull();
        expect(getSwhidComparisonKey(first)).toBe(getSwhidComparisonKey(second));
    });

    it.each(cases.distinct)('preserves distinct context: %s', (first, second) => {
        expect(getSwhidComparisonKey(first)).not.toBe(getSwhidComparisonKey(second));
    });

    it('renders the specification file example, including cnt with path, without visit or anchor', () => {
        // Clause 6's prose conflicts with its own cnt/path example; #1385 explicitly requires this example.
        const parsed = parseSwhid(cases.cases.find((entry) => entry.name === 'specification qualified file (cnt with path)')!.input)!;
        expect(parsed.objectType).toBe('cnt');
        expect(getSwhidQualifierLabel(parsed)).toBe(
            'Origin: https://gitorious.org/ocamlp3l/ocamlp3l_cvs.git · Path: /Examples/SimpleFarm/simplefarm.ml · Lines 9–15',
        );
    });

    it('decodes readable labels without changing stored values or throwing on non-UTF-8 paths', () => {
        const core = cases.cases[0].input;
        expect(getSwhidQualifierLabel(parseSwhid(core)!)).toBe('');
        expect(getSwhidQualifierLabel(parseSwhid(`${core};path=/A%20B;bytes=0-9`)!)).toBe('Path: /A B · Bytes 0–9');
        expect(getSwhidQualifierLabel(parseSwhid(`${core};path=/%FF`)!)).toBe('Path: /%FF');
    });
});
