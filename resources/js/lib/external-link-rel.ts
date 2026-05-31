export function buildExternalLinkRel(rel?: string): string {
    const tokens = new Map<string, string>();

    for (const token of rel?.split(/\s+/) ?? []) {
        const trimmedToken = token.trim();

        if (trimmedToken === '') {
            continue;
        }

        tokens.set(trimmedToken.toLowerCase(), trimmedToken);
    }

    for (const requiredToken of ['noopener', 'noreferrer']) {
        if (!tokens.has(requiredToken)) {
            tokens.set(requiredToken, requiredToken);
        }
    }

    return Array.from(tokens.values()).join(' ');
}