export interface HttpsLinkSegment {
    type: 'link' | 'text';
    value: string;
}

const HTTPS_URL_PATTERN = /https:\/\/[^\s<>"']+/giu;
const SIMPLE_TRAILING_PUNCTUATION = new Set(['.', ',', ';', ':', '!', '?']);
const CLOSING_PAIRS: Record<string, string> = {
    ')': '(',
    ']': '[',
    '}': '{',
};

function countCharacter(value: string, character: string): number {
    return [...value].filter((candidate) => candidate === character).length;
}

function detachTrailingPunctuation(candidate: string): { url: string; trailing: string } {
    let url = candidate;
    let trailing = '';

    while (url !== '') {
        const lastCharacter = url.at(-1) ?? '';

        if (SIMPLE_TRAILING_PUNCTUATION.has(lastCharacter)) {
            trailing = lastCharacter + trailing;
            url = url.slice(0, -1);
            continue;
        }

        const openingCharacter = CLOSING_PAIRS[lastCharacter];
        if (openingCharacter && countCharacter(url, lastCharacter) > countCharacter(url, openingCharacter)) {
            trailing = lastCharacter + trailing;
            url = url.slice(0, -1);
            continue;
        }

        break;
    }

    return { url, trailing };
}

function isHttpsUrl(value: string): boolean {
    try {
        const url = new URL(value);

        return url.protocol === 'https:' && url.hostname !== '';
    } catch {
        return false;
    }
}

function appendSegment(segments: HttpsLinkSegment[], segment: HttpsLinkSegment): void {
    const previous = segments.at(-1);

    if (segment.value === '') {
        return;
    }

    if (segment.type === 'text' && previous?.type === 'text') {
        previous.value += segment.value;
        return;
    }

    segments.push(segment);
}

/**
 * Splits plain description text into safe text and HTTPS link segments.
 * HTTP URLs and HTML-like input deliberately remain plain text.
 */
export function splitHttpsLinks(value: string): HttpsLinkSegment[] {
    const segments: HttpsLinkSegment[] = [];
    let offset = 0;

    for (const match of value.matchAll(HTTPS_URL_PATTERN)) {
        const matchIndex = match.index;
        const matchedValue = match[0];

        if (matchIndex > offset) {
            appendSegment(segments, { type: 'text', value: value.slice(offset, matchIndex) });
        }

        const { url, trailing } = detachTrailingPunctuation(matchedValue);
        if (isHttpsUrl(url)) {
            appendSegment(segments, { type: 'link', value: url });
            appendSegment(segments, { type: 'text', value: trailing });
        } else {
            appendSegment(segments, { type: 'text', value: matchedValue });
        }

        offset = matchIndex + matchedValue.length;
    }

    if (offset < value.length) {
        appendSegment(segments, { type: 'text', value: value.slice(offset) });
    }

    return segments;
}
