/**
 * URL transformation for production deployments with path prefix
 */

export function setupUrlTransformation() {
    if (!import.meta.env.PROD) {
        return;
    }

    // Get the base path from meta tag
    const metaTag = document.querySelector('meta[name="app-base-path"]') as HTMLMetaElement;
    const basePath = metaTag?.content || '';
    
    if (!basePath) {
        return;
    }

    console.log('Setting up URL transformation with base path:', basePath);

    // Override global fetch to fix URLs
    const originalFetch = window.fetch;
    window.fetch = function(input: RequestInfo | URL, init?: RequestInit) {
        let url = input;
        
        if (typeof input === 'string') {
            // If it's a relative URL starting with /, prepend base path
            if (input.startsWith('/') && !input.startsWith(basePath)) {
                url = basePath + input;
                console.log('Transformed URL:', input, '->', url);
            }
        } else if (input instanceof Request) {
            const originalUrl = input.url;
            if (originalUrl.includes(window.location.origin) && !originalUrl.includes(basePath)) {
                const path = originalUrl.replace(window.location.origin, '');
                if (path.startsWith('/') && !path.startsWith(basePath)) {
                    const newUrl = window.location.origin + basePath + path;
                    console.log('Transformed Request URL:', originalUrl, '->', newUrl);
                    input = new Request(newUrl, input);
                }
            }
        }
        
        return originalFetch.call(this, url, init);
    };

    // Override XMLHttpRequest for legacy AJAX calls
    const originalOpen = XMLHttpRequest.prototype.open;
    // @ts-expect-error - Complex overload handling
    XMLHttpRequest.prototype.open = function(method: string, url: string | URL, ...rest: unknown[]) {
        let transformedUrl = url;
        if (typeof url === 'string' && url.startsWith('/') && !url.startsWith(basePath)) {
            transformedUrl = basePath + url;
            console.log('Transformed XHR URL:', url, '->', transformedUrl);
        }
        
        // @ts-expect-error - Complex overload handling
        return originalOpen.call(this, method, transformedUrl, ...rest);
    };
}