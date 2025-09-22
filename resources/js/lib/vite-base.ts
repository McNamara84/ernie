export function normalizeAssetBase(assetUrl?: string | null): string {
    if (!assetUrl) {
        return '';
    }

    return assetUrl.replace(/\/$/, '');
}

export function resolveViteBase(assetUrl?: string | null): string {
    const normalized = normalizeAssetBase(assetUrl);

    return normalized ? `${normalized}/build/` : '/build/';
}
