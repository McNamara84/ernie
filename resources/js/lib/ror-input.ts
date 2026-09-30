import type { AffiliationSuggestion } from '@/types/affiliations';

export type RorInput = { kind: 'name'; name: string } | { kind: 'invalid'; name: string } | { kind: 'ror'; name: string; rorId: string };

const ROR_ID = /^0[0-9a-hj-km-np-tv-z]{6}[0-9]{2}$/;
const ROR_URL = /^(?:https?:\/\/)?(?:www\.)?ror\.org\/([^/?#]+)\/?$/i;

export function canonicalRorId(value: string): string | null {
    const trimmed = value.trim();
    const id = (ROR_URL.exec(trimmed)?.[1] ?? trimmed).toLowerCase();
    return ROR_ID.test(id) ? `https://ror.org/${id}` : null;
}

function looksLikeRor(value: string): boolean {
    return /ror\.org/i.test(value) || /^0[a-z0-9]{8}$/i.test(value);
}

/** Parse only a whole identifier or a final identifier in parentheses, never arbitrary name fragments. */
export function parseRorInput(text: string): RorInput {
    const trimmed = text.trim();
    const pair = /^(.*?)\s*\(([^()]*)\)$/.exec(trimmed);
    const candidate = pair?.[2].trim() ?? trimmed;
    const rorId = canonicalRorId(candidate);
    if (rorId) return { kind: 'ror', name: pair?.[1].trim() ?? '', rorId };
    if (looksLikeRor(candidate) || /ror\.org/i.test(trimmed)) return { kind: 'invalid', name: pair?.[1].trim() ?? '' };
    return { kind: 'name', name: trimmed };
}

export function indexRorSuggestions(suggestions: AffiliationSuggestion[]): Map<string, AffiliationSuggestion> {
    const index = new Map<string, AffiliationSuggestion>();
    for (const suggestion of suggestions) {
        const id = canonicalRorId(suggestion.rorId ?? '');
        if (id) index.set(id, suggestion);
    }
    return index;
}

export function resolveRorInput(input: RorInput, index: ReadonlyMap<string, AffiliationSuggestion>): AffiliationSuggestion | null {
    if (input.kind !== 'ror') return null;
    const match = index.get(input.rorId);
    return match ? { ...match, value: input.name || match.value, rorId: input.rorId } : null;
}
