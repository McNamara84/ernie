const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Replace an internal DOI-form IGSN in user-visible text while keeping links separate. */
export function replaceIgsnIdentifierText(text: string, doi: string | null | undefined, igsn: string | null | undefined): string {
    if (!doi || !igsn) return text;

    const escapedDoi = escapeRegExp(doi);

    return text.replace(new RegExp(`https?://(?:dx\\.)?doi\\.org/${escapedDoi}`, 'gi'), igsn).replace(new RegExp(escapedDoi, 'gi'), igsn);
}

/** Replace only HTML text nodes; DOI resolver href attributes remain unchanged. */
export function replaceIgsnIdentifierInHtml(html: string, doi: string | null | undefined, igsn: string | null | undefined): string {
    return html
        .split(/(<[^>]+>)/g)
        .map((part) => (part.startsWith('<') ? part : replaceIgsnIdentifierText(part, doi, igsn)))
        .join('');
}
