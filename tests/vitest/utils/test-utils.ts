/**
 * Normalize URLs for testing in different environments.
 * In CI, wayfinder might generate URLs like "//http://localhost/path"
 * while locally they might be "/path"
 */
export function normalizeTestUrl(url: string): string {
    // Handle URLs like "//http://localhost/path" -> "/path"
    if (url.startsWith('//http://localhost')) {
        return url.replace('//http://localhost', '');
    }
    
    // Handle URLs like "http://localhost/path" -> "/path" 
    if (url.startsWith('http://localhost')) {
        return url.replace('http://localhost', '');
    }
    
    return url;
}

/**
 * Check if a URL matches the expected path, handling both local and CI formats
 */
export function urlMatches(actual: string, expected: string): boolean {
    return normalizeTestUrl(actual) === expected;
}

/**
 * Custom matcher for testing URLs that works in both local and CI environments
 */
export function expectUrlToBe(actual: string, expected: string): void {
    const normalizedActual = normalizeTestUrl(actual);
    if (normalizedActual !== expected) {
        throw new Error(`Expected URL to be "${expected}" but got "${normalizedActual}" (original: "${actual}")`);
    }
}
