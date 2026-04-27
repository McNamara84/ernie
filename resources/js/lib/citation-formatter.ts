import type { RelatedItem, RelatedItemCreator } from '@/types/related-item';

export type CitationStyle = 'apa' | 'ieee';

const CONTAINER_TYPES = ['JournalArticle', 'BookChapter', 'ConferencePaper'];

/**
 * Format a `RelatedItem` as a human-readable citation string.
 *
 * Pure mirror of `App\Services\Citations\CitationFormatter`.
 * Both sides are exercised against the identical fixtures in
 * `tests/Fixtures/citations/` to guarantee byte-identical output.
 */
export function formatCitation(item: RelatedItem, style: CitationStyle = 'apa'): string {
    return style === 'ieee' ? formatIeee(item) : formatApa(item);
}

function formatApa(item: RelatedItem): string {
    const creators = formatCreatorsApa(item.creators);
    const year = item.publication_year != null ? `(${item.publication_year})` : '(n.d.)';
    const title = mainTitle(item);
    const container = containerTitle(item);
    const volIssue = volumeIssue(item);
    const pages = pageRange(item);
    const publisher = item.publisher ?? '';
    const doi = doiUrl(item);

    const parts: string[] = [];
    parts.push(creators !== '' ? `${creators} ${year}.` : `${year}.`);

    let titlePart = title;
    if (container !== null) {
        parts.push(`${titlePart}.`);
        let cont = container;
        if (volIssue !== '') cont += `, ${volIssue}`;
        if (pages !== '') cont += `, ${pages}`;
        parts.push(`${cont}.`);
    } else {
        if (volIssue !== '') titlePart += ` (${volIssue})`;
        if (pages !== '') titlePart += `, ${pages}`;
        parts.push(`${titlePart}.`);
        if (publisher !== '') parts.push(`${publisher}.`);
    }

    if (doi !== null) parts.push(doi);

    return parts.filter((p) => p !== '').join(' ').trim();
}

function formatIeee(item: RelatedItem): string {
    const creators = formatCreatorsIeee(item.creators);
    const title = mainTitle(item);
    const container = containerTitle(item);
    const { volume, issue, publisher, publication_year: year } = item;
    const pages = pageRange(item);
    const doi = doiUrl(item);

    const parts: string[] = [];
    if (creators !== '') parts.push(`${creators},`);
    parts.push(`"${title},"`);

    if (container !== null) {
        let segment = container;
        if (volume) segment += `, vol. ${volume}`;
        if (issue) segment += `, no. ${issue}`;
        if (pages !== '') segment += `, pp. ${pages}`;
        if (year != null) segment += `, ${year}`;
        parts.push(`${segment}.`);
    } else {
        if (publisher) parts.push(`${publisher},`);
        if (year != null) parts.push(`${year}.`);
    }

    if (doi !== null) parts.push(`doi: ${doi}`);

    return parts.join(' ').trim();
}

function mainTitle(item: RelatedItem): string {
    const main = item.titles.find((t) => t.title_type === 'MainTitle');
    return main?.title ?? '[untitled]';
}

function containerTitle(item: RelatedItem): string | null {
    if (CONTAINER_TYPES.includes(item.related_item_type)) {
        return item.publisher && item.publisher !== '' ? item.publisher : null;
    }
    return null;
}

function volumeIssue(item: RelatedItem): string {
    if (item.volume && item.issue) return `${item.volume}(${item.issue})`;
    if (item.volume) return String(item.volume);
    if (item.issue) return `(${item.issue})`;
    return '';
}

function pageRange(item: RelatedItem): string {
    if (item.first_page && item.last_page) return `${item.first_page}-${item.last_page}`;
    if (item.first_page) return String(item.first_page);
    return '';
}

function doiUrl(item: RelatedItem): string | null {
    if (item.identifier_type === 'DOI' && item.identifier) {
        const doi = item.identifier.replace(/^\/+/, '');
        return doi.startsWith('http') ? doi : `https://doi.org/${doi}`;
    }
    return null;
}

function formatCreatorsApa(creators: RelatedItemCreator[]): string {
    const names = creators.map(creatorApaName).filter((n) => n !== '');
    if (names.length === 0) return '';
    if (names.length === 1) return names[0];
    if (names.length <= 20) {
        const last = names[names.length - 1];
        return names.slice(0, -1).join(', ') + ', & ' + last;
    }
    const last = names[names.length - 1];
    return names.slice(0, 19).join(', ') + ', … ' + last;
}

function formatCreatorsIeee(creators: RelatedItemCreator[]): string {
    const names = creators.map(creatorIeeeName).filter((n) => n !== '');
    if (names.length === 0) return '';
    if (names.length === 1) return names[0];
    if (names.length <= 6) {
        const last = names[names.length - 1];
        return names.slice(0, -1).join(', ') + ', and ' + last;
    }
    return names[0] + ' et al.';
}

function creatorApaName(c: RelatedItemCreator): string {
    if (c.name_type === 'Organizational') return c.name ?? '';
    if (c.family_name && c.given_name) {
        return `${c.family_name}, ${initials(c.given_name)}`;
    }
    return c.name ?? '';
}

function creatorIeeeName(c: RelatedItemCreator): string {
    if (c.name_type === 'Organizational') return c.name ?? '';
    if (c.family_name && c.given_name) {
        return `${initials(c.given_name)} ${c.family_name}`;
    }
    return c.name ?? '';
}

function initials(given: string): string {
    return given
        .trim()
        .split(/[\s-]+/)
        .filter((p) => p !== '')
        .map((p) => p.charAt(0).toUpperCase() + '.')
        .join(' ');
}
